<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
    }

    public function send(string $message, string $parseMode = 'Markdown'): bool
    {
        if (!$this->token || !$this->chatId) {
            Log::warning('TelegramService: BOT_TOKEN 或 CHAT_ID 未設定');
            return false;
        }

        $hostname = gethostname() ?: 'unknown';
        $message = "[{$hostname}]\n" . $message;

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => $parseMode,
                ]);

            if (!$response->successful()) {
                Log::error('Telegram send failed: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram send error: ' . $e->getMessage());
            return false;
        }
    }
}
