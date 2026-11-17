'use strict';

// Node.js >= 20. Tidak perlu npm install / database baru.
// Hanya dapat diakses dari Laravel pada 127.0.0.1:3107.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { promisify } = require('node:util');
const scrypt = promisify(crypto.scrypt);

const authPath = path.join(__dirname, 'credentials.json');
const PORT = 3107;
let credentials;
try {
  credentials = JSON.parse(fs.readFileSync(authPath, 'utf8'));
  if (credentials.scheme !== 'scrypt-v1' ||
      !/^[a-zA-Z0-9_.-]{3,40}$/.test(credentials.username || '') ||
      !/^[0-9a-f]{64}$/.test(credentials.token || '') ||
      !/^[0-9a-f]{32}$/.test(credentials.salt || '') ||
      !/^[0-9a-f]{128}$/.test(credentials.hash || '')) {
    throw new Error('Format kredensial tidak valid.');
  }
} catch (_error) {
  console.error('Kredensial Node.js belum siap. Jalankan: node auth-node/setup.cjs');
  process.exit(1);
}

const attempts = new Map();
const WINDOW_MS = 5 * 60 * 1000;
const MAX_ATTEMPTS = 5;
const MAX_PAYLOAD = 4096;
const SCRYPT_OPTIONS = { N: 32768, r: 8, p: 1, maxmem: 64 * 1024 * 1024 };

function safeEqual(left, right) {
  const a = Buffer.from(String(left));
  const b = Buffer.from(String(right));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
function reply(res, status, payload) {
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
    'Content-Security-Policy': "default-src 'none'",
  });
  res.end(JSON.stringify(payload));
}
function readJson(req) {
  return new Promise((resolve, reject) => {
    let text = '';
    let size = 0;
    let tooLarge = false;
    req.on('data', chunk => {
      size += chunk.length;
      if (size > MAX_PAYLOAD) {
        tooLarge = true;
        return;
      }
      text += chunk.toString('utf8');
    });
    req.on('end', () => {
      if (tooLarge) return reject(new Error('Payload too large'));
      try { resolve(JSON.parse(text)); } catch { reject(new Error('Invalid JSON')); }
    });
    req.on('error', reject);
  });
}
async function checkPassword(password) {
  // scrypt berbiaya memori, dengan salt unik. Tidak ada kata sandi teks biasa di disk.
  const key = await scrypt(password, Buffer.from(credentials.salt, 'hex'), 64, SCRYPT_OPTIONS);
  return crypto.timingSafeEqual(key, Buffer.from(credentials.hash, 'hex'));
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    return reply(res, 200, { ok: true, service: 'cerah-admin-auth' });
  }
  if (req.method !== 'POST' || req.url !== '/verify') return reply(res, 404, { ok: false });
  if (!safeEqual(req.headers['x-cerah-node-token'] || '', credentials.token)) {
    return reply(res, 403, { ok: false });
  }
  if (!String(req.headers['content-type'] || '').startsWith('application/json')) {
    return reply(res, 415, { ok: false });
  }

  let body;
  try { body = await readJson(req); }
  catch { return reply(res, 400, { ok: false }); }
  if (!body || typeof body !== 'object' ||
      typeof body.username !== 'string' || typeof body.password !== 'string' ||
      typeof body.ip !== 'string' || body.username.length > 80 ||
      body.password.length > 200 || body.ip.length > 64) {
    return reply(res, 400, { ok: false });
  }

  const key = crypto.createHash('sha256')
    .update(body.ip + ':' + body.username.toLowerCase()).digest('hex');
  const now = Date.now();
  let state = attempts.get(key);
  if (!state || state.until <= now) state = { count: 0, until: now + WINDOW_MS };
  if (state.count >= MAX_ATTEMPTS) {
    res.setHeader('Retry-After', String(Math.ceil((state.until - now) / 1000)));
    return reply(res, 429, { ok: false });
  }

  try {
    // Tetap lakukan scrypt walaupun nama pengguna salah untuk mengurangi petunjuk waktu.
    const passwordOk = await checkPassword(body.password);
    const usernameOk = safeEqual(
      crypto.createHash('sha256').update(body.username).digest('hex'),
      crypto.createHash('sha256').update(credentials.username).digest('hex')
    );
    if (!passwordOk || !usernameOk) {
      state.count++;
      attempts.set(key, state);
      if (attempts.size > 1500) {
        for (const [k, v] of attempts) if (v.until <= Date.now()) attempts.delete(k);
      }
      return reply(res, 401, { ok: false });
    }
    attempts.delete(key);
    return reply(res, 200, { ok: true });
  } catch (error) {
    console.error('Kesalahan verifikasi internal:', error?.code || 'unknown');
    return reply(res, 503, { ok: false });
  }
});
server.headersTimeout = 10000;
server.requestTimeout = 10000;
server.listen(PORT, '127.0.0.1', () => {
  console.log(`CERAH Node Auth aktif: http://127.0.0.1:${PORT}`);
  console.log('Login tetap melalui http://127.0.0.1:8000/admin/login');
});
