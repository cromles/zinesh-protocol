# AGENTS.md

## Cursor Cloud specific instructions

### What this repo is
Zinesh (`zinesh-production`) — a Turkish-language Web3 trust/escrow marketing site plus a gated user console. The **frontend** is a Vite 6 + React 19 + TypeScript app (Tailwind v4). The **backend** is a framework-less PHP API under `api/*.php` that uses flat-file JSON storage; it is deployed separately (nginx/PHP-FPM in production) and has **no local dev command** in this repo. Firebase/Firestore is a remote managed service whose public web config is committed in `firebase-applet-config.json`.

### Scope of local dev
The supported local development target is the **Vite frontend** (this matches `README.md`, which defines local dev as `npm install` + `npm run dev`). PHP is **not installed** in this environment and Vite only proxies `/api/events.php` to production (see `vite.config.ts`), so the gated console flows that call `/api/auth.php`, `/api/wallet.php`, etc. cannot be exercised end-to-end locally without running PHP and adding a proxy. The public landing site and its interactive UI work fully with the frontend alone.

### Commands (all from repo root)
- Dev server: `npm run dev` → serves on `http://localhost:3000` (port and `--host 0.0.0.0` are hard-coded in the `dev` script).
- **Demo escrow (local):** Terminal 1 → `npm run demo:api` (PHP 8+ required). Terminal 2 → `npm run dev`. Giriş modalında **Demo Employer** / **Demo Worker**; sağlık paneli → `/debug`.
- Build: `npm run build` (runs `scripts/generate-favicons.mjs` then `vite build`; also builds the `teknik-dokuman/` second entry).
- Preview built output: `npm run preview`.
- Lint / typecheck: `npm run lint` (this is `tsc --noEmit`).

### DDoS / origin protection
- Production API is **PHP-FPM** (`api/*.php`) with flat-file JSON storage — there is no Express, Next.js API routes, or Prisma connection pool in this repo.
- IP rate limits: `zinesh_rate_limit()` in `api/security_lib.php` (per-IP temp files) + global `global_api` bucket via `zinesh_security_headers()`.
- Edge/nginx: run `python scripts/fix_apex_nginx.py` after deploy to apply `limit_req`, cache headers, and PHP timeouts on the VPS.
- Tune limits in `api/config.php` → `rate_limits` and `file_lock`.
- `npm run lint` (`tsc --noEmit`) currently reports **pre-existing type errors** on `main` (e.g. in `src/components/AlphaConsole.tsx`, `NotificationCenter.tsx`, `OnboardingChecklist.tsx`). These are not caused by env setup. `vite build` does **not** typecheck, so the build still succeeds despite these errors.
- Do NOT deploy from the cloud agent. `README.md` states deploys are manual and run from a separate directory; the agent should only commit/push and open PRs.
- Secret files are git-ignored and absent by default: `.env` / `.env.production`, `api/config.local.php` (template: `api/config.local.example.php`), `api/secrets.json` (template: `api/secrets.example.json`). They are only needed for backend/email/on-chain features, not for frontend dev.
- `api/scripts/` is a separate optional Node subproject (ethers/tronweb) used by the PHP withdrawal executor; it has its own `package.json` with no lockfile and is not needed for frontend dev.
