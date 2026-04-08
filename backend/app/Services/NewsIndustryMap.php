<?php

namespace App\Services;

class NewsIndustryMap
{
    /**
     * 產業分類及其關鍵字
     */
    public const INDUSTRIES = [
        '半導體' => ['台積電', 'TSMC', '聯發科', 'MediaTek', 'IC設計', '晶圓', '封測', '半導體', 'semiconductor', 'chip', 'NVIDIA', '輝達', 'AMD', 'Intel', '記憶體', 'DRAM', 'HBM', 'CoWoS', 'Samsung foundry', 'wafer', 'Qualcomm', 'Broadcom', 'ASML'],
        'AI與雲端' => ['AI', '人工智慧', 'artificial intelligence', 'ChatGPT', 'Claude', '雲端', 'cloud', 'AWS', 'Azure', 'GPU', '伺服器', 'server', '資料中心', 'data center', '機器學習', 'machine learning', 'OpenAI', 'Microsoft', 'Google Cloud', 'Meta AI'],
        '電子零組件' => ['PCB', '印刷電路板', '被動元件', '散熱', '連接器', '電子零組件', 'CCL', 'ABF', 'electronic component'],
        '面板光電' => ['面板', 'LCD', 'OLED', 'LED', 'Mini LED', 'Micro LED', '光電', '友達', '群創', '富采', 'display panel', 'BOE'],
        '通訊網路' => ['5G', '6G', '通訊', '基地台', '網通', '光纖', '衛星', 'WiFi', 'telecom', 'Starlink', 'satellite', 'fiber optic'],
        '金融' => ['銀行', '金控', '壽險', '保險', '證券', '升息', '降息', '利率', '央行', 'Fed', '聯準會', 'Federal Reserve', 'interest rate', 'rate cut', 'rate hike', 'Wall Street', 'S&P 500', 'Nasdaq', 'Dow Jones', 'bond yield', 'Treasury'],
        '傳產' => ['鋼鐵', '水泥', '塑化', '紡織', '造紙', '航運', '貨櫃', '散裝', 'shipping', 'steel', 'oil price', 'crude oil', 'commodity'],
        '生技醫療' => ['生技', '新藥', '醫療', '疫苗', 'FDA', '臨床試驗', '醫材', 'biotech', 'pharmaceutical', 'drug approval', 'clinical trial'],
        '綠能車用' => ['電動車', 'EV', '特斯拉', 'Tesla', '充電', '儲能', '太陽能', '風電', '綠能', '車用', 'electric vehicle', 'battery', 'solar', 'renewable energy', 'Rivian', 'BYD'],
        '地緣政治' => ['美中', '台海', '關稅', '制裁', '貿易戰', '地緣', '戰爭', '俄烏', '中東', 'tariff', 'sanction', 'trade war', 'geopolitical', 'US-China', 'Taiwan Strait', 'Ukraine', 'Middle East'],
        '總體經濟' => ['GDP', 'CPI', '通膨', '就業', '非農', 'PMI', '消費者信心', '經濟成長', '衰退', '降息', '升息', 'inflation', 'employment', 'nonfarm payroll', 'consumer confidence', 'recession', 'economic growth', 'jobless claims'],
    ];

    /**
     * RSS 來源設定
     */
    public const RSS_FEEDS = [
        // 台灣中文
        [
            'name' => 'cnyes_tw',
            'url' => 'https://news.cnyes.com/news/cat/tw_stock/rss',
            'source' => 'cnyes',
            'category' => 'tw_stock',
        ],
        [
            'name' => 'cnyes_intl',
            'url' => 'https://news.cnyes.com/news/cat/wd_stock/rss',
            'source' => 'cnyes',
            'category' => 'international',
        ],
        [
            'name' => 'cnyes_fund',
            'url' => 'https://news.cnyes.com/news/cat/fund/rss',
            'source' => 'cnyes',
            'category' => 'macro',
        ],
        [
            'name' => 'yahoo_tw',
            'url' => 'https://tw.stock.yahoo.com/rss?category=tw-market',
            'source' => 'yahoo',
            'category' => 'tw_stock',
        ],
        [
            'name' => 'yahoo_intl',
            'url' => 'https://tw.stock.yahoo.com/rss?category=intl-market',
            'source' => 'yahoo',
            'category' => 'international',
        ],
        // 國際英文
        [
            'name' => 'reuters_markets',
            'url' => 'https://news.google.com/rss/search?q=site:reuters.com+markets&hl=en&gl=US&ceid=US:en',
            'source' => 'reuters',
            'category' => 'international',
        ],
        [
            'name' => 'bloomberg_markets',
            'url' => 'https://news.google.com/rss/search?q=site:bloomberg.com+markets+stocks&hl=en&gl=US&ceid=US:en',
            'source' => 'bloomberg',
            'category' => 'international',
        ],
        [
            'name' => 'cnbc_markets',
            'url' => 'https://search.cnbc.com/rs/search/combinedcms/view.xml?partnerId=wrss01&id=15839069',
            'source' => 'cnbc',
            'category' => 'international',
        ],
        [
            'name' => 'wsj_markets',
            'url' => 'https://feeds.a.dj.com/rss/RSSMarketsMain.xml',
            'source' => 'wsj',
            'category' => 'international',
        ],
        [
            'name' => 'marketwatch',
            'url' => 'https://feeds.content.dowjones.io/public/rss/mw_topstories',
            'source' => 'marketwatch',
            'category' => 'international',
        ],
    ];

    /**
     * 根據標題 + 摘要判斷所屬產業
     */
    public static function classify(string $text): ?string
    {
        $bestMatch = null;
        $bestCount = 0;

        foreach (self::INDUSTRIES as $industry => $keywords) {
            $count = 0;
            foreach ($keywords as $kw) {
                if (mb_stripos($text, $kw) !== false) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestMatch = $industry;
            }
        }

        return $bestCount > 0 ? $bestMatch : null;
    }
}
