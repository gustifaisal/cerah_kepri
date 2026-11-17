<?php

namespace App\Console\Commands;

use App\Services\ClimateInsight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menghitung ulang ringkasan iklim wilayah tingkat atas (provinsi, kabupaten, kota)
 * dan menyimpannya di cache. Tanpa ini, membuka tampilan provinsi harus menghitung
 * ratusan kelurahan satu per satu saat pengunjung menunggu.
 */
class WarmClimateCache extends Command
{
    protected $signature = 'climate:warm';

    protected $description = 'Hangatkan cache ringkasan iklim provinsi, kabupaten, dan kota';

    public function handle(ClimateInsight $insight): int
    {
        $regions = DB::table('regions')->where('active', true)->whereIn('type', ['province', 'regency', 'city'])->orderBy('code')->get(['code', 'name']);
        $done = 0;

        foreach ($regions as $region) {
            foreach ([30, 7] as $days) {
                try {
                    $summary = $insight->forRegion($region->code, $days);
                    Cache::put("climate-summary:v2:{$region->code}:{$days}", $summary, now()->addHours(8));
                    $done++;
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("Gagal: {$region->name} ({$days} hari): ".$e->getMessage());
                }
            }
        }

        $this->info("Cache iklim dihangatkan: {$done} entri.");

        return self::SUCCESS;
    }
}