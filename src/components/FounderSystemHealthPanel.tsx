import React, { useCallback, useEffect, useState } from 'react';
import {
  Cloud,
  HardDrive,
  RefreshCw,
  Server,
  ShieldCheck,
  Activity,
  Gauge,
} from 'lucide-react';
import {
  fetchFounderSystemHealth,
  fileHealthLabel,
  type FounderFileHealth,
  type FounderOverallHealth,
  type FounderSystemHealth,
  type FileHealthStatus,
} from '../lib/founderHealthApi';

const STATUS_ICON: Record<FileHealthStatus, string> = {
  green: '🟢',
  yellow: '🟡',
  red: '🔴',
};

const OVERALL_CARD_CLASS: Record<FileHealthStatus, string> = {
  green: 'border-emerald-500/30 bg-emerald-500/[0.07]',
  yellow: 'border-amber-500/35 bg-amber-500/[0.08]',
  red: 'border-rose-500/35 bg-rose-500/[0.08]',
};

const OVERALL_TEXT_CLASS: Record<FileHealthStatus, string> = {
  green: 'text-emerald-300',
  yellow: 'text-amber-300',
  red: 'text-rose-300',
};

function statusTextClass(status: FileHealthStatus): string {
  if (status === 'green') return 'text-emerald-300';
  if (status === 'yellow') return 'text-amber-300';
  return 'text-rose-300';
}

function FileRow({ item }: { item: FounderFileHealth }) {
  const label = fileHealthLabel(item);
  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-zinc-800/80 bg-zinc-950/50 px-2.5 py-2">
      <span className="font-mono text-[11px] text-zinc-200">{item.file}</span>
      <span className={`text-[11px] shrink-0 text-right ${statusTextClass(item.status)}`}>
        {STATUS_ICON[item.status] ?? '🔴'} {label}
      </span>
    </div>
  );
}

