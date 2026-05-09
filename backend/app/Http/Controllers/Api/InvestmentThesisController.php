<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentThesis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentThesisController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            InvestmentThesis::orderByRaw("FIELD(status, 'active', 'inactive', 'disabled')")
                ->orderByDesc('confidence_score')
                ->get()
        );
    }

    public function update(Request $request, InvestmentThesis $investmentThesis): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:120',
            'description' => 'sometimes|string',
            'industry_chain' => 'sometimes|nullable|array',
            'beneficiary_industries' => 'sometimes|nullable|array',
            'beneficiary_keywords' => 'sometimes|nullable|array',
            'evidence_summary' => 'sometimes|nullable|string',
            'risk_factors' => 'sometimes|nullable|array',
            'sentiment_divergence' => 'sometimes|nullable|string|max:40',
            'confidence_score' => 'sometimes|integer|min:0|max:100',
            'status' => 'sometimes|in:active,inactive,disabled',
        ]);

        $investmentThesis->update($validated);

        return response()->json($investmentThesis);
    }

    public function disable(InvestmentThesis $investmentThesis): JsonResponse
    {
        $investmentThesis->update(['status' => InvestmentThesis::STATUS_DISABLED]);
        return response()->json($investmentThesis);
    }

    public function enable(InvestmentThesis $investmentThesis): JsonResponse
    {
        $investmentThesis->update(['status' => InvestmentThesis::STATUS_ACTIVE]);
        return response()->json($investmentThesis);
    }
}
