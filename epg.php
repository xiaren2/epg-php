<?php
header('Content-Type: application/json; charset=utf-8');

/* ================= 配置 ================= */
// 多个 EPG 源，按顺序尝试。注意：URL 不要带反引号 `
$epgSources = [
    'https://raw.githubusercontent.com/taksssss/tv/refs/heads/main/epg/112114.xml.gz',
    'https://raw.githubusercontent.com/taksssss/tv/refs/heads/main/epg/51zmt.xml.gz',
    'https://raw.githubusercontent.com/taksssss/tv/refs/heads/main/epg/erw.xml.gz',
    'https://raw.githubusercontent.com/taksssss/tv/refs/heads/main/epg/epgpw_cn.xml.gz',
];

$cacheTtl = 6 * 3600;
$baseDir  = __DIR__;
$cacheDir = $baseDir . '/caches';
$mapFile  = $baseDir . '/channel_map.txt'; // 频道名映射文件（可选）

/* ================= 参数 ================= */
// 从原始 QUERY_STRING 解析 ch，避免 + 被 $_GET 解析成空格（如 cctv5+ → cctv5 ）
function getRawQueryParam($name) {
    if (!isset($_SERVER['QUERY_STRING'])) return '';
    foreach (explode('&', $_SERVER['QUERY_STRING']) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2 && $parts[0] === $name) {
            return rawurldecode($parts[1]); // rawurldecode 不把 + 当空格
        }
    }
    return '';
}

$channelName = getRawQueryParam('ch');
$date        = $_GET['date'] ?? date('Y-m-d');
$debug       = isset($_GET['debug']) && $_GET['debug'] === '1';

if ($channelName === '') {
    echo json_encode(['error' => 'missing ch'], JSON_UNESCAPED_UNICODE);
    exit;
}
$targetDay = str_replace('-', '', $date);

// 加载频道名映射
$channelMap = loadChannelMap($mapFile);
// 如果映射表里有，用映射后的名称去 EPG 源里匹配；否则用原名
$lookupName = $channelName;
$forcedSrc  = -1; // 指定只用哪个源，-1=自动轮询
if (isset($channelMap[$channelName])) {
    $lookupName = $channelMap[$channelName]['name'];
    $forcedSrc  = $channelMap[$channelName]['src'];
}

/* ================= 工具函数 ================= */

function cleanName($name) {
    $name = strtoupper(trim($name));
    // 只处理基础格式，不删除"频道"等词汇，防止误伤
    return str_replace([' ', '-', '_', 'HD', '4K', '高清', '超清'], '', $name);
}

/**
 * 读取频道名映射文件 channel_map.txt
 * 格式（# 开头为注释）：
 *   别名1,别名2,...=源里的频道名[|源索引]
 *   黄河=黄河电视台
 *   cctv5+,体育赛事=CCTV5+|2
 * 说明：
 *   - 左边可写多个别名，逗号分隔
 *   - 右边"频道名"后面可选 |源索引，指定只用哪个 EPG 源（从0开始）
 *     源索引对应 $epgSources 数组的下标，不写则自动轮询所有源
 */
function loadChannelMap($mapFile) {
    $map = [];
    if (!file_exists($mapFile)) return $map;
    $lines = @file($mapFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $map;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        // 右边解析：频道名|源索引
        $right = trim($parts[1]);
        $srcIdx = -1;
        $pipePos = strrpos($right, '|');
        if ($pipePos !== false) {
            $maybeIdx = trim(substr($right, $pipePos + 1));
            if (ctype_digit($maybeIdx)) {
                $srcIdx = (int)$maybeIdx;
                $right = trim(substr($right, 0, $pipePos));
            }
        }
        if ($right === '') continue;

        // 左边多个别名
        $aliases = explode(',', $parts[0]);
        foreach ($aliases as $alias) {
            $from = trim($alias);
            if ($from !== '') {
                $map[$from] = ['name' => $right, 'src' => $srcIdx];
            }
        }
    }
    return $map;
}

/**
 * 下载远程 EPG 内容并解码
 * 自动识别 gzip / 纯 XML，无需关心 URL 后缀
 * 返回: ['ok' => bool, 'content' => string|false, 'error' => string, 'http_code' => int, 'size' => int, 'content_type' => string, 'encoding' => string]
 */
