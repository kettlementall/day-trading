<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // TWSE/TPEX 會封鎖 Guzzle 預設 User-Agent，需偽裝為瀏覽器
        Http::globalRequestMiddleware(function ($request) {
            return $request->withHeader(
                'User-Agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            );
        });
    }
}
