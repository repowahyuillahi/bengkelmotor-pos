<?php
// Pintu masuk utama bila web server diarahkan ke file ini
// (mis. fallback SPA di Apache/Nginx):
// - /api/* diteruskan ke logika app.php
// - selain itu tampilkan index.html (aplikasi kasir)
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/api/')) {
    define('A3_ENTRY', true);
    require __DIR__ . '/app.php';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
