<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CerahReverseGeocodeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Batasi uji coba ke sekitar Kepulauan Riau (termasuk Natuna/Anambas).
        $lat = round((float) $input['lat'], 4);
        $lon = round((float) $input['lon'], 4);
        if ($lat < -1.5 || $lat > 5.5 || $lon < 102.5 || $lon > 110.0) {
            return response()->json(['message' => 'Koordinat di luar cakupan CERAH.'], 422);
        }

        $key = 'cerah:reverse-osm:' . sha1($lat . ',' . $lon);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return response()->json($cached);
        }

        // Pembatas global bagi semua pengunjung pada satu cache bersama.
        // Untuk skala produksi gunakan Redis/shared cache dan penyedia dengan SLA.
        if (!Cache::add('cerah:reverse-osm:request-slot', true, 2)) {
            return response()->json(['message' => 'Layanan lokasi sibuk. Coba lagi.'], 429);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => 'id',
                'User-Agent' => 'CERAH-Weather/1.0 (+https://cerah.gurind.am)',
            ])->timeout(6)->get('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'lat' => $lat,
                'lon' => $lon,
                'zoom' => 18,
                'addressdetails' => 1,
            ]);

            if (!$response->successful()) {
                return response()->json(['message' => 'Reverse geocoding tidak tersedia.'], 503);
            }

            $raw = $response->json();
            $address = is_array($raw) ? ($raw['address'] ?? []) : [];
            if (!is_array($address)) $address = [];

            // Hanya nama administratif yang diperlukan (bukan alamat lengkap).
            $fields = [
                'village', 'suburb', 'quarter', 'neighbourhood', 'hamlet',
                'residential', 'city_block', 'city_district', 'district',
                'subdistrict', 'borough', 'city', 'town', 'municipality', 'county',
            ];
            $filtered = array_intersect_key($address, array_flip($fields));
            $result = [
                'address' => $filtered,
                'source' => 'OpenStreetMap/Nominatim',
            ];

            Cache::put($key, $result, now()->addDay());
            return response()->json($result);
        } catch (\Throwable $error) {
            // Jangan memasukkan lokasi pengguna atau pesan koneksi mentah ke respons.
            return response()->json(['message' => 'Layanan lokasi belum tersedia.'], 503);
        }
    }
}
