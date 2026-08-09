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
php api/scripts/run-intelligence-regression.php   # intelligence_regression_slice PASS → intelligence-regression.json
npm run build
python scripts/deploy_live.py
```

Deploy gate (`require_intelligence_regression_pass`) şunları doğrular: `overall: PASS`, taze `generated_at`, tüm section'lar PASS, git HEAD eşleşmesi (repo varsa). Tüm `scripts/deploy_*.py` girişleri ve `npm run deploy:firebase:app` bu gate'i kullanır.

Bypass (acil): `ZINESH_SKIP_REGRESSION_GATE=1`

**Not:** `intelligence_regression_slice PASS` tam trust stack doğrulaması değildir; bkz. `docs/INTELLIGENCE_OPS.md`.

## Repo

https://github.com/cromles/zinesh-protocol