function downloadEpg($url) {
    $result = [
        'ok'           => false,
        'content'      => false,
        'error'        => '',
        'http_code'    => 0,
        'size'         => 0,
        'content_type' => '',
        'encoding'     => '',
    ];

    $raw = null;

    if (!function_exists('curl_init')) {
        // curl 不可用时回退到 file_get_contents (需 allow_url_fopen=On)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $result['error'] = 'file_get_contents failed (allow_url_fopen maybe Off): ' . (error_get_last()['message'] ?? 'unknown');
            return $result;
        }
        $result['size'] = strlen($raw);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            // 不主动要求 gzip，让服务器返回原始内容；我们自己判断是否解压
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $raw = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $result['http_code']    = $info['http_code'] ?? 0;
        $result['size']         = $info['size_download'] ?? 0;
        $result['content_type'] = $info['content_type'] ?? '';

        if ($raw === false) {
            $result['error'] = "curl error: {$error}";
            return $result;
        }
        if ($result['http_code'] !== 200) {
            $result['error'] = "HTTP {$result['http_code']}, content_type={$result['content_type']}, size={$result['size']}";
            return $result;
        }
    }

    if ($raw === null || $raw === '') {
        $result['error'] = 'empty response';
        return $result;
    }

    // 自动识别：gzip 魔数 0x1f 0x8b
    $isGzip = (strlen($raw) >= 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b);

    if ($isGzip) {
        $result['encoding'] = 'gzip';
        $xmlContent = @gzdecode($raw);
        if ($xmlContent === false) {
            $result['error'] = "gzdecode failed, size={$result['size']}, content_type={$result['content_type']}, first bytes=" . bin2hex(substr($raw, 0, 16));
            return $result;
        }
        $result['ok'] = true;
        $result['content'] = $xmlContent;
    } else {
        $result['encoding'] = 'plain';
        $result['ok'] = true;
        $result['content'] = $raw;
    }

    return $result;
}

/* ================= 准备工作 ================= */
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$epgData = [];
$usedSrc = "";
$downloadErrors = [];  // 收集每个源的下载失败原因
$debugInfo = [];       // 调试信息

