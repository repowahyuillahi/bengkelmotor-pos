<?php
// =====================================================================
// A3 Motor Service Station - Konfigurasi Edisi PHP Native
// =====================================================================
// File ini satu-satunya yang perlu diubah saat pindah server.
// JANGAN membagikan file ini ke publik (sudah dilindungi .htaccess /
// aturan Nginx, tapi tetap jaga kerahasiaan kata sandi MySQL di sini).

// ---------------------------------------------------------------------
// PILIHAN DATABASE: 'sqlite' atau 'mysql'
// ---------------------------------------------------------------------
// 'sqlite' (bawaan, paling gampang):
//   - Tidak perlu server database sama sekali. Cukup ekstensi
//     pdo_sqlite aktif di PHP (di XAMPP biasanya sudah aktif).
//   - Semua data tersimpan dalam SATU file: data/a3motor.sqlite
//   - Backup = copy file itu saja.
//
// 'mysql' (cocok untuk aaPanel / hosting):
//   - Isi data koneksi MySQL di bawah.
//   - Tabel dibuat OTOMATIS oleh aplikasi saat pertama kali dijalankan
//     (tidak wajib import SQL manual).
define('DB_DRIVER', 'sqlite');

// Lokasi file database SQLite (hanya dipakai bila DB_DRIVER = 'sqlite').
// Folder data/ harus bisa ditulis oleh web server.
define('DB_SQLITE_PATH', __DIR__ . '/data/a3motor.sqlite');

// ---------------------------------------------------------------------
// KONEKSI MYSQL (hanya dipakai bila DB_DRIVER = 'mysql')
// ---------------------------------------------------------------------
define('DB_MYSQL_HOST', '127.0.0.1');
define('DB_MYSQL_PORT', '3306');
define('DB_MYSQL_NAME', 'a3motor');
define('DB_MYSQL_USER', 'root');
define('DB_MYSQL_PASS', '');
