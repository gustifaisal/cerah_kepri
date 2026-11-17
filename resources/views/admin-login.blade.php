<!doctype html>
<html lang="id"><head>
<!-- Ikon tab CERAH -->
<link rel="icon" type="image/x-icon" href="/favicon.ico?v=cerah-20261117">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=cerah-20261117">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=cerah-20261117">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk Admin — CERAH</title>
<style>
:root{--ink:#183756;--muted:#7890a8;--line:#dbe8f2}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#ecf3f9;color:var(--ink);font-family:'Segoe UI',Inter,system-ui,sans-serif;font-size:14px;padding:20px}
.box{width:min(390px,100%);background:#fff;border:1px solid var(--line);border-radius:20px;padding:30px;box-shadow:0 10px 28px rgba(26,76,115,.08)}
.brand{display:flex;gap:11px;align-items:center;margin-bottom:22px}.brand svg{width:42px;height:42px}.brand strong{display:block;color:#12629d;letter-spacing:2px;font-size:20px;line-height:1.05}.brand small{color:#7492a8;font-size:10px}
h1{font-size:19px;margin:0 0 4px}p{color:var(--muted);font-size:12px;margin:0 0 18px}
label{display:block;font-size:12px;font-weight:700;color:#41677e;margin:12px 0 6px}
input{width:100%;border:1px solid #dce7f1;border-radius:10px;padding:11px 12px;font:inherit;outline:none}input:focus{border-color:#63a7d5}
button{width:100%;margin-top:20px;border:1px solid #1b73b4;background:#247fbd;color:#fff;border-radius:11px;padding:12px;font:inherit;font-weight:700;cursor:pointer}button:hover{background:#17679e}
.err{margin-top:14px;background:#fdece9;border:1px solid #f3d3ce;color:#b15248;border-radius:10px;padding:10px 12px;font-size:12px}
a{color:#2175b7;font-size:12px;font-weight:700;text-decoration:none;display:inline-block;margin-top:16px}
</style></head><body>
<form class="box" method="post" action="{{ route('admin.login.submit') }}">
@csrf
<div class="brand"><svg viewBox="0 0 56 56" aria-hidden="true"><circle cx="27" cy="19" r="11" fill="#f6b524"/><g stroke="#f6b524" stroke-width="3" stroke-linecap="round"><path d="M27 2v5M27 31v4M11 19H5M49 19h-5M12 5l4 4M42 5l-4 4"/></g><path d="M5 35Q16 22 29 34Q41 40 51 29L51 38Q39 49 26 42Q15 37 5 46Z" fill="#2098ca"/><path d="M7 46Q18 37 28 45Q40 51 50 42L50 49Q36 57 26 50Q15 45 7 53Z" fill="#1a6cac"/></svg><div><strong>CERAH</strong><small>ADMINISTRATOR</small></div></div>
<h1>Login Administrator</h1>
<label for="username">Nama pengguna</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
<label for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="current-password" required>
@if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
<button type="submit">Masuk</button>
<a href="{{ url('/') }}">← Kembali ke CERAH</a>
</form></body></html>
