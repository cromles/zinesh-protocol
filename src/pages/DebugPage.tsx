import { useCallback, useEffect, useState } from 'react';
import {
  fetchDemoHealth,
  isDemoUiEnabled,
  resetDemoEscrow,
  type DemoHealthReport,
} from '../lib/demoMode';
import { getEscrowDebugSnapshot } from '../lib/escrowDebugTrace';

function StatusRow({
  label,
  value,
  tone = 'neutral',
  detail,
}: {
  label: string;
  value: string;
  tone?: 'ok' | 'fail' | 'warn' | 'neutral';
  detail?: string;
}) {
  const toneClass =
    tone === 'ok'
      ? 'text-emerald-400'
      : tone === 'fail'
        ? 'text-red-400'
        : tone === 'warn'
          ? 'text-amber-300'
          : 'text-zinc-300';
  return (
    <div className="flex items-center justify-between gap-4 rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-3">
      <span className="text-sm text-zinc-300">{label}</span>
      <div className="text-right">
        <span className={`font-semibold text-sm ${toneClass}`}>{value}</span>
        {detail ? <p className="text-[11px] text-zinc-500 mt-0.5">{detail}</p> : null}
      </div>
    </div>
  );
}

export default function DebugPage() {
  const [health, setHealth] = useState<DemoHealthReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [resetting, setResetting] = useState(false);
  const [actionError, setActionError] = useState('');

  const refresh = useCallback(async () => {
    setLoading(true);
    setActionError('');
    const report = await fetchDemoHealth();
    setHealth(report);
    setLoading(false);
  }, []);

  useEffect(() => {
    void refresh();
    const t = window.setInterval(() => void refresh(), 5000);
    return () => window.clearInterval(t);
  }, [refresh]);

  const escrowTrace = getEscrowDebugSnapshot();
  const lastError = health?.lastError ?? escrowTrace.lastError ?? 'None';
  const apiOnline = health?.apiOnline === true;

  if (!isDemoUiEnabled()) {
    return (
      <div className="min-h-screen bg-[#030307] text-zinc-100 flex items-center justify-center p-6">
        <p className="text-sm text-zinc-400">/debug yalnızca demo ortamında kullanılabilir.</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-[#030307] text-zinc-100 p-6">
      <div className="max-w-lg mx-auto space-y-6">
        <div>
          <h1 className="text-xl font-bold text-white">Escrow Health</h1>
          <p className="text-sm text-zinc-500 mt-1">Demo / staging — otomatik yenileme 5 sn</p>
        </div>

        <div className="space-y-2">
          <StatusRow
            label="API"
            value={loading && !health ? '…' : apiOnline ? 'ONLINE' : 'OFFLINE'}
            tone={apiOnline ? 'ok' : 'fail'}
          />
          <StatusRow
            label="Wallet"
            value={
              loading && !health
                ? '…'
                : apiOnline
                  ? health?.checks.wallet.ok
                    ? 'OK'
                    : 'FAIL'
                  : 'UNKNOWN'
            }
            tone={apiOnline && health?.checks.wallet.ok ? 'ok' : apiOnline ? 'fail' : 'warn'}
            detail={health?.checks.wallet.detail}
          />
          <StatusRow
            label="Room"
            value={
              loading && !health
                ? '…'
                : apiOnline
                  ? health?.checks.room.ok
                    ? 'OK'
                    : 'FAIL'
                  : 'UNKNOWN'
            }
            tone={apiOnline && health?.checks.room.ok ? 'ok' : apiOnline ? 'fail' : 'warn'}
            detail={health?.checks.room.detail}
          />
          <StatusRow
            label="Status"
            value={
              loading && !health
                ? '…'
                : apiOnline
                  ? health?.checks.status.ok
                    ? 'OK'
                    : 'FAIL'
                  : 'UNKNOWN'
            }
            tone={apiOnline && health?.checks.status.ok ? 'ok' : apiOnline ? 'fail' : 'warn'}
            detail={health?.checks.status.detail}
          />
          <StatusRow
            label="JSON"
            value={
              loading && !health
                ? '…'
                : apiOnline
                  ? health?.checks.json.ok
                    ? 'OK'
                    : 'FAIL'
                  : 'UNKNOWN'
            }
            tone={apiOnline && health?.checks.json.ok ? 'ok' : apiOnline ? 'fail' : 'warn'}
            detail={health?.checks.json.detail}
          />
          {!apiOnline && health?.reason ? (
            <StatusRow label="Reason" value={health.reason} tone="warn" />
          ) : null}
        </div>

        <div className="rounded-xl border border-zinc-800 bg-zinc-900/40 p-4 space-y-2">
          <p className="text-xs uppercase tracking-wider text-zinc-500 font-bold">Last error</p>
          <p className="text-sm text-white">{lastError}</p>
          {escrowTrace.lastCall ? (
            <p className="text-xs text-zinc-500">
              API: {escrowTrace.lastCall.action} · HTTP {escrowTrace.lastCall.httpStatus} ·{' '}
              {escrowTrace.lastCall.responseSummary}
            </p>
          ) : null}
        </div>

        {!apiOnline ? (
          <p className="text-sm text-amber-300">
            Demo API kapalı. Terminalde <code className="text-amber-100">npm run demo:api</code> çalıştırın,
            ardından yenileyin.
          </p>
        ) : null}

        {actionError ? <p className="text-sm text-red-300">{actionError}</p> : null}

        <div className="flex flex-wrap gap-3">
          <button
            type="button"
            onClick={() => void refresh()}
            className="rounded-full border border-zinc-700 px-4 py-2 text-sm text-zinc-200 hover:bg-zinc-800"
          >
            Yenile
          </button>
          <button
            type="button"
            disabled={resetting || !apiOnline}
            onClick={() => {
              setResetting(true);
              resetDemoEscrow()
                .then(() => refresh())
                .catch((err: Error) => setActionError(err.message))
                .finally(() => setResetting(false));
            }}
            className="rounded-full bg-amber-600/90 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          >
            {resetting ? 'Sıfırlanıyor…' : 'Demo escrow sıfırla'}
          </button>
          <a
            href="/"
            className="rounded-full border border-purple-500/40 px-4 py-2 text-sm text-purple-200 hover:bg-purple-500/10"
          >
            Konsola dön
          </a>
        </div>

        {health?.dataDir ? (
          <p className="text-[11px] text-zinc-600 break-all">data: {health.dataDir}</p>
        ) : null}
      </div>
    </div>
  );
}
