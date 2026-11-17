'use strict';

// Inisialisasi nama pengguna, hash password scrypt, dan token koneksi Laravel.
// Hanya dijalankan secara interaktif oleh pemilik server (Windows PowerShell).
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const readline = require('node:readline');
const { promisify } = require('node:util');
const scrypt = promisify(crypto.scrypt);

const root = path.resolve(__dirname, '..');
const envPath = path.join(root, '.env');
const credentialsPath = path.join(__dirname, 'credentials.json');
const SCRYPT_OPTIONS = { N: 32768, r: 8, p: 1, maxmem: 64 * 1024 * 1024 };

function questionVisible(prompt) {
  return new Promise(resolve => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    rl.question(prompt, answer => { rl.close(); resolve(answer.trim()); });
  });
}
function questionSecret(prompt) {
  if (!process.stdin.isTTY || !process.stdin.setRawMode) {
    throw new Error('Jalankan setup di PowerShell interaktif (bukan pipe atau output redirect).');
  }
  return new Promise((resolve, reject) => {
    process.stdout.write(prompt);
    let result = '';
    readline.emitKeypressEvents(process.stdin);
    process.stdin.setRawMode(true);
    process.stdin.resume();
    const onKey = (ch, key) => {
      if (key?.ctrl && key?.name === 'c') {
        cleanup(); process.stdout.write('\n'); reject(new Error('Dibatalkan.')); return;
      }
      if (key?.name === 'return' || key?.name === 'enter') {
        cleanup(); process.stdout.write('\n'); resolve(result); return;
      }
      if (key?.name === 'backspace') {
        result = result.slice(0, -1); return;
      }
      // Di PowerShell karakter password tidak ditampilkan (bahkan tidak pakai bintang).
      if (ch && !key?.ctrl && !key?.meta && /^[^\x00-\x1F\x7F]+$/u.test(ch)) result += ch;
    };
    const cleanup = () => {
      process.stdin.off('keypress', onKey);
      process.stdin.setRawMode(false);
      process.stdin.pause();
    };
    process.stdin.on('keypress', onKey);
  });
}
function putEnvValue(env, name, value) {
  const lines = env.split(/\r?\n/).filter(line => !new RegExp('^'+name+'=').test(line));
  // Nilai dibuat aman untuk dotenv: username [a-zA-Z0-9_.-], token berupa hex.
  lines.push(name+'='+value);
  return lines.join('\r\n').replace(/(?:\r\n)*$/, '\r\n');
}
async function main() {
  if (!fs.existsSync(envPath)) throw new Error('File .env Laravel tidak ditemukan. Jalankan di folder proyek CERAH.');
  if (!process.stdin.isTTY) throw new Error('Buka PowerShell interaktif untuk membuat kredensial.');
  console.log('=== Pengaturan Login Admin CERAH melalui Node.js ===');
  console.log('Login lama akan diganti. Gunakan password BARU minimal 12 karakter.');
  let username = await questionVisible('Username admin [admin]: ');
  username ||= 'admin';
  if (!/^[a-zA-Z0-9_.-]{3,40}$/.test(username)) {
    throw new Error('Username harus 3–40 karakter: huruf, angka, titik, garis bawah, atau minus.');
  }
  const password = await questionSecret('Password baru (tidak ditampilkan): ');
  if (password.length < 12 || password.length > 128) {
    throw new Error('Panjang password harus 12–128 karakter.');
  }
  const confirm = await questionSecret('Ulangi password baru: ');
  if (password !== confirm) throw new Error('Password tidak sama. Tidak ada file yang diubah.');

  const salt = crypto.randomBytes(16);
  const hash = await scrypt(password, salt, 64, SCRYPT_OPTIONS);
  const token = crypto.randomBytes(32).toString('hex');
  const creds = JSON.stringify({ scheme: 'scrypt-v1', username, salt: salt.toString('hex'),
    hash: hash.toString('hex'), token }, null, 2) + '\n';
  const original = fs.readFileSync(envPath, 'utf8');
  const backup = path.join(__dirname, 'laravel-env-backup.txt');
  if (!fs.existsSync(backup)) fs.writeFileSync(backup, original, { mode: 0o600, flag: 'wx' });
  let updated = putEnvValue(original, 'ADMIN_USERNAME', username);
  updated = putEnvValue(updated, 'ADMIN_NODE_TOKEN', token);
  const temp = credentialsPath + '.tmp';
  fs.writeFileSync(temp, creds, { mode: 0o600 });
  fs.renameSync(temp, credentialsPath);
  fs.writeFileSync(envPath, updated, { mode: 0o600 });
  console.log('\nBerhasil. Token dan hash password disimpan, password teks biasa TIDAK disimpan.');
  console.log('Sekarang jalankan: php artisan optimize:clear');
  console.log('Kemudian: node auth-node/server.cjs');
  console.log('JANGAN unggah auth-node/credentials.json atau .env ke GitHub.');
}
main().catch(error => { console.error('GAGAL:', error.message); process.exitCode = 1; });
