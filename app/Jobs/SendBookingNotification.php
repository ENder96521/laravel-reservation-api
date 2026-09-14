<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\NotificationLog;
use App\Services\Notifications\LineNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBookingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Booking $booking,
        public string $event,
    ) {}

    public function handle(LineNotifier $notifier): void
    {
        $sent = $notifier->send($this->message());

        NotificationLog::create([
            'booking_id' => $this->booking->id,
            'channel' => 'line',
            'event' => $this->event,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => now(),
        ]);
    }

    private function message(): string
    {
        return match ($this->event) {
            'booking_confirmed' => "您的預約已確認（Booking #{$this->booking->id}）",
            'booking_pending_payment' => "您的預約已建立，待付款（Booking #{$this->booking->id}）。付款連結：{$this->booking->payment_url}",
            'booking_payment_confirmed' => "您的付款已確認，預約成立（Booking #{$this->booking->id}）",
            'booking_payment_failed' => "您的付款失敗，請重新嘗試付款（Booking #{$this->booking->id}）",
            'booking_cancelled' => "您的預約已取消（Booking #{$this->booking->id}）",
            default => "Booking #{$this->booking->id}: {$this->event}",
        };
    }
}
