<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Isi kolom AQI/PM2.5 yang kosong dalam climate_daily_summaries.
 * Tidak mengubah hujan, suhu, kelembapan, cuaca, source, atau AQI/PM2.5 yang sudah ada.
 * Sumber: Open-Meteo Air Quality API (CAMS, berbasis model).
 */
class SyncHistoricalAirQuality extends Command
{
    protected $signature = 'climate:sync-aqi
        {--region= : Kode wilayah untuk pengujian, contoh 21.72.04.1001}
        {--days=30 : Rentang hari termasuk hari ini (1-92)}';

    protected $description = 'Lengkapi AQI dan PM2.5 historis yang kosong tanpa menimpa data cuaca';

    public function handle(): int
    {
        $days = max(1, min(92, (int) $this->option('days')));
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $start = Carbon::today('Asia/Jakarta')->subDays($days - 1)->toDateString();
        $nowLocal = Carbon::now('Asia/Jakarta')->format('Y-m-d\TH:i');
        $scope = trim((string) $this->option('region'));

        $query = DB::table('regions')
            ->where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('code');
        if ($scope !== '') {
            $query->where('code', $scope);
        }
        $regions = $query->get(['code', 'name', 'latitude', 'longitude']);
        if ($regions->isEmpty()) {
            $this->error('Tidak ada wilayah aktif dengan koordinat yang cocok.');
            return self::FAILURE;
        }

        $this->info("Melengkapi AQI/PM2.5: {$start} s.d. {$today}; {$regions->count()} wilayah.");
        $this->warn('Sumber AQI adalah estimasi model CAMS, bukan sensor kualitas udara langsung.');

        $updatedRows = 0;
        $filledAqi = 0;
        $filledPm25 = 0;
        $failedRegions = 0;
        $stoppedByQuota = false;

        foreach ($regions->chunk(5) as $batchIndex => $batch) {
            $existing = DB::table('climate_daily_summaries')
                ->whereIn('region_code', $batch->pluck('code')->all())
                ->whereBetween('observed_on', [$start, $today])
                ->get(['region_code', 'observed_on', 'us_aqi_mean', 'pm2_5_mean_ug_m3'])
                ->keyBy(fn ($r) => $r->region_code.'|'.substr((string) $r->observed_on, 0, 10));

            // Tidak perlu memanggil API jika semua nilai AQI dan PM2.5 sudah tersedia.
            $hasMissing = $existing->contains(fn ($r) => $r->us_aqi_mean === null || $r->pm2_5_mean_ug_m3 === null);
            if (! $hasMissing) {
                $this->line('Batch '.($batchIndex + 1).': tidak ada kolom kosong pada baris yang tersedia.');
                continue;
            }

            try {
                $response = Http::acceptJson()
                    ->connectTimeout(12)
                    ->timeout(90)
                    ->get('https://air-quality-api.open-meteo.com/v1/air-quality', [
                        'latitude' => $batch->pluck('latitude')->implode(','),
                        'longitude' => $batch->pluck('longitude')->implode(','),
                        'hourly' => 'us_aqi,pm2_5',
                        'start_date' => $start,
                        'end_date' => $today,
                        'timezone' => 'Asia/Jakarta',
                    ]);

                if (! $response->successful()) {
                    $msg = mb_substr((string) $response->body(), 0, 180);
                    throw new \RuntimeException('HTTP '.$response->status().' dari Open-Meteo: '.$msg, $response->status());
                }
                $payload = $response->json();
                $locations = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];
                if (count($locations) !== $batch->count()) {
                    throw new \RuntimeException('Jumlah lokasi API berbeda dengan permintaan.');
                }

                $rows = [];
                foreach ($batch->values() as $i => $region) {
                    $hourly = $locations[$i]['hourly'] ?? [];
                    $times = $hourly['time'] ?? [];
                    if (! is_array($times)) {
                        continue;
                    }
                    $groups = [];
                    foreach ($times as $index => $time) {
                        if (! is_string($time) || $time > $nowLocal) {
                            continue; // Abaikan jam prakiraan di masa depan.
                        }
                        $date = substr($time, 0, 10);
                        if ($date < $start || $date > $today) {
                            continue;
                        }
                        foreach (['us_aqi' => 'aqi', 'pm2_5' => 'pm25'] as $apiKey => $field) {
                            $value = $hourly[$apiKey][$index] ?? null;
                            if ($value !== null && is_numeric($value)) {
                                $groups[$date][$field][] = (float) $value;
                            }
                        }
                    }

                    foreach ($groups as $date => $group) {
                        $key = $region->code.'|'.$date;
                        $prior = $existing->get($key);
                        if (! $prior) {
                            // Jangan membuat baris iklim tanpa data cuaca di tanggal ini.
                            continue;
                        }
                        $aqiCount = count($group['aqi'] ?? []);
                        $pm25Count = count($group['pm25'] ?? []);
                        // Minimal 18 jam untuk hari historis. Hari ini boleh parsial.
                        $minHours = ($date === $today) ? 1 : 18;
                        $newAqi = $prior->us_aqi_mean;
                        $newPm25 = $prior->pm2_5_mean_ug_m3;
                        if ($newAqi === null && $aqiCount >= $minHours) {
                            $newAqi = round(array_sum($group['aqi']) / $aqiCount, 2);
                            $filledAqi++;
                        }
                        if ($newPm25 === null && $pm25Count >= $minHours) {
                            $newPm25 = round(array_sum($group['pm25']) / $pm25Count, 2);
                            $filledPm25++;
                        }
                        if ($newAqi === $prior->us_aqi_mean && $newPm25 === $prior->pm2_5_mean_ug_m3) {
                            continue;
                        }
                        $rows[] = [
                            'region_code' => $region->code,
                            'observed_on' => $date,
                            'us_aqi_mean' => $newAqi,
                            'pm2_5_mean_ug_m3' => $newPm25,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                foreach (array_chunk($rows, 250) as $chunk) {
                    DB::table('climate_daily_summaries')->upsert(
                        $chunk,
                        ['region_code', 'observed_on'],
                        ['us_aqi_mean', 'pm2_5_mean_ug_m3', 'updated_at'],
                    );
                    $updatedRows += count($chunk);
                }
                $this->info('Batch '.($batchIndex + 1).': '.count($rows).' baris diperbarui.');
            } catch (Throwable $e) {
                $failedRegions += $batch->count();
                $this->error('Batch '.($batchIndex + 1).': '.$e->getMessage());
                if ((int) $e->getCode() === 429 || str_contains($e->getMessage(), 'HTTP 429')) {
                    $stoppedByQuota = true;
                    $this->warn('Batas permintaan API tercapai. Hentikan dan ulangi setelah kuota pulih.');
                    break;
                }
            }
            if ($scope === '') {
                sleep(2); // Kurangi risiko terkena batas permintaan API.
            }
        }

        $remaining = DB::table('climate_daily_summaries')
            ->whereBetween('observed_on', [$start, $today])
            ->whereIn('region_code', $regions->pluck('code')->all())
            ->whereNull('us_aqi_mean')
            ->count();
        $this->info("Selesai: {$updatedRows} baris diubah; {$filledAqi} nilai AQI dan {$filledPm25} nilai PM2.5 dilengkapi.");
        $this->line("AQI masih kosong: {$remaining} baris; wilayah pada batch gagal: {$failedRegions}.");
        if ($remaining > 0) {
            $this->warn('Nilai yang tetap kosong tidak diisi angka buatan. Periksa cakupan model/API dan log.');
        }

        return ($failedRegions === 0 && ! $stoppedByQuota) ? self::SUCCESS : self::FAILURE;
    }
}
