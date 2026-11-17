<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('admin.node_auth_token', '');
        $actual = $request->session()->get('cerah_admin_auth_version');
        $expected = strlen($token) >= 32
            ? hash_hmac('sha256', 'cerah-admin-session-v1', $token)
            : '';

        if ($request->session()->get('cerah_admin') === true
            && is_string($actual)
            && $expected !== ''
            && hash_equals($expected, $actual)) {
            return $next($request);
        }

        // Sesi lama dari metode PHP atau sebelum rotasi token tidak diizinkan.
        $request->session()->forget(['cerah_admin', 'cerah_admin_auth_version']);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sesi admin berakhir. Silakan masuk kembali.'], 401);
        }
        return redirect()->route('admin.login');
    }
}
