<?php

use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\DataSyncController;
use App\Http\Controllers\Api\FormulaSettingController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ScreeningRuleController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

// 候選標的
Route::get('/candidates', [CandidateController::class, 'index']);
Route::get('/candidates/dates', [CandidateController::class, 'dates']);
Route::get('/candidates/stats', [CandidateController::class, 'stats']);
Route::get('/candidates/morning', [CandidateController::class, 'morning']);
Route::get('/candidates/{candidate}', [CandidateController::class, 'show']);

// 股票
Route::get('/stocks', [StockController::class, 'index']);
Route::get('/stocks/{stock}', [StockController::class, 'show']);
Route::get('/stocks/{stock}/kline', [StockController::class, 'kline']);
Route::get('/stocks/{stock}/detail', [StockController::class, 'detail']);

// 篩選規則
Route::apiResource('screening-rules', ScreeningRuleController::class);

// 公式設定
Route::get('/formula-settings', [FormulaSettingController::class, 'index']);
Route::put('/formula-settings/{type}', [FormulaSettingController::class, 'update']);

// 手動同步
Route::post('/data-sync', [DataSyncController::class, 'sync']);

// 消息面
Route::get('/news/dashboard', [NewsController::class, 'dashboard']);
Route::post('/news/fetch', [NewsController::class, 'fetch']);
Route::get('/news/fetch-status', [NewsController::class, 'fetchStatus']);

// 回測系統
Route::get('/backtest/rounds', [BacktestController::class, 'rounds']);
Route::post('/backtest/optimize', [BacktestController::class, 'optimize']);
Route::get('/backtest/optimize-validated', [BacktestController::class, 'optimizeValidated']);
Route::post('/backtest/rounds/{round}/apply', [BacktestController::class, 'apply']);

// 系統規格
Route::get('/spec', function () {
    $path = base_path('SPEC.md');
    if (!file_exists($path)) {
        return response()->json(['content' => ''], 404);
    }
    return response()->json(['content' => file_get_contents($path)]);
});
