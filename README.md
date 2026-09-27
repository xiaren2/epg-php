# epg-php
将xml转换成diyp使用的节目单格式

将2份文件放置php环境同一路径，xxx/epg.php?ch=CCTV5+&date=2026-09-29  ,缓存2小时，channel_map.txt自定义频道名称和指定epg来源

php部署在国内环境，将epg.php里xml来源换成gitee的 ,参考https://github.com/taksssss/tv/

diyp epg格式如下

    "date": "20260929",
    "channel_name": "CCTV5+",
    "source": "https:\/\/raw.githubusercontent.com\/taksssss\/tv\/refs\/heads\/main\/epg\/51zmt.xml.gz",
    "epg_data": [
        {
            "title": "实况录像-2025\/2026赛季中国男子篮球职业联赛 总决赛 1",
            "start": "01:06",
            "end": "02:36"
        },
        {
            "title": "国际足球赛场-25-26赛季英超联赛第37轮 曼联-诺丁汉森林(4K)",
            "start": "02:36",
            "end": "04:00"
        },
        {
            "title": "实况录像-2025\/2026赛季斯诺克西安大奖赛 决赛",
            "start": "04:00",
            "end": "05:55"
        },
        {
            "title": "实况录像-2026年世界田联钻石联赛 西里西亚站",
            "start": "05:55",
            "end": "07:25"
        },
        {
            "title": "实况录像-2026年亚运会 游泳比赛2",
            "start": "07:25",
            "end": "08:25"
        },
        {
            "title": "2026年亚运会-赛事直播10",
            "start": "08:25",
            "end": "20:35"
        },
        {
            "title": "国际足球赛场-26-27赛季英超联赛第5轮 伯恩茅斯-利物浦(4K)",
            "start": "20:35",
            "end": "22:05"
        },
        {
            "title": "实况录像-2026年皮划艇激流回旋世界杯 总决赛2",
            "start": "22:05",
            "end": "23:06"
        },
        {
            "title": "2026年射箭世界杯总决赛-2(4K)",
            "start": "23:06",
            "end": "23:36"
        },
        {
            "title": "实况录像-2026年世界田径终极冠军赛 2",
            "start": "23:36",
            "end": "01:06"
        }
    ]
}
