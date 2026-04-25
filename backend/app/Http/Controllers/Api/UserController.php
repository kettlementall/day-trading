<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::orderBy('id')->get()->map(fn ($u) => [
                'id'         => $u->id,
                'user_id'    => $u->user_id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'telegram_chat_id'         => $u->telegram_chat_id,
                'telegram_enabled'         => $u->telegram_enabled ?? false,
                'intraday_monitor_enabled' => $u->intraday_monitor_enabled ?? true,
                'created_at' => $u->created_at,
            ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => 'required|string|max:50|unique:users,user_id',
            'name'     => 'required|string|max:100',
            'email'    => 'nullable|email|unique:users',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin,viewer',
            'telegram_chat_id'         => 'nullable|string|max:50|unique:users,telegram_chat_id',
            'telegram_enabled'         => 'sometimes|boolean',
            'intraday_monitor_enabled' => 'sometimes|boolean',
        ]);

        $user = User::create($validated);

        return response()->json([
            'id'      => $user->id,
            'user_id' => $user->user_id,
            'name'    => $user->name,
            'email'   => $user->email,
            'role'    => $user->role,
            'telegram_chat_id'         => $user->telegram_chat_id,
            'telegram_enabled'         => $user->telegram_enabled,
            'intraday_monitor_enabled' => $user->intraday_monitor_enabled,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => 'sometimes|string|max:50|unique:users,user_id,' . $user->id,
            'name'     => 'sometimes|string|max:100',
            'email'    => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role'     => 'sometimes|in:admin,viewer',
            'telegram_chat_id'         => 'sometimes|nullable|string|max:50|unique:users,telegram_chat_id,' . $user->id,
            'telegram_enabled'         => 'sometimes|boolean',
            'intraday_monitor_enabled' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'id'      => $user->id,
            'user_id' => $user->user_id,
            'name'    => $user->name,
            'email'   => $user->email,
            'role'    => $user->role,
            'telegram_chat_id'         => $user->telegram_chat_id,
            'telegram_enabled'         => $user->telegram_enabled,
            'intraday_monitor_enabled' => $user->intraday_monitor_enabled,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => '不能刪除自己'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    public function testTelegram(User $user): JsonResponse
    {
        if (!$user->telegram_chat_id) {
            return response()->json(['message' => '該用戶未設定 Telegram Chat ID'], 422);
        }

        $role = $user->role === 'admin' ? '管理員' : '觀看者';
        $ok = app(TelegramService::class)->sendTo(
            $user->telegram_chat_id,
            "🧪 *Telegram 通知測試*\n\n👤 {$user->name}（{$role}）\n✅ 連線正常，此 Chat ID 可接收通知"
        );

        return $ok
            ? response()->json(['message' => '測試訊息已發送'])
            : response()->json(['message' => '發送失敗，請確認 Chat ID 是否正確'], 422);
    }
}
