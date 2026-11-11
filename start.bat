@echo off
title Kasir A3 Motor - Offline
cd /d %~dp0
echo ============================================
echo   Kasir A3 Motor - Mode Offline (Windows)
echo ============================================
echo.
where node >nul 2>nul
if %errorlevel% neq 0 (
  echo Node.js belum terpasang.
  where winget >nul 2>nul
  if %errorlevel% equ 0 (
    echo Memasang Node.js lewat winget, tunggu sebentar...
    winget install -e --id OpenJS.NodeJS.LTS --accept-source-agreements --accept-package-agreements
    echo.
    echo Pemasangan Node.js selesai.
    echo TUTUP jendela ini, lalu jalankan start.bat sekali lagi.
    pause
    exit /b
  )
  echo Membuka halaman unduhan Node.js...
  start https://nodejs.org/en/download
  echo Pasang Node.js versi LTS, lalu TUTUP jendela ini dan jalankan start.bat lagi.
  pause
  exit /b
)
if not exist node_modules (
  echo Mengunduh komponen yang dibutuhkan...
  echo Langkah ini butuh internet, hanya satu kali ini saja.
  call npm install
  if %errorlevel% neq 0 (
    echo.
    echo Pengunduhan gagal. Periksa koneksi internet, lalu jalankan start.bat lagi.
    pause
    exit /b 1
  )
)
echo Menyiapkan database lokal...
call npx wrangler d1 execute a3motor-db --local --file=schema.sql >nul 2>nul
echo.
echo Kasir berjalan di http://127.0.0.1:8788
echo Browser akan terbuka sendiri. Bila tidak, buka alamat di atas manual.
echo JANGAN tutup jendela ini selama kasir dipakai. Menutup = kasir berhenti.
echo.
start /b cmd /c "timeout /t 6 >nul & start http://127.0.0.1:8788"
call npx wrangler pages dev dist --port 8788 --ip 127.0.0.1
pause
