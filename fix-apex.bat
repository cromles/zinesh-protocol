@echo off
title Zinesh — Apex yonlendirme duzeltmesi
echo.
echo Sorun: zinesh.com (www'siz) eski Shopify kaydina gidiyor.
echo Cozum: Cloudflare'de zinesh.com -^> www.zinesh.com yonlendirmesi.
echo.

if defined CLOUDFLARE_API_TOKEN goto run
if defined CF_API_TOKEN (
  set CLOUDFLARE_API_TOKEN=%CF_API_TOKEN%
  goto run
)

echo Cloudflare API token gerekli.
echo.
echo 1) https://dash.cloudflare.com/profile/api-tokens
echo 2) Create Token - Zone icin Rulesets Edit + Zone Read
echo 3) Asagiya yapistir (ekranda gorunmez):
echo.
set /p CLOUDFLARE_API_TOKEN=API Token: 
if "%CLOUDFLARE_API_TOKEN%"=="" (
  echo Token bos. Cikiliyor.
  pause
  exit /b 1
)

:run
cd /d "%~dp0"
node scripts/fix-cloudflare-apex-redirect.mjs
echo.
pause
