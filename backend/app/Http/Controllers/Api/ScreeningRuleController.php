<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScreeningRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScreeningRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = ScreeningRule::orderBy('sort_order')->get();
        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'conditions' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $rule = ScreeningRule::create($validated);

        return response()->json($rule, 201);
    }

    public function update(Request $request, ScreeningRule $screeningRule): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'conditions' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $screeningRule->update($validated);

        return response()->json($screeningRule);
    }

    public function destroy(ScreeningRule $screeningRule): JsonResponse
    {
        $screeningRule->delete();

        return response()->json(null, 204);
    }
}
