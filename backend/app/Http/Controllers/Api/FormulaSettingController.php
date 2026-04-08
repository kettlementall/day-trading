<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormulaSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormulaSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = FormulaSetting::all()->keyBy('type');
        return response()->json($settings);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'config' => 'required|array',
        ]);

        $setting = FormulaSetting::where('type', $type)->firstOrFail();
        $setting->update(['config' => $validated['config']]);

        return response()->json($setting);
    }
}
