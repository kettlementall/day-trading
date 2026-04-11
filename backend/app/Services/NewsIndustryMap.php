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
     * 精簡為高品質來源，避免大量無關新聞
     */
    public const RSS_FEEDS = [
        // 台灣中文（主要來源）
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
            'name' => 'yahoo_tw',
            'url' => 'https://tw.stock.yahoo.com/rss?category=tw-market',
            'source' => 'yahoo',
            'category' => 'tw_stock',
        ],
        // 國際英文（只留 Reuters + CNBC）
        [
            'name' => 'reuters_markets',
            'url' => 'https://news.google.com/rss/search?q=site:reuters.com+markets&hl=en&gl=US&ceid=US:en',
            'source' => 'reuters',
            'category' => 'international',
        ],
        [
            'name' => 'cnbc_markets',
            'url' => 'https://search.cnbc.com/rs/search/combinedcms/view.xml?partnerId=wrss01&id=15839069',
            'source' => 'cnbc',
            'category' => 'international',
        ],
    ];

    /**
     * 相關性關鍵字 — 標題必須包含至少一個才會收錄
     * 分中文和英文兩組
     */
    public const RELEVANCE_KEYWORDS_ZH = [
        // 台股相關
        '台股', '台指', '加權', '上市', '上櫃', '外資', '法人', '融資', '融券',
        '漲停', '跌停', '成交量', '權值', '盤勢', '開盤', '收盤', '盤中',
        // 個股/產業
        '台積電', '聯發科', '鴻海', '半導體', '晶圓', 'AI', '伺服器', '電動車',
        'PCB', '面板', '金控', '銀行', '航運', '生技', '新藥',
        // 總經/政策
        '升息', '降息', '利率', 'CPI', 'GDP', '通膨', '非農', '央行', '聯準會',
        '關稅', '貿易戰', '制裁', '台海', '地緣',
        // 國際股市
        '美股', '道瓊', '那斯達克', '費城半導體', 'S&P', '日股', '陸股',
        '期貨', '選擇權', 'ETF',
    ];

    public const RELEVANCE_KEYWORDS_EN = [
        // Markets
        'stock market', 'stocks', 'S&P 500', 'Nasdaq', 'Dow', 'Wall Street',
        'rally', 'sell-off', 'correction', 'bear market', 'bull market',
        // Macro
        'Fed', 'Federal Reserve', 'rate cut', 'rate hike', 'interest rate',
        'inflation', 'CPI', 'GDP', 'jobs report', 'nonfarm', 'recession',
        'Treasury', 'bond yield',
        // Tech/Semi
        'TSMC', 'Nvidia', 'semiconductor', 'chip', 'AI stocks', 'tech stocks',
        'AMD', 'Intel', 'Broadcom', 'ASML',
        // Geopolitics affecting markets
        'tariff', 'trade war', 'sanction', 'Taiwan', 'China',
        // Asia
        'Asia stocks', 'Asian markets', 'Taiwan stock', 'Nikkei', 'Hang Seng',
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

    /**
     * 判斷標題是否與台股交易相關
     */
    public static function isRelevant(string $title, string $category): bool
    {
        // 台股類一律保留
        if ($category === 'tw_stock') {
            return true;
        }

        $titleLower = mb_strtolower($title);

        // 中文關鍵字
        foreach (self::RELEVANCE_KEYWORDS_ZH as $kw) {
            if (mb_stripos($titleLower, mb_strtolower($kw)) !== false) {
                return true;
            }
        }

        // 英文關鍵字
        foreach (self::RELEVANCE_KEYWORDS_EN as $kw) {
            if (mb_stripos($titleLower, mb_strtolower($kw)) !== false) {
                return true;
            }
        }

        return false;
    }
}
