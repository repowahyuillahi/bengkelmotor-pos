# A3 Motor Service Station — Edisi PHP Native (Offline / Server Sendiri)

Ini adalah aplikasi kasir A3 Motor Service Station yang **sama persis**
dengan versi online di Cloudflare (https://a3motor.pages.dev), tetapi
backend-nya ditulis ulang dengan **PHP native** (tanpa framework, tanpa
Composer, tanpa proses build) agar bisa berjalan di server sendiri:
**XAMPP di PC toko** atau **aaPanel**.

Semua fitur sama: kasir, katalog & stok, riwayat, laporan (termasuk
ekspor Excel/PDF), data pelanggan, shift kasir, kunci PIN, pengaturan
toko & logo, dan halaman landing publik.

> **PENTING — data benar-benar terpisah.** Edisi PHP ini memakai
> database sendiri. Transaksi di sini **tidak** muncul di versi
> Cloudflare, dan sebaliknya. PIN juga dibuat ulang saat pertama kali
> dijalankan (layar pertama akan meminta pembuatan PIN baru).

## Isi Folder

| File / Folder | Fungsi |
|---|---|
| `index.html` | Aplikasi kasir (frontend, sama dengan versi Cloudflare) |
| `landing.html` | Halaman landing publik (di `/landing`) |
| `app.php` | Seluruh backend API (tidak untuk diakses langsung) |
| `api.php` | Pintu masuk API untuk Apache/Nginx |
| `index.php` | Pintu masuk utama (API + aplikasi) |
| `router.php` | Router untuk server bawaan PHP (`php -S`) |
| `config.php` | **Satu-satunya file yang perlu diubah**: pilihan database |
| `.htaccess` | Aturan Apache/XAMPP (sudah jadi, tidak perlu diubah) |
| `aapanel-rewrite.conf` | Cuplikan rewrite Nginx untuk aaPanel |
| `data/` | Tempat file database SQLite (`data/a3motor.sqlite`) |
| `logo.png`, `hero.jpg`, `galeri-*.jpg`, `nota.jpg` | Gambar bawaan aplikasi/landing |

## Pilihan Database (di `config.php`)

- **SQLite (bawaan, `DB_DRIVER = 'sqlite'`)** — paling gampang. Tidak
  perlu server database. Semua data tersimpan dalam satu file
  `data/a3motor.sqlite` yang dibuat otomatis saat pertama dijalankan.
  Syaratnya hanya ekstensi PHP `pdo_sqlite` aktif.
- **MySQL (`DB_DRIVER = 'mysql'`)** — untuk aaPanel/hosting. Isi
  host/nama database/user/password di `config.php`. Tabel dibuat
  **otomatis** oleh aplikasi saat pertama kali dijalankan, jadi tidak
  wajib mengimpor SQL secara manual.

## Cara Pasang di XAMPP (Windows, PC Toko)

1. Pasang XAMPP (pilih yang berisi **PHP 8.x**), lalu jalankan
   **XAMPP Control Panel**.
2. Copy seluruh folder ini ke dalam folder `htdocs`, contoh:
   `C:\xampp\htdocs\a3motor`
3. Pastikan ekstensi SQLite aktif: buka `C:\xampp\php\php.ini`, cari
   baris `;extension=pdo_sqlite` dan `;extension=sqlite3`, hapus tanda
   `;` di depannya bila ada, lalu simpan. (Di XAMPP versi baru biasanya
   sudah aktif dari awal.)
4. Start modul **Apache** saja dari XAMPP Control Panel.
   **MySQL tidak perlu dijalankan** bila memakai mode SQLite.
5. Buka browser: `http://localhost/a3motor`
6. Pada pemakaian pertama aplikasi meminta **membuat PIN** baru
   (4–8 digit). Setelah itu isi katalog dan buka shift seperti biasa.

### Diakses dari HP / komputer lain di jaringan toko (LAN)

1. Cari alamat IP PC toko, mis. `192.168.1.10` (perintah `ipconfig`).
2. Dari HP/komputer lain yang satu WiFi/LAN, buka
   `http://192.168.1.10/a3motor`
3. Bila tidak bisa dibuka, izinkan Apache lewat **Windows Firewall**:
   saat pertama kali Apache dijalankan Windows biasanya menampilkan
   permintaan izin — pilih **Allow** untuk jaringan Private. Atau buat
   aturan masuk (Inbound Rule) untuk port **80** secara manual.
4. Login PIN tetap bekerja lewat HTTP di jaringan lokal — cookie sesi
   memang hanya diberi flag `Secure` bila koneksinya HTTPS.

## Cara Pasang di aaPanel

1. Buat situs baru di aaPanel (**Website → Add site**), arahkan ke
   domain/subdomain yang diinginkan, dan pilih **PHP 8.x**.
2. Upload seluruh isi folder ini ke root situs (mis.
   `/www/wwwroot/a3motor`), **termasuk** file tersembunyi `.htaccess`
   bila memakai Apache; untuk Nginx lihat langkah 3.
3. **Bila memakai Nginx:** buka **Website → pilih situs → Settings →
   Rewrite (Pseudo-static)**, lalu tempel isi file
   `aapanel-rewrite.conf` dari folder ini dan **Save**.
4. Pastikan folder `data/` bisa ditulis oleh web server (user `www`):
   atur permission folder `data/` menjadi `775` dan pemilik `www`
   bila perlu (File Manager aaPanel → Permissions).
5. Buka alamat situsnya. Aplikasi langsung berjalan dalam mode SQLite
   (ekstensi `pdo_sqlite` harus aktif di PHP aaPanel: **App Store →
   PHP 8.x → Settings → Install extensions** bila belum ada).
6. **Opsional — pindah ke MySQL:** buat database & user di aaPanel
   (**Databases → Add database**), lalu ubah `config.php`:
   `DB_DRIVER` menjadi `'mysql'` dan isi nama database, user, dan
   password-nya. Tabel dibuat otomatis saat aplikasi pertama diakses,
   tidak perlu impor SQL manual.

## Cara Cepat Menjalankan Tanpa XAMPP/aaPanel (untuk mencoba)

Bila di komputer ada PHP 8.x, dari dalam folder ini jalankan:

```bash
php -S 127.0.0.1:8788 router.php
```

Lalu buka `http://127.0.0.1:8788`. Cocok untuk sekadar mencoba;
untuk pemakaian harian di toko gunakan XAMPP/aaPanel di atas.

## Backup Data

- **Mode SQLite:** cukup copy satu file `data/a3motor.sqlite` ke
  tempat aman (flashdisk/Google Drive). Untuk memulihkan, matikan
  dulu Apache/web server, lalu timpa kembali file tersebut.
- **Mode MySQL:** gunakan **mysqldump** atau fitur Backup pada menu
  Databases di aaPanel:
  `mysqldump -u <user> -p a3motor > backup-a3motor.sql`

Lakukan backup secara berkala — database ini berisi seluruh riwayat
transaksi toko pada edisi PHP ini.

## Catatan Teknis Singkat

- Semua endpoint `/api/*`, bentuk JSON, dan pesan error identik dengan
  versi Cloudflare, karena frontend-nya memang file yang sama.
- PIN disimpan sebagai hash SHA-256; sesi login adalah cookie bertanda
  tangan HMAC yang berlaku 7 hari, sama seperti versi Cloudflare.
- Zona waktu laporan mengikuti **WIB (Asia/Jakarta)**, sama seperti
  versi online.
- Kuota Cloudflare tidak terpakai sama sekali oleh edisi ini.
