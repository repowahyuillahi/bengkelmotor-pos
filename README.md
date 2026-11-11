# Kasir A3 Motor — Versi Offline untuk Windows

Aplikasi kasir A3 Motor Service Station yang berjalan **sepenuhnya di PC Windows kamu,
tanpa internet**. Datanya tersimpan di PC itu sendiri, bukan di cloud.

Kode sumber aplikasi ini juga tersimpan sebagai repo publik di GitHub:
https://github.com/repowahyuillahi/bengkelmotor-pos — dari repo itulah update untuk versi offline ini
diunduh. Versi online-nya berjalan di https://a3motor.pages.dev

Isi paket ini sama dengan aplikasi kasir online di https://a3motor.pages.dev
(kasir, stok barang, kelola jasa, riwayat, laporan, shift, data pelanggan, kunci PIN),
hanya saja databasenya lokal.

## Yang dibutuhkan

- PC Windows 10 atau 11.
- **Internet hanya satu kali**, saat pertama kali menjalankan `start.bat`:
  script akan memasang Node.js (bila belum ada dan tersedia winget) dan mengunduh
  komponen aplikasi (Wrangler) lewat npm. Setelah itu aplikasi berjalan **100% offline**,
  selamanya, di PC tersebut.

## Cara menjalankan

1. Ekstrak file zip ini ke folder mana pun, misalnya `C:\KasirA3Motor`.
2. Klik dua kali **start.bat**.
   - Bila Windows menampilkan peringatan SmartScreen, klik "More info" lalu "Run anyway".
   - Bila Node.js belum terpasang dan winget tidak tersedia, halaman unduhan Node.js
     akan terbuka otomatis. Pasang Node.js (pilih versi LTS), tutup jendela start.bat,
     lalu jalankan **start.bat** sekali lagi.
3. Tunggu sampai muncul tulisan "Kasir berjalan". Browser akan terbuka sendiri di
   alamat http://127.0.0.1:8788
4. Saat pertama kali, aplikasi meminta kamu **membuat PIN**. Buat PIN yang kamu ingat,
   PIN ini yang dipakai setiap membuka kasir. Selesai, kasir siap dipakai.

Untuk memakai lagi keesokan harinya, cukup klik dua kali **start.bat** lagi
(tidak perlu internet lagi).

## Penting

- **Jangan tutup jendela hitam start.bat selama kasir dipakai.** Menutupnya berarti
  mematikan kasir. Untuk berhenti, cukup tutup jendela itu.
- Kasir hanya bisa dibuka **di PC ini saja** (alamat 127.0.0.1). Kalau nanti mau bisa
  dibuka juga dari HP atau tablet lewat Wi-Fi toko, kabari saja, ada varian jaringannya.

## Data dan backup

- Semua data (barang, jasa, transaksi, shift, PIN) tersimpan di folder
  **`.wrangler\state`** di dalam folder aplikasi ini.
- **Backup:** salin folder `.wrangler` ke flashdisk atau tempat aman secara berkala.
  Lebih aman lagi: salin seluruh folder aplikasi.
- **Pindah PC:** salin seluruh folder aplikasi ke PC baru, jalankan `start.bat` di sana,
  data ikut terbawa.
- Jangan menghapus folder `.wrangler`, isinya adalah database kasir kamu.

## Catatan: database ini terpisah dari versi online

Versi offline ini databasenya sendiri. Transaksi yang dibuat di versi offline
**tidak muncul** di a3motor.pages.dev, dan sebaliknya. Anggap versi online dan versi
offline sebagai dua kasir terpisah; pilih satu yang jadi kasir utama toko.

## Cara update ke versi baru

Aplikasi di PC ini tidak memeriksa update sendiri (memang disengaja, karena offline).
Bila ada versi baru, kamu akan menerima file bernama **a3motor-update.zip**. Caranya:

1. Tutup dulu kasir (tutup jendela start.bat).
2. Taruh file **a3motor-update.zip** di folder aplikasi ini (sejajar dengan start.bat).
3. Klik dua kali **update.bat**, lalu ikuti petunjuknya (langkah ini butuh internet sebentar).
4. Jalankan **start.bat** lagi seperti biasa.

Tenang, update hanya mengganti file aplikasi. Folder database `.wrangler` tidak pernah
disentuh oleh update.bat, jadi **seluruh data, stok, transaksi, dan PIN kamu aman**.

Hal yang sama berlaku untuk kode di GitHub: versi terbaru akan selalu tersedia di repo
`a3motor` (setelah repo-nya jadi), dan file update di atas dibuat dari kode yang sama.

## Kalau ada masalah

- **"npm install gagal"**: periksa koneksi internet (langkah ini hanya untuk unduhan
  pertama), lalu jalankan start.bat lagi.
- **Browser tidak terbuka sendiri**: buka manual alamat http://127.0.0.1:8788
- **Alamat sudah dipakai (port 8788)**: biasanya karena kasir masih berjalan di jendela
  lain. Tutup dulu jendela start.bat yang lama.
- **Lupa PIN**: hapus data PIN lewat database lokal itu teknis; minta bantuan Biji
  (asistenmu) dengan menyebut "reset PIN kasir offline", jangan menghapus file apa pun
  sendiri.
