<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DataSyncController extends Controller
{
    /**
     * 手動觸發同步股市資料
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'tasks' => 'required|array',
            'tasks.*' => 'in:daily,institutional,margin,screen,results',
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $tasks = $validated['tasks'];
        $dateArg = str_replace('-', '', $date);

        $results = [];

        $commandMap = [
            'daily' => ['command' => 'stock:fetch-daily', 'arg' => $dateArg, 'label' => '日行情'],
            'institutional' => ['command' => 'stock:fetch-institutional', 'arg' => $dateArg, 'label' => '法人買賣'],
            'margin' => ['command' => 'stock:fetch-margin', 'arg' => $dateArg, 'label' => '融資融券'],
            'screen' => ['command' => 'stock:screen-candidates', 'arg' => $date, 'label' => '選股篩選'],
            'results' => ['command' => 'stock:update-results', 'arg' => $date, 'label' => '盤後結果'],
        ];

        foreach ($tasks as $task) {
            $cmd = $commandMap[$task];
            try {
                $exitCode = Artisan::call($cmd['command'], ['date' => $cmd['arg']]);
                $output = trim(Artisan::output());
                $results[] = [
                    'task' => $task,
                    'label' => $cmd['label'],
                    'success' => $exitCode === 0,
                    'message' => $output ?: '完成',
                ];
            } catch (\Throwable $e) {
                Log::error("DataSync {$task} failed: " . $e->getMessage());
                $results[] = [
                    'task' => $task,
                    'label' => $cmd['label'],
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $allSuccess = collect($results)->every('success');

        return response()->json([
            'success' => $allSuccess,
            'date' => $date,
            'results' => $results,
        ]);
    }
}
