<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineNotifier
{
    /**
     * Send a LINE Notify message. Falls back to logging when no token is
     * configured (local/testing), so the queued job never fails for that reason.
     */
    public function send(string $message): bool
    {
        $token = config('services.line_notify.token');

        if (! $token) {
            Log::info('[LineNotify:skipped, no token configured] '.$message);

            return true;
        }

        $response = Http::asForm()
            ->withToken($token)
            ->post('https://notify-api.line.me/api/notify', [
                'message' => $message,
            ]);

        if (! $response->successful()) {
            Log::warning('[LineNotify:failed] '.$message, ['status' => $response->status()]);
        }

        return $response->successful();
    }
}
