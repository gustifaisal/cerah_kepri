<?php

namespace App\Console\Commands;

use App\Services\MapBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CacheMapBoundaries extends Command
{
    protected $signature = 'map:cache-boundaries {--region= : Kode provinsi/kabupaten/kecamatan yang ingin diisi dahulu}';
    protected $description = 'Unduh poligon administrasi dan simpan lokal agar peta CERAH lebih cepat';

    public function handle(MapBoundaryService $boundaries): int
    {
        $region = (string) ($this->option('region') ?? '');
        if ($region !== '') {
            $type = DB::table('regions')->where('code', $region)->value('type');
            $level = match ($type) {
                'province' => 'municipalities',
                'regency', 'city' => 'districts',
                'district' => 'villages',
                default => null,
            };
            if ($level === null) {
                $this->error('Kode wilayah tidak ditemukan atau tidak memiliki anak.');
                return self::FAILURE;
            }
            $jobs = [['level' => $level, 'code' => $region]];
        } else {
            $regions = DB::table('regions')->where('active', true)
                ->whereIn('type', ['province','regency','city','district'])
                ->orderBy('code')->get(['code', 'type']);
            $jobs = $regions->map(fn ($r) => [
                'level' => match ($r->type) {
                    'province' => 'municipalities',
                    'city', 'regency' => 'districts',
                    default => 'villages',
                },
                'code' => (string) $r->code,
            ])->all();
        }
        $ok = 0; $partial = 0; $errors = 0;
        $this->info('Menyiapkan '.count($jobs).' grup poligon; data lokal akan dipakai kunjungan berikutnya.');
        foreach ($jobs as $job) {
            try {
                $data = $boundaries->collection($job['level'], $job['code']);
                $have = count($data['features']);
                $need = $data['_meta']['total_children'] ?? $have;
                $this->line($job['code'].' — '.$have.'/'.$need.' area');
                if ($have < $need) $partial++; else $ok++;
            } catch (Throwable $e) {
                $this->warn($job['code'].' — '.$e->getMessage());
                $errors++;
            }
        }
        $this->info("Lengkap: $ok, parsial: $partial, gagal: $errors.");
        return $errors === 0 && $partial === 0 ? self::SUCCESS : self::FAILURE;
    }
}
