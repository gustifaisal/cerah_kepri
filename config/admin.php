<?php

return [
    // Nama admin hanya untuk ditampilkan di dashboard.
    // Username/password diverifikasi oleh Node.js, BUKAN oleh Laravel.
    'username' => env('ADMIN_USERNAME', 'admin'),
    'node_auth_url' => env('ADMIN_NODE_AUTH_URL', 'http://127.0.0.1:3107'),
    'node_auth_token' => env('ADMIN_NODE_TOKEN'),
];
