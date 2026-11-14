<?php
// Pintu masuk khusus API untuk Apache (XAMPP) dan Nginx (aaPanel).
// Aturan rewrite mengarahkan semua /api/* ke file ini, sementara
// $_SERVER['REQUEST_URI'] tetap berisi alamat /api/... aslinya,
// sehingga app.php dapat merutekan persis seperti versi Cloudflare.
define('A3_ENTRY', true);
require __DIR__ . '/app.php';
