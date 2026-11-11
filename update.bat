@echo off
title Update Kasir A3 Motor
cd /d %~dp0
echo ============================================
echo   Update Kasir A3 Motor (Offline)
echo ============================================
echo.
echo PASTIKAN jendela start.bat (kasir) sudah DITUTUP sebelum lanjut.
pause
echo Mengunduh update terbaru dari GitHub...
powershell -NoProfile -Command "try { Invoke-WebRequest -Uri 'https://github.com/repowahyuillahi/bengkelmotor-pos/archive/refs/heads/main.zip' -OutFile 'a3motor-update.zip' -ErrorAction Stop } catch { exit 1 }"
if %errorlevel% equ 0 goto ekstrak
if exist a3motor-update.zip (
  echo Unduhan gagal, memakai file a3motor-update.zip yang ada di folder ini.
  goto ekstrak
)
echo.
echo Tidak bisa mengunduh update. Kemungkinan penyebabnya:
echo - PC sedang tidak terhubung ke internet, atau
echo - repo GitHub bengkelmotor-pos belum tersedia.
echo Cara lain: taruh file a3motor-update.zip di folder ini, lalu jalankan lagi.
pause
exit /b 1
:ekstrak
echo Mengekstrak file update...
if exist _update_tmp rmdir /S /Q _update_tmp
powershell -NoProfile -Command "Expand-Archive -Path 'a3motor-update.zip' -DestinationPath '_update_tmp' -Force"
set SRC=
if exist _update_tmp\bengkelmotor-pos-main set SRC=_update_tmp\bengkelmotor-pos-main
if exist _update_tmp\a3motor-offline set SRC=_update_tmp\a3motor-offline
if "%SRC%"=="" (
  echo Isi file update tidak sesuai. Update dibatalkan, tidak ada yang berubah.
  pause
  exit /b 1
)
echo .wrangler\ > _exclude.txt
echo Memperbarui file aplikasi (database .wrangler TIDAK disentuh, data aman)...
xcopy /E /Y /Q "%SRC%" "." /EXCLUDE:_exclude.txt
echo Menyegarkan komponen (butuh internet sebentar)...
call npm install
echo Menyiapkan database lokal...
call npx wrangler d1 execute a3motor-db --local --file=schema.sql >nul 2>nul
rmdir /S /Q _update_tmp
del _exclude.txt
del a3motor-update.zip
echo.
echo Update selesai. Jalankan start.bat untuk membuka kasir.
pause
