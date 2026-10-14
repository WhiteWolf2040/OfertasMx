<?php

return [
    'chain' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('SCRAPER_CHAIN', 'http,browser'))
    ))),
    'cache_seconds' => 3600,
    'api' => [
        'url' => env('SCRAPER_API_URL'),
        'key' => env('SCRAPER_API_KEY'),
        'url_parameter' => env('SCRAPER_API_URL_PARAMETER', 'url'),
    ],
    'browser' => [
        'node_binary' => env('SCRAPER_NODE_BINARY', 'node'),
        'script' => base_path('scripts/scrape-browser.cjs'),
        'headless' => env('SCRAPER_BROWSER_HEADLESS', false),
        'channel' => env('SCRAPER_BROWSER_CHANNEL'),
        'concurrency' => 1,
    ],
    'domains' => [
        // These selectors are store-specific and may change; adjust them when testing those stores.
        'amazon.com' => [
            'title' => '#productTitle',
            'price' => '.a-price .a-offscreen',
            'image' => '#landingImage',
        ],
        'amazon.com.mx' => [
            'title' => '#productTitle',
            'price' => '.a-price .a-offscreen',
            'image' => '#landingImage',
        ],
        'mercadolibre.com' => [
            'title' => 'h1.ui-pdp-title',
            'price' => '.andes-money-amount__fraction',
            'image' => '.ui-pdp-gallery__figure img',
        ],
        'mercadolibre.com.mx' => [
            'title' => 'h1.ui-pdp-title',
            'price' => '.andes-money-amount__fraction',
            'image' => '.ui-pdp-gallery__figure img',
        ],
        'temu.com' => [
            'title' => null,
            'price' => null,
            'image' => null,
        ],
    ],
];
