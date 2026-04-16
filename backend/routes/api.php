<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\DataSyncController;
use App\Http\Controllers\Api\FormulaSettingController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ScreeningRuleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ── 公開：只有登入不需驗證 ─────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);

// ── 已登入（admin + viewer）─────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // 候選標的
    Route::get('/candidates',                       [CandidateController::class, 'index']);
    Route::get('/candidates/dates',                 [CandidateController::class, 'dates']);
    Route::get('/candidates/stats',                 [CandidateController::class, 'stats']);
    Route::get('/candidates/morning',               [CandidateController::class, 'morning']);
    Route::get('/candidates/monitors',              [CandidateController::class, 'monitors']);
    Route::get('/candidates/{candidate}',           [CandidateController::class, 'show']);
    Route::get('/candidates/{candidate}/snapshots', [CandidateController::class, 'snapshots']);
    Route::get('/candidates/{candidate}/monitor',   [CandidateController::class, 'monitor']);

    // 股票（viewer 可點卡片看股票詳情）
    Route::get('/stocks',                [StockController::class, 'index']);
    Route::get('/stocks/{stock}',        [StockController::class, 'show']);
    Route::get('/stocks/{stock}/kline',  [StockController::class, 'kline']);
    Route::get('/stocks/{stock}/detail', [StockController::class, 'detail']);

    // 回測 SSE 串流（EventSource 無法送 header，改由 BacktestController 從 query string 讀 token）
    Route::get('/backtest/daily-review',       [BacktestController::class, 'dailyReview']);
    Route::get('/backtest/analyze-tip',        [BacktestController::class, 'analyzeTip']);
    Route::get('/backtest/daily-review-show',  [BacktestController::class, 'dailyReviewShow']);
    Route::get('/backtest/daily-review-dates', [BacktestController::class, 'dailyReviewDates']);
    Route::get('/backtest/rounds',             [BacktestController::class, 'rounds']);
    Route::get('/backtest/optimize-validated', [BacktestController::class, 'optimizeValidated']);

    // ── Admin only ───────────────────────────────────────────────────────────
    Route::middleware('admin')->group(function () {

        // 用戶管理
        Route::get('/users',              [UserController::class, 'index']);
        Route::post('/users',             [UserController::class, 'store']);
        Route::put('/users/{user}',       [UserController::class, 'update']);
        Route::delete('/users/{user}',    [UserController::class, 'destroy']);

        // 手動同步
        Route::post('/data-sync', [DataSyncController::class, 'sync']);

        // 消息面（觸發）
        Route::get('/news/dashboard',    [NewsController::class, 'dashboard']);
        Route::post('/news/fetch',       [NewsController::class, 'fetch']);
        Route::get('/news/fetch-status', [NewsController::class, 'fetchStatus']);

        // 公式設定
        Route::get('/formula-settings',           [FormulaSettingController::class, 'index']);
        Route::put('/formula-settings/{type}',    [FormulaSettingController::class, 'update']);

        // 回測（觸發）
        Route::post('/backtest/optimize',             [BacktestController::class, 'optimize']);
        Route::post('/backtest/rounds/{round}/apply', [BacktestController::class, 'apply']);

        // 篩選規則
        Route::apiResource('screening-rules', ScreeningRuleController::class);

        // 系統規格
        Route::get('/spec', function () {
            $path = base_path('SPEC.md');
            if (! file_exists($path)) {
                return response()->json(['content' => ''], 404);
            }
            return response()->json(['content' => file_get_contents($path)]);
        });
    });
});
