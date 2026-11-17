<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * GeoJSON simplified polygons from Emsifa static paths v2 (cahyadsn data).
 * Not legally definitive administrative boundaries. No fake centroid polygons.
 */
class MapBoundaryService
{
    private const BASE = 'https://www.emsifa.com/api-wilayah-indonesia/v2/paths/';

    /** @return array<string,mixed> GeoJSON FeatureCollection */
    public function collection(string $level, string $parent, bool $refresh = false): array
    {
        $groupFile = $this->groupPath($level, $parent);
        $cached = $this->readJson($groupFile);
        if (! $refresh && $this->validCollection($cached)) {
            return $cached;
        }
        $children = DB::table('regions')->where('parent_code', $parent)
            ->where('active', true)->orderBy('code')->get(['code', 'name']);
        if ($children->isEmpty()) {
            throw new RuntimeException('Tidak ada wilayah anak yang aktif untuk kode '.$parent.'.');
        }

        $features = [];
        $missing = [];
        $toFetch = [];
        foreach ($children as $child) {
            $code = (string) $child->code;
            $geometry = $refresh ? null : $this->readGeometry($code);
            if ($geometry !== null) {
                $features[$code] = $this->feature($child, $geometry);
            } else {
                $toFetch[$code] = $child;
            }
        }

        // API statis per kode; ambil secara paralel agar 4-17 kecamatan
        // tidak menunggu serial. Per grup maksimum delapan koneksi.
        foreach (array_chunk($toFetch, 8, true) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $code => $child) {
                        $pool->as((string) $code)
                            ->acceptJson()->connectTimeout(5)->timeout(13)
                            ->get(self::BASE.rawurlencode((string) $code).'.json');
                    }
                });
                foreach ($chunk as $code => $child) {
                    $response = $responses[(string) $code] ?? null;
                    if (! $response || ! $response->successful()) {
                        $missing[] = (string) $code;
                        continue;
                    }
                    $data = $response->json();
                    $geometry = $this->geometryFromPath($data['data']['path'] ?? null);
                    if ($geometry === null) {
                        $missing[] = (string) $code;
                        continue;
                    }
                    $features[(string) $code] = $this->feature($child, $geometry);
                    $this->writeJson($this->singlePath((string) $code), $geometry);
                }
            } catch (Throwable $e) {
                report($e);
                foreach (array_keys($chunk) as $code) {
                    $missing[] = (string) $code;
                }
            }
        }

        // Jika API sedang tidak tersedia, utamakan poligon asli yang pernah
        // disimpan, bukan menebak dari titik koordinat.
        foreach ($children as $child) {
            $code = (string) $child->code;
            if (isset($features[$code])) continue;
            $geometry = $this->readGeometry($code);
            if ($geometry !== null) $features[$code] = $this->feature($child, $geometry);
        }
        if (! count($features) && $this->validCollection($cached)) return $cached;
        if (! count($features)) {
            throw new RuntimeException('Semua '.count($toFetch).' permintaan geometri gagal/kosong. Uji https://www.emsifa.com/api-wilayah-indonesia/v2/paths/21.72.04.json dari komputer Anda.');
        }
        ksort($features, SORT_STRING);
        $result = [
            'type' => 'FeatureCollection',
            'features' => array_values($features),
            '_meta' => [
                'source' => 'Emsifa static v2 / cahyadsn wilayah_boundaries',
                'total_children' => $children->count(),
                'features_count' => count($features),
                'incomplete' => count($features) < $children->count(),
            ],
        ];
        if (! $result['_meta']['incomplete']) $this->writeJson($groupFile, $result);
        // Jangan simpan kumpulan parsial sebagai 'berhasil lengkap' permanen.
        return $result;
    }

    private function feature(object $region, array $geometry): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['code' => (string) $region->code, 'name' => (string) $region->name],
            'geometry' => $geometry,
        ];
    }

    /**
     * Emsifa path is nested rings of [latitude, longitude] (Leaflet convention),
     * but GeoJSON MUST store each point as [longitude, latitude].
     * Emsifa combines islands as separate rings; we expose each as separate
     * polygon rather than accidentally treating another island as a hole.
     */
    public function geometryFromPath(mixed $path): ?array
    {
        if (! is_array($path)) return null;
        $rings = [];
        $collect = function ($node) use (&$collect, &$rings): void {
            if (! is_array($node) || ! count($node)) return;
            if (is_array($node[0] ?? null) && is_numeric($node[0][0] ?? null)) {
                $ring = [];
                foreach ($node as $point) {
                    if (! is_array($point) || count($point) < 2 ||
                        ! is_numeric($point[0]) || ! is_numeric($point[1])) continue;
                    $lat = (float) $point[0];
                    $lng = (float) $point[1];
                    // Indonesia-wide sanity bounds; reject malformed coordinates.
                    if ($lat < -12 || $lat > 12 || $lng < 94 || $lng > 142) continue;
                    $ring[] = [$lng, $lat];
                }
                if (count($ring) < 3) return;
                if ($ring[0] !== $ring[count($ring) - 1]) $ring[] = $ring[0];
                if (count($ring) >= 4) $rings[] = [$ring];
                return;
            }
            foreach ($node as $child) $collect($child);
        };
        $collect($path);
        if (! count($rings)) return null;
        return count($rings) === 1
            ? ['type' => 'Polygon', 'coordinates' => $rings[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $rings];
    }

    private function readGeometry(string $code): ?array
    {
        $geo = $this->readJson($this->singlePath($code));
        return is_array($geo) && in_array($geo['type'] ?? '', ['Polygon', 'MultiPolygon'], true)
            && ! empty($geo['coordinates']) ? $geo : null;
    }

    private function validCollection(?array $data): bool
    {
        return is_array($data) && ($data['type'] ?? '') === 'FeatureCollection'
            && ! empty($data['features']) && is_array($data['features']);
    }

    private function readJson(string $file): ?array
    {
        if (! is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0775, true) && ! is_dir(dirname($file))) return;
        $temp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== false) @rename($temp, $file);
    }

    private function groupPath(string $level, string $parent): string
    {
        return storage_path('app/map-boundaries-emsifa/groups/'.$level.'/'.$parent.'.geojson');
    }

    private function singlePath(string $code): string
    {
        return storage_path('app/map-boundaries-emsifa/regions/'.$code.'.json');
    }
}
