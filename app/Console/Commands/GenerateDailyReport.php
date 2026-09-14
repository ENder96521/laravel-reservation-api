<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class GenerateDailyReport extends Command
{
    /**
     * @var string
     */
    protected $signature = 'report:daily {date? : The date to report on (Y-m-d), defaults to yesterday}';

    /**
     * @var string
     */
    protected $description = '統計指定日期的預約數並產生 CSV 結算報表';

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : today()->subDay();

        $bookings = Booking::whereDate('created_at', $date)
            ->with('timeSlot.resource')
            ->get()
            ->groupBy('timeSlot.resource.id');

        $rows = $bookings->map(function ($resourceBookings) {
            $resource = $resourceBookings->first()->timeSlot->resource;

            return [
                'resource_id' => $resource->id,
                'resource_name' => $resource->name,
                'total_bookings' => $resourceBookings->count(),
                'confirmed' => $resourceBookings->where('status', 'confirmed')->count(),
                'pending' => $resourceBookings->where('status', 'pending')->count(),
                'cancelled' => $resourceBookings->where('status', 'cancelled')->count(),
                'payment_failed' => $resourceBookings->where('status', 'payment_failed')->count(),
                'revenue_paid' => $resourceBookings->where('payment_status', 'paid')->count() * $resource->price,
            ];
        })->values();

        $path = $this->writeCsv($date, $rows);

        $this->info("Daily report for {$date->toDateString()} written to {$path} ({$rows->count()} resource(s), {$bookings->flatten(1)->count()} booking(s)).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function writeCsv(Carbon $date, $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, ['resource_id', 'resource_name', 'total_bookings', 'confirmed', 'pending', 'cancelled', 'payment_failed', 'revenue_paid']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $path = 'reports/daily-'.$date->toDateString().'.csv';
        Storage::disk('local')->put($path, $csv);

        return $path;
    }
}
