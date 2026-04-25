<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $defaultChatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
        $this->defaultChatId = config('services.telegram.chat_id', '');
    }

    /**
     * 廣播通知給符合角色的用戶
     * @param string $level 'signal'（所有人）或 'system'（僅 admin）
     */
    public function broadcast(string $message, string $level = 'signal', string $parseMode = 'Markdown'): void
    {
        $query = User::whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->where('telegram_enabled', true);

        if ($level === 'system') {
            $query->where('role', 'admin');
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            // fallback 到 .env 的預設 chat_id
            $this->sendTo($this->defaultChatId, $message, $parseMode);
            return;
        }

        foreach ($users as $user) {
            $this->sendTo($user->telegram_chat_id, $message, $parseMode);
        }
    }

    /**
     * 發送到單一 chat ID（底層方法）
     */
    public function sendTo(string $chatId, string $message, string $parseMode = 'Markdown'): bool
    {
        if (!$this->token || !$chatId) {
            Log::warning('TelegramService: BOT_TOKEN 或 chat_id 未設定');
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => $parseMode,
                ]);

            if (!$response->successful()) {
                Log::error("Telegram send failed (chat_id={$chatId}): " . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Telegram send error (chat_id={$chatId}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * 發送到 .env 預設 chat_id（向後相容）
     */
    public function send(string $message, string $parseMode = 'Markdown'): bool
    {
        return $this->sendTo($this->defaultChatId, $message, $parseMode);
    }
}
