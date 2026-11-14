<?php
// Router untuk server bawaan PHP:  php -S 127.0.0.1:8788 router.php
// - /api/* diteruskan ke app.php
// - file yang benar-benar ada (html/jpg/png/css/js) disajikan langsung
// - / menuju index.html, /landing dan /landing.html menuju landing.html
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') $path = '/';

if (str_starts_with($path, '/api/')) {
    define('A3_ENTRY', true);
    require __DIR__ . '/app.php';
    return true;
}

// Folder data/ (berisi database SQLite) tidak boleh diakses lewat web.
if (str_starts_with($path, '/data/')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

if ($path === '/landing' || $path === '/landing.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/landing.html');
    return true;
}

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

// File statis yang ada: biarkan server bawaan PHP menyajikannya.
// File PHP internal (config/app/router/api/index) tidak disajikan mentah:
// index.php menangani sisanya, file internal lain ditolak.
$file = __DIR__ . $path;
if (is_file($file)) {
    if (str_ends_with($path, '.php')) {
        if ($path === '/index.php' || $path === '/api.php') return false;
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
    return false;
}

// Sisanya (rute SPA yang tidak dikenal) -> index.html
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
return true;
