<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
use Throwable;

class AdminController extends Controller
{
    private const COMMANDS = [
        'weather_full' => ['weather:sync', [], 'Sinkronisasi cuaca penuh (semua wilayah)'],
        'weather_missing' => ['weather:sync', ['--missing' => true], 'Sinkronisasi cuaca (wilayah yang kosong)'],
        'climate_recent' => ['climate:sync-recent', ['--days' => 30], 'Penambalan riwayat 30 hari'],
        'news' => ['news:sync', [], 'Sinkronisasi berita'],
    ];

    public function loginForm()
    {
        return view('admin-login');
    }

    public function login(Request $request)
    {
        // Form dan sesi tetap dikelola Laravel. Verifikasi sandi dilakukan Node.js (localhost).
        $input = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $key = 'admin-login:v2:'.hash('sha256', strtolower($input['username']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['login' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.'])
                ->onlyInput('username');
        }

        $token = (string) config('admin.node_auth_token', '');
        $url = (string) config('admin.node_auth_url', 'http://127.0.0.1:3107');
        if (strlen($token) < 32) {
            return back()->withErrors(['login' => 'Layanan autentikasi Node.js belum dikonfigurasi. Jalankan setup admin.'])
                ->onlyInput('username');
        }

        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(2)->timeout(7)
                ->withHeaders(['X-Cerah-Node-Token' => $token])
                ->post(rtrim($url, '/').'/verify', [
                    'username' => $input['username'],
                    'password' => $input['password'],
                    'ip' => (string) $request->ip(),
                ]);
        } catch (Throwable $exception) {
            // Jangan tulis request/password ke log.
            logger()->warning('CERAH admin Node auth tidak dapat dihubungi', ['exception_type' => get_class($exception)]);
            return back()->withErrors(['login' => 'Layanan login sementara tidak tersedia. Pastikan Node.js berjalan.'])
                ->onlyInput('username');
        }

        if ($response->successful() && $response->json('ok') === true) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            $request->session()->put('cerah_admin', true);
            // Mengganti token saat reset kredensial akan membatalkan sesi admin lama.
            $request->session()->put('cerah_admin_auth_version', hash_hmac('sha256', 'cerah-admin-session-v1', $token));

            return redirect()->route('admin.index');
        }

        if ($response->status() === 401 || $response->status() === 429) {
            RateLimiter::hit($key, 300);
            $message = $response->status() === 429
                ? 'Terlalu banyak percobaan. Coba lagi beberapa menit.'
                : 'Nama pengguna atau kata sandi salah.';
            return back()->withErrors(['login' => $message])->onlyInput('username');
        }

        logger()->warning('CERAH admin Node auth mengembalikan status tidak diharapkan', ['status' => $response->status()]);
        return back()->withErrors(['login' => 'Layanan autentikasi Node.js tidak siap. Periksa terminal Node.js.'])
            ->onlyInput('username');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function index()
    {
        return view('admin', ['adminName' => config('admin.username')]);
    }

    public function data()
    {
        $tz = 'Asia/Jakarta';
        $latest = DB::table('weather_observations')->where('kind', 'current')->select('region_code')
            ->selectRaw('MAX(fetched_at) AS latest_at')->groupBy('region_code');
        $rows = DB::table('regions as r')
            ->leftJoinSub($latest, 'l', 'l.region_code', '=', 'r.code')
            ->leftJoin('weather_observations as w', function ($join) {
                $join->on('w.region_code', '=', 'r.code')->on('w.fetched_at', '=', 'l.latest_at')->where('w.kind', '=', 'current');
            })
            ->where('r.active', true)->orderBy('r.code')
            ->get(['r.code', 'r.name', 'r.type', 'r.parent_code', 'w.weather_code', 'w.temperature_c', 'w.relative_humidity', 'w.us_aqi', 'w.fetched_at']);

        $regions = $rows->map(fn ($r) => [
            'code' => $r->code,
            'name' => $r->name,
            'type' => $r->type,
            'top' => $r->parent_code === null,
            'weather' => $r->fetched_at === null ? null : $this->weatherLabel($r->weather_code),
            'temp' => $r->temperature_c === null ? null : round((float) $r->temperature_c),
            'humidity' => $r->relative_humidity,
            'aqi' => $r->us_aqi,
            'updated' => $r->fetched_at ? Carbon::parse($r->fetched_at)->timezone($tz)->format('d M H.i') : null,
        ])->values();

        $total = $rows->count();
        $withWeather = $rows->whereNotNull('fetched_at')->count();
        $quality = [
            'total' => $total,
            'weather' => $withWeather,
            'temperature' => $rows->whereNotNull('temperature_c')->count(),
            'humidity' => $rows->whereNotNull('relative_humidity')->count(),
            'aqi' => $rows->whereNotNull('us_aqi')->count(),
        ];

        // Grafik 7 hari: jumlah wilayah berhasil pada sinkronisasi cuaca (maksimum per hari, WIB).
        $since = Carbon::now($tz)->subDays(6)->startOfDay();
        $perDay = DB::table('processing_logs')->where('process', 'weather')->where('started_at', '>=', $since)->get(['started_at', 'records_succeeded'])
            ->groupBy(fn ($log) => Carbon::parse($log->started_at)->timezone($tz)->toDateString())
            ->map(fn ($group) => (int) $group->max('records_succeeded'));
        $weekly = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::now($tz)->subDays($i);
            $weekly[] = ['label' => $day->locale('id')->translatedFormat('D'), 'value' => (int) ($perDay[$day->toDateString()] ?? 0)];
        }

        $names = ['weather' => 'Sinkronisasi cuaca terkini', 'climate_history' => 'Riwayat iklim (ERA5)', 'climate_recent' => 'Penambalan riwayat harian', 'news' => 'Sinkronisasi berita', 'aggregate' => 'Agregasi cuaca harian'];
        $logs = DB::table('processing_logs')->orderByDesc('started_at')->limit(10)->get()->map(function ($log) use ($names, $tz) {
            $status = match ($log->status) { 'succeeded' => 'success', 'partial' => 'partial', 'running' => 'partial', default => 'fail' };
            $desc = $log->status === 'running' ? 'Sedang berjalan' : number_format((int) $log->records_succeeded, 0, ',', '.').' berhasil · '.number_format((int) $log->records_failed, 0, ',', '.').' gagal';
            if ($log->error_message) {
                $desc .= ' · '.mb_substr(trim(strtok($log->error_message, "\n")), 0, 90);
            }

            return [
                'name' => $names[$log->process] ?? $log->process,
                'desc' => $desc,
                'time' => Carbon::parse($log->started_at)->timezone($tz)->format('d M H.i'),
                'status' => $status,
            ];
        })->values();
        $lastWeather = DB::table('processing_logs')->where('process', 'weather')->orderByDesc('started_at')->first();

        // Iklim: 30 hari selesai (sampai kemarin).
        $to = Carbon::now($tz)->subDay()->toDateString();
        $from = Carbon::now($tz)->subDays(30)->toDateString();
        $stats = DB::table('climate_daily_summaries')->whereBetween('observed_on', [$from, $to])->whereNotNull('precipitation_mm')
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT region_code) AS regions, COALESCE(SUM(precipitation_mm),0) AS total, COALESCE(SUM(CASE WHEN precipitation_mm < 1 THEN 1 ELSE 0 END),0) AS dry')->first();
        $daily = DB::table('climate_daily_summaries')->whereBetween('observed_on', [$from, $to])->whereNotNull('precipitation_mm')
            ->groupBy('observed_on')->orderBy('observed_on')->selectRaw('observed_on, AVG(precipitation_mm) AS avg')->get()
            ->map(fn ($d) => ['date' => substr((string) $d->observed_on, 8, 2), 'value' => round((float) $d->avg, 1)])->values();
        $climateRegions = max(1, (int) $stats->regions);
        $climate = [
            'from' => $from, 'to' => $to,
            'rows' => (int) DB::table('climate_daily_summaries')->count(),
            'regions' => (int) $stats->regions,
            'avg_dry_days' => round($stats->dry / $climateRegions, 1),
            'avg_rain_mm' => round($stats->total / $climateRegions, 1),
            'coverage' => round($stats->n / ($climateRegions * 30) * 100),
            'daily' => $daily,
        ];

