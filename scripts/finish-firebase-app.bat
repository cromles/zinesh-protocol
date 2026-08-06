@echo off
cd /d "C:\Users\TOHAT\Desktop\zinesh (2)"
echo.
echo ========================================
echo   ZINESH FIREBASE OTURUM + DEPLOY
echo ========================================
echo.
echo 1) Tarayici acilacak
echo 2) Google hesabinla (Firebase projesine erisen) giris yap
echo 3) Izin ver - sonra kurulum otomatik devam eder
echo.
pause
call npx.cmd firebase-tools login
if errorlevel 1 (
  echo.
  echo GIRIS BASARISIZ - tekrar dene
  pause
  exit /b 1
)
echo.
echo Oturum acildi. Kurulum basliyor...
call npm.cmd run firebase:setup-app
if errorlevel 1 (
  echo SETUP basarisiz olabilir; deploy denenecek...
)
echo.
call npm.cmd run deploy:firebase:app
if errorlevel 1 (
  echo DEPLOY BASARISIZ
  pause
  exit /b 1
)
echo.
echo Auth domain ekleniyor...
call npm.cmd run google:auth-setup
echo.
echo ========================================
echo  FIREBASE DEPLOY TAMAM
echo ========================================
echo Sonraki: Firebase Console - Hosting - app-zinesh
echo Custom domain: app.zinesh.com
echo Cloudflare DNS: app CNAME -^> app-zinesh.web.app (DNS only)
echo.
pause
