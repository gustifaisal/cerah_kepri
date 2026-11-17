<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Menambal tanggal yang bolong di climate_daily_summaries untuk satu wilayah
 * dengan data harian Open-Meteo (Forecast API, past_days).
 *
 * Data ERA5 yang sudah ada tidak pernah ditimpa. Permintaan ke API dibatasi
 * (sekali per wilayah dalam beberapa menit) supaya tidak membebani kuota.
 */
class ClimateGapFiller
{
    private const RETRY_AFTER_MINUTES = 30;   // setelah berhasil
    private const RETRY_AFTER_FAILURE_MINUTES = 3; // setelah gagal (koneksi lambat/terputus)

    /** @return int jumlah baris yang berhasil ditulis */
    public function fill(object $region, string $startDate, string $endDate): int
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $endDate = min($endDate, $today);

        $have = DB::table('climate_daily_summaries')
            ->where('region_code', $region->code)
            ->whereBetween('observed_on', [$startDate, $endDate])
            ->whereNotNull('precipitation_mm')
            ->pluck('observed_on')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->flip();

        $missing = [];
        for ($d = Carbon::parse($startDate); $d->toDateString() <= $endDate; $d->addDay()) {
            if (! $have->has($d->toDateString())) {
                $missing[] = $d->toDateString();
            }
        }
        if ($missing === []) {
            return 0;
        }

        // Cegah pemanggilan API berulang tiap kali halaman dibuka.
        if (! Cache::add("climate-gap-fill:{$region->code}", 1, now()->addMinutes(self::RETRY_AFTER_FAILURE_MINUTES))) {
            return 0;
        }

        $pastDays = min(92, Carbon::parse($missing[0])->diffInDays(Carbon::parse($today)) + 1);

        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(20)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $region->latitude,
                    'longitude' => $region->longitude,
                    'past_days' => $pastDays,
                    'forecast_days' => 1,
                    'hourly' => 'precipitation',
                    'daily' => 'precipitation_sum,temperature_2m_mean,temperature_2m_max,temperature_2m_min,weather_code,relative_humidity_2m_mean',
                    'timezone' => 'Asia/Jakarta',
                ]);
            if (! $response->successful()) {
                report(new \RuntimeException('Open-Meteo HTTP '.$response->status().' untuk '.$region->code));
                return 0;
            }
            $daily = $response->json('daily') ?? [];
            $hourly = $response->json('hourly') ?? [];
        } catch (Throwable $e) {
            report($e);
            return 0;
        }

        if (empty($daily['time']) || ! is_array($daily['time'])) {
            return 0;
        }

        // Jangan menimpa baris ERA5 yang sudah lengkap.
        $era5 = DB::table('climate_daily_summaries')
            ->where('region_code', $region->code)
            ->whereBetween('observed_on', [$startDate, $endDate])
            ->whereNotNull('precipitation_mm')
            ->where('source', 'like', '%era5%')
            ->pluck('observed_on')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->flip();

        // Curah hujan per jam tertinggi per hari (hari ini: hanya jam yang sudah lewat).
        $peaks = [];
        $nowKey = Carbon::now('Asia/Jakarta')->format('Y-m-d\TH:i');
        foreach (($hourly['time'] ?? []) as $h => $hourTime) {
            $value = $hourly['precipitation'][$h] ?? null;
            if ($value === null || $hourTime > $nowKey) {
                continue;
            }
            $day = substr($hourTime, 0, 10);
            $peaks[$day] = max($peaks[$day] ?? 0, (float) $value);
        }

        $missingSet = array_flip($missing);
        $rows = [];
        foreach ($daily['time'] as $i => $date) {
            if (! isset($missingSet[$date]) || $era5->has($date)) {
                continue;
            }
            $rain = $daily['precipitation_sum'][$i] ?? null;
            if ($rain === null) {
                continue;
            }
            $humidity = $daily['relative_humidity_2m_mean'][$i] ?? null;
            $rows[] = [
                'region_code' => $region->code,
                'observed_on' => $date,
                'precipitation_mm' => $rain,
                'temperature_mean_c' => $daily['temperature_2m_mean'][$i] ?? null,
                'temperature_max_c' => $daily['temperature_2m_max'][$i] ?? null,
                'temperature_min_c' => $daily['temperature_2m_min'][$i] ?? null,
                'weather_code' => $daily['weather_code'][$i] ?? null,
                'precipitation_max_hourly_mm' => $peaks[$date] ?? null,
                'relative_humidity_mean' => $humidity === null ? null : (int) round((float) $humidity),
                'source' => $date === $today ? 'open_meteo_today_forecast' : 'open_meteo_recent_model',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('climate_daily_summaries')->upsert($rows, ['region_code', 'observed_on'], [
            'precipitation_mm', 'temperature_mean_c', 'temperature_max_c', 'temperature_min_c',
            'weather_code', 'relative_humidity_mean', 'precipitation_max_hourly_mm', 'source', 'updated_at',
        ]);
        Cache::put("climate-gap-fill:{$region->code}", 1, now()->addMinutes(self::RETRY_AFTER_MINUTES));
        Cache::forget("climate-summary:v2:{$region->code}:30");
        Cache::forget("climate-summary:v2:{$region->code}:7");

        return count($rows);
    }
}