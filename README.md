# Zinesh Protocol

Zinesh'in kanonik kaynak kodu: PHP API, Vite + React ön uç ve `dist/` dağıtım snapshot'ları.

Canlı site: https://www.zinesh.com · Konsol: https://app.zinesh.com

## Öne çıkanlar

- TL emanet (escrow) platformu — üye numarası ile eşleşme, sözleşme, cüzdan
- Stack: PHP (flat-file API) + React 19 + Vite 6 + Tailwind v4
- Deploy: `scripts/deploy_live.py` (VPS; `secrets/deploy.local.env` gerekir)

## Hızlı başlangıç

```bash
npm install
npm run dev      # http://localhost:3000
npm run build
npm run lint     # tsc --noEmit
```

## Ortam

- `.env.example` — frontend değişkenleri
- `api/config.local.example.php` — sunucu/API gizlileri (repoda yok)
- `secrets/deploy.local.example.env` — deploy SSH (repoda yok)

## Repoda olmayan dosyalar

`api/config.local.php`, `api/secrets.json`, `api/data/*.json`, `.env`, `secrets/deploy.local.env`

## Deploy

```bash
npm run build
python scripts/deploy_live.py
```

## Repo

https://github.com/cromles/zinesh-protocol