/* ================= 遍历源 ================= */
foreach ($epgSources as $idx => $url) {

    // 如果指定了源，只处理对应的源
    if ($forcedSrc >= 0 && $idx !== $forcedSrc) continue;

    $cacheXml = "{$cacheDir}/src_{$idx}.xml";
    $lockFile = "{$cacheDir}/src_{$idx}.lock";

    // debug 模式下强制重新下载
    $needDownload = $debug || !file_exists($cacheXml) || (time() - filemtime($cacheXml) >= $cacheTtl);

    if ($needDownload) {
        if ($debug && file_exists($cacheXml)) {
            @unlink($cacheXml); // debug 模式删除旧缓存强制重下
        }
        $fp = fopen($lockFile, 'c');
        if (!$fp) {
            $downloadErrors[] = ['src' => $url, 'error' => "fopen({$lockFile}) failed"];
            continue;
        }
        if (!flock($fp, LOCK_EX)) {
            $downloadErrors[] = ['src' => $url, 'error' => "flock failed on {$lockFile}"];
            fclose($fp);
            continue;
        }
        // 二次检查：拿到锁后再判断一次，避免并发重复下载
        $needDownload2 = $debug || !file_exists($cacheXml) || (time() - filemtime($cacheXml) >= $cacheTtl);
        if ($needDownload2) {
            $dl = downloadEpg($url);
            if ($dl['ok']) {
                $written = file_put_contents($cacheXml, $dl['content']);
                if ($debug) {
                    $debugInfo[] = "src_{$idx}: downloaded OK ({$dl['encoding']}), wrote {$written} bytes to {$cacheXml}";
                }
            } else {
                $downloadErrors[] = [
                    'src'      => $url,
                    'error'    => $dl['error'],
                    'http'     => $dl['http_code'],
                    'size'     => $dl['size'],
                    'type'     => $dl['content_type'],
                    'encoding' => $dl['encoding'],
                ];
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    if (!file_exists($cacheXml)) continue;

    $xmlString = @file_get_contents($cacheXml);
    if ($xmlString === false) continue;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) continue;

    /* ---------- 查找频道 ID (梯度匹配逻辑) ---------- */
    $searchKey = cleanName($lookupName); // 用映射后的名称匹配
    $channelId = null;
    $fuzzyMatchId = null;

    foreach ($xml->channel as $ch) {
        foreach ($ch->{'display-name'} as $dn) {
            $currentClean = cleanName((string)$dn);

            // 1. 绝对匹配：去掉干扰符后完全相等
            if ($currentClean === $searchKey) {
                $channelId = (string)$ch['id'];
                break 2; // 直接确定，跳出两层
            }

            // 2. 模糊备选：包含关系但非前缀（避免 cctv5 匹配到 cctv5+、cctv1 匹配到 cctv13）
            if ($fuzzyMatchId === null) {
                $posA = strpos($currentClean, $searchKey);
                $posB = strpos($searchKey, $currentClean);
                if ($posA !== false || $posB !== false) {
                    // 如果一个是另一个的前缀（短的是长的的开头），则不匹配
                    $isPrefix = ($posA === 0) || ($posB === 0);
                    if (!$isPrefix) {
                        $fuzzyMatchId = (string)$ch['id'];
                    }
                }
            }
        }
    }

    // 如果遍历完当前 XML 没找到精确匹配，但有模糊匹配，则使用模糊匹配
    if ($channelId === null && $fuzzyMatchId !== null) {
        $channelId = $fuzzyMatchId;
    }

    if ($debug) {
        $matchType = ($channelId === $fuzzyMatchId && $channelId !== null) ? 'fuzzy' : (($channelId !== null) ? 'exact' : 'none');
        $mapped = ($lookupName !== $channelName) ? " (mapped from '{$channelName}')" : '';
        $debugInfo[] = "src_{$idx}: searchKey={$searchKey}{$mapped}, channelId=" . ($channelId ?? 'null') . ", matchType={$matchType}";
    }

    if ($channelId === null) continue;

    /* ---------- 提取节目 ---------- */
    $tempList = [];
    $chProgCount = 0;
    $chProgDates = [];
    foreach ($xml->programme as $p) {
        if ((string)$p['channel'] !== $channelId) continue;
        $chProgCount++;
        $startRaw = (string)$p['start'];
        $progDate = substr($startRaw, 0, 8);
        if (!isset($chProgDates[$progDate])) $chProgDates[$progDate] = 0;
        $chProgDates[$progDate]++;

        if ($progDate !== $targetDay) continue;

        $tempList[] = [
            'title' => trim((string)$p->title),
            'start' => substr($startRaw, 8, 2) . ':' . substr($startRaw, 10, 2),
            'end'   => substr((string)$p['stop'], 8, 2) . ':' . substr((string)$p['stop'], 10, 2)
        ];
    }
    if ($debug) {
        $debugInfo[] = "src_{$idx}: channelId={$channelId}, total programmes={$chProgCount}, dates=" . json_encode($chProgDates, JSON_UNESCAPED_UNICODE);
    }

    if (!empty($tempList)) {
        $epgData = $tempList;
        $usedSrc = $url;
        break;
    }
}

/* ================= 输出 ================= */
if (empty($epgData)) {
    $resp = [
        'date' => $date,
        'channel_name' => $channelName,
        'error' => 'No EPG data found',
    ];
    if (!empty($downloadErrors)) {
        $resp['download_errors'] = $downloadErrors;
    }
    if ($debug && !empty($debugInfo)) {
        $resp['debug'] = $debugInfo;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

usort($epgData, function($a, $b) {
    return strcmp($a['start'], $b['start']);
});

$resp = [
    'date' => $date,
    'channel_name' => $channelName,
    'source' => $usedSrc,
    'epg_data' => $epgData,
];
if ($debug) {
    if (!empty($downloadErrors)) $resp['download_errors'] = $downloadErrors;
    if (!empty($debugInfo)) $resp['debug'] = $debugInfo;
}
echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
