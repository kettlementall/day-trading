<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\MarketHoliday;
use App\Models\UserPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinController extends Controller
{
    /**
     * 回傳目前使用者在指定日期/模式下已釘選的 candidate id 清單
     *
     * intraday：date = trade_date；overnight：date = 建倉日，translate 為 trade_date 後查詢
     */
    public function index(Request $request): JsonResponse
    {
        $date = $request->get('date');
        $mode = $request->get('mode', 'intraday');

        $tradeDate = $mode === 'overnight'
            ? MarketHoliday::nextTradingDay($date)
            : $date;

        $ids = UserPin::where('user_id', $request->user()->id)
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $tradeDate)->where('mode', $mode))
            ->pluck('candidate_id');

        return response()->json($ids);
    }

    /**
     * 釘選
     */
    public function store(Request $request, Candidate $candidate): JsonResponse
    {
        UserPin::firstOrCreate([
            'user_id'      => $request->user()->id,
            'candidate_id' => $candidate->id,
        ]);

        return response()->json(['pinned' => true]);
    }

    /**
     * 取消釘選
     */
    public function destroy(Request $request, Candidate $candidate): JsonResponse
    {
        UserPin::where('user_id', $request->user()->id)
            ->where('candidate_id', $candidate->id)
            ->delete();

        return response()->json(['pinned' => false]);
    }
}