function OverallStatusCard({ overall }: { overall: FounderOverallHealth }) {
  return (
    <div className={`rounded-xl border p-3 sm:p-4 ${OVERALL_CARD_CLASS[overall.status]}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-1 flex items-center gap-1">
            <Gauge className="h-3 w-3 text-amber-400" /> Sistem Durumu
          </p>
          <p className={`text-base font-bold ${OVERALL_TEXT_CLASS[overall.status]}`}>
            {STATUS_ICON[overall.status]} {overall.statusLabel}
          </p>
          <p className="text-sm text-zinc-200 mt-1">
            <strong className="text-white">{overall.passedChecks}</strong>
            {' / '}
            {overall.totalChecks} kontrol başarılı
          </p>
          <p className="text-[10px] text-zinc-500 mt-2">
            Son kontrol:{' '}
            <span className="font-mono text-zinc-400">{overall.checkedAt}</span>
          </p>
        </div>
      </div>
    </div>
  );
}

type FounderSystemHealthPanelProps = {
  sessionToken?: string;
};

export default function FounderSystemHealthPanel({ sessionToken }: FounderSystemHealthPanelProps) {
  const [health, setHealth] = useState<FounderSystemHealth | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    else setRefreshing(true);
    setError(null);
    try {
      const data = await fetchFounderSystemHealth(sessionToken);
      setHealth(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Sistem sağlığı alınamadı.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [sessionToken]);

  useEffect(() => {
    load();
    const id = window.setInterval(() => load(true), 60000);
    return () => window.clearInterval(id);
  }, [load]);

  const updatedLabel = health?.updatedAt
    ? new Date(health.updatedAt).toLocaleTimeString('tr-TR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      })
    : null;

  const overall = health?.overall;

  return (
    <div
      className="rounded-2xl border border-amber-500/25 bg-gradient-to-br from-amber-500/[0.06] to-orange-500/[0.03] p-4 sm:p-5 text-left"
      title="Sadece kurucu tarafından görüntülenebilir."
    >
      <div className="flex items-start justify-between gap-3 mb-4">
        <div>
          <p className="font-mono text-[10px] text-amber-400 uppercase tracking-widest font-bold mb-0.5">
            Sistem Sağlığı
          </p>
          <h3 className="font-display text-base font-bold text-white">Kurucu gözlem paneli</h3>
          {updatedLabel && (
            <p className="text-[10px] text-zinc-400 mt-1">
              Son güncelleme {updatedLabel} · 60 sn&apos;de bir yenilenir
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => load(true)}
          disabled={refreshing}
          title="Sadece kurucu tarafından görüntülenebilir."
          className="touch-target shrink-0 p-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-200 transition cursor-pointer disabled:opacity-50"
        >
          <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {loading && !health && (
        <p className="text-sm text-zinc-400 animate-pulse py-4 text-center">Sistem durumu yükleniyor…</p>
      )}

      {error && !health && (
        <div className="space-y-2 py-4">
          <p className="text-sm text-rose-300">{error}</p>
          {/oturum|session/i.test(error) ? (
            <p className="text-[11px] text-zinc-500">
              Oturum yenileniyor olabilir — sayfayı yenilemeden önce bir kez daha deneyin.
            </p>
          ) : null}
          <button
            type="button"
            onClick={() => load()}
            className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-amber-600/90 hover:bg-amber-500 text-white"
          >
            Tekrar dene
          </button>
        </div>
      )}

      {health && (
        <div className="space-y-4">
          {error && (
            <p className="text-xs text-amber-300/90 rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 py-2">
              Kısmi uyarı: {error}
            </p>
          )}

          {overall && <OverallStatusCard overall={overall} />}

          <div>
            <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-2 flex items-center gap-1">
              <ShieldCheck className="h-3 w-3 text-amber-400" /> Veri dosyaları
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {(health?.files ?? []).map((item) => (
                <FileRow key={item.file} item={item} />
              ))}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="rounded-xl border border-zinc-800/80 bg-[#09090e]/80 p-3">
              <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                <HardDrive className="h-3 w-3 text-sky-400" /> Son başarılı yedek
              </p>
              <p className="text-sm font-bold text-white font-mono">
                {health?.backup.display ?? 'Görüntülenemiyor'}
              </p>
              <p className="text-[10px] text-zinc-500 mt-1">
                Kaynak: {health?.backup.source ?? 'Google Drive backups'}
              </p>
              <p className={`text-[11px] mt-1.5 ${health?.backup.unavailable ? 'text-rose-300' : 'text-emerald-300'}`}>
                {health?.backup.unavailable ? '🔴 Görüntülenemiyor' : '🟢 Sağlıklı'}
              </p>
            </div>

            <div className="rounded-xl border border-zinc-800/80 bg-[#09090e]/80 p-3">
              <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                <Cloud className="h-3 w-3 text-emerald-400" /> Disaster Recovery
              </p>
              <p className="text-sm font-bold text-white">
                {health?.disasterRecovery.provider ?? 'Google Drive'}
              </p>
              <p className={`text-[11px] mt-1 ${(health?.disasterRecovery.connected ?? false) ? 'text-emerald-300' : 'text-rose-300'}`}>
                {(health?.disasterRecovery.connected ?? false) ? (
                  <span>🟢 {health?.disasterRecovery.label ?? 'Bağlı'}</span>
                ) : (
                  <span>🔴 {health?.disasterRecovery.label ?? 'Bağlı değil'}</span>
                )}
              </p>
            </div>
          </div>

          <div className="rounded-xl border border-zinc-800/80 bg-[#09090e]/80 p-3 sm:p-4">
            <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-1">
              <Server className="h-3 w-3 text-amber-400" /> Sunucu
            </p>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
              <span className="text-emerald-300 font-semibold">
                🟢 {health?.server.label ?? 'Çalışıyor'}
              </span>
              {health?.server.uptimeLabel && health.server.uptimeLabel !== 'Bilinmiyor' && (
                <span className="text-zinc-300 flex items-center gap-1">
                  <Activity className="h-3.5 w-3.5 text-zinc-500" />
                  Uptime: <strong className="text-white">{health.server.uptimeLabel}</strong>
                </span>
              )}
            </div>
            <div className="grid grid-cols-2 gap-2 mt-3 text-[10px]">
              <div className="rounded-lg border border-zinc-800 bg-zinc-950/50 px-2.5 py-2">
                <span className="text-[8px] font-mono text-zinc-400 uppercase block mb-0.5">Disk</span>
                <span className={`text-xs font-bold font-mono block ${health?.server.diskUsedPct != null ? 'text-white' : 'text-amber-300'}`}>
                  {health?.server.diskDisplay ??
                    (health?.server.diskUsedPct != null
                      ? `%${health.server.diskUsedPct} dolu`
                      : '🟡 Bilinmiyor')}
                </span>
                <span className="text-[9px] text-zinc-600">
                  {health?.server.diskSubDisplay ??
                    (health?.server.diskFreeGb != null
                      ? `${health.server.diskFreeGb} GB boş`
                      : 'Bilinmiyor')}
                </span>
              </div>
              <div className="rounded-lg border border-zinc-800 bg-zinc-950/50 px-2.5 py-2">
                <span className="text-[8px] font-mono text-zinc-400 uppercase block mb-0.5">Bellek</span>
                <span className={`text-xs font-bold font-mono block ${health?.server.memoryUsedPct != null ? 'text-white' : 'text-amber-300'}`}>
                  {health?.server.memoryDisplay ??
                    (health?.server.memoryUsedPct != null
                      ? `%${health.server.memoryUsedPct} kullanım`
                      : '🟡 Bilinmiyor')}
                </span>
                <span className="text-[9px] text-zinc-600">
                  {health?.server.memorySubDisplay ??
                    (health?.server.memoryUsedMb != null && health?.server.memoryTotalMb != null
                      ? `${health.server.memoryUsedMb} / ${health.server.memoryTotalMb} MB`
                      : 'Bilinmiyor')}
                </span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
