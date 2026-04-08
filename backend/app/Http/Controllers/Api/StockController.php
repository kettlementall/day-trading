<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyQuote;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Stock::query();

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('day_trading_only')) {
            $query->where('is_day_trading', true);
        }

        $stocks = $query->orderBy('symbol')->paginate(50);

        return response()->json($stocks);
    }

    public function show(Stock $stock): JsonResponse
    {
        return response()->json($stock);
    }

    public function kline(Stock $stock, Request $request): JsonResponse
    {
        $days = $request->get('days', 60);

        $quotes = $stock->dailyQuotes()
            ->orderByDesc('date')
            ->limit($days)
            ->get()
            ->reverse()
            ->values();

        return response()->json($quotes);
    }

    public function detail(Stock $stock, Request $request): JsonResponse
    {
        $days = $request->get('days', 5);

        $stock->load([
            'dailyQuotes' => fn ($q) => $q->orderByDesc('date')->limit($days),
            'institutionalTrades' => fn ($q) => $q->orderByDesc('date')->limit($days),
            'marginTrades' => fn ($q) => $q->orderByDesc('date')->limit($days),
        ]);

        return response()->json($stock);
    }
}