        $news = DB::table('news_articles')->orderByDesc('published_at')->limit(30)->get()->map(fn ($n) => [
            'id' => $n->id, 'title' => $n->title, 'source' => $n->source_name, 'url' => $n->url,
            'published' => Carbon::parse($n->published_at)->timezone($tz)->format('d M Y H.i'), 'hidden' => (bool) $n->is_hidden,
        ])->values();

        return response()->json([
            'regions' => $regions,
            'quality' => $quality,
            'weekly' => $weekly,
            'logs' => $logs,
            'last_weather' => $lastWeather ? ['processed' => (int) $lastWeather->records_processed, 'failed' => (int) $lastWeather->records_failed] : null,
            'climate' => $climate,
            'news' => $news,
            'news_visible' => DB::table('news_articles')->where('is_hidden', false)->count(),
            'generated_at' => Carbon::now($tz)->format('H.i'),
        ]);
    }

    public function run(Request $request)
    {
        $key = (string) $request->input('command');
        abort_unless(isset(self::COMMANDS[$key]), 422, 'Perintah tidak dikenal.');
        [$command, $arguments, $label] = self::COMMANDS[$key];

        if (! Cache::add("admin-run:{$key}", 1, now()->addMinutes(2))) {
            return response()->json(['ok' => false, 'message' => 'Proses ini baru saja dijalankan. Tunggu 2 menit.'], 429);
        }

        set_time_limit(900);
        try {
            $exit = Artisan::call($command, $arguments);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => $label.' gagal: '.mb_substr($e->getMessage(), 0, 200)], 500);
        }

        return response()->json([
            'ok' => $exit === 0,
            'message' => $label.($exit === 0 ? ' selesai.' : ' selesai dengan kode '.$exit.'.'),
            'output' => mb_substr($output, -600),
        ]);
    }

    public function toggleNews(int $id)
    {
        $article = DB::table('news_articles')->where('id', $id)->first();
        abort_unless($article, 404);
        DB::table('news_articles')->where('id', $id)->update(['is_hidden' => ! $article->is_hidden, 'updated_at' => now()]);
        Cache::forget('news:latest');

        return response()->json(['ok' => true, 'hidden' => ! $article->is_hidden]);
    }

    private function weatherLabel(mixed $code): string
    {
        if ($code === null) {
            return 'Tidak tersedia';
        }
        $code = (int) $code;

        return match (true) {
            $code === 0 => 'Cerah',
            in_array($code, [1, 2], true) => 'Cerah Berawan',
            $code === 3 => 'Berawan',
            in_array($code, [45, 48], true) => 'Berkabut',
            $code >= 51 && $code <= 57 => 'Gerimis',
            in_array($code, [61, 80], true) => 'Hujan Ringan',
            in_array($code, [63, 81], true) => 'Hujan Sedang',
            in_array($code, [65, 82, 66, 67], true) => 'Hujan Lebat',
            $code >= 95 => 'Hujan Petir',
            default => 'Berawan',
        };
    }
}