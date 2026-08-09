import React, { useCallback, useEffect, useState } from 'react';
import { Activity, RefreshCw, Users, Wallet, ShieldCheck } from 'lucide-react';
import { fetchFounderPlatformStats, type FounderPlatformStats } from '../lib/founderPlatformApi';
import { formatMoney } from '../lib/currencyFormat';

function StatCard({
  label,
  value,
  sub,
  accent = 'text-white',
  icon,
}: {
  label: string;
  value: string;
  sub?: string;
  accent?: string;
  icon: React.ReactNode;
}) {
  return (
    <div className="rounded-xl border border-amber-500/15 bg-[rgba(255,255,255,0.015)] p-3 text-left">
      <div className="flex items-center gap-2 mb-1.5">
        <div className="h-7 w-7 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
          {icon}
        </div>
        <span className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider leading-tight">{label}</span>
      </div>
      <p className={`font-display text-lg font-black ${accent}`}>{value}</p>
      {sub && <p className="text-[10px] text-zinc-400 mt-0.5 leading-snug">{sub}</p>}
    </div>
  );
}

export default function FounderPlatformPanel() {
  const [stats, setStats] = useState<FounderPlatformStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    else setRefreshing(true);
    setError(null);
    try {
      const data = await fetchFounderPlatformStats();
      setStats(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Veri alınamadı');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    load();
    const id = window.setInterval(() => load(true), 30000);
    return () => window.clearInterval(id);
  }, [load]);

  const updatedLabel = stats?.updatedAt
    ? new Date(stats.updatedAt).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : null;

  return (
    <div className="rounded-2xl border border-amber-500/25 bg-gradient-to-br from-amber-500/[0.06] to-orange-500/[0.03] p-4 sm:p-5 text-left">
      <div className="flex items-start justify-between gap-3 mb-4">
        <div>
          <p className="font-mono text-[10px] text-amber-400 uppercase tracking-widest font-bold mb-0.5">
            Protokol Özeti
          </p>
          <h3 className="font-display text-base font-bold text-white">Canlı platform istatistikleri</h3>
          {updatedLabel && (
            <p className="text-[10px] text-zinc-400 mt-1">
              Son güncelleme {updatedLabel} · 30 sn&apos;de bir yenilenir
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => load(true)}
          disabled={refreshing}
          className="touch-target shrink-0 p-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-200 transition cursor-pointer disabled:opacity-50"
          title="Yenile"
        >
          <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {loading && !stats && (
        <p className="text-sm text-zinc-400 animate-pulse py-6 text-center">Platform verisi yükleniyor…</p>
      )}

      {error && !stats && (
        <div className="space-y-2 py-4">
          <p className="text-sm text-rose-300">{error}</p>
          <button
            type="button"
            onClick={() => load()}
            className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-amber-600/90 hover:bg-amber-500 text-white"
          >
            Tekrar dene
          </button>
        </div>
      )}

      {stats && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <StatCard
              label="Toplam kullanıcı"
              value={String(stats.users.total)}
              sub={`${stats.users.emailVerified} e-posta · ${stats.users.kycApproved} KYC`}
              icon={<Users className="h-3.5 w-3.5 text-sky-400" />}
            />
            <StatCard
              label="Aktif oturum"
              value={String(stats.users.activeSessions)}
              sub={`${stats.users.foundingMembers} erken kayıt`}
              accent="text-emerald-400"
              icon={<Activity className="h-3.5 w-3.5 text-emerald-400" />}
            />
            <StatCard
              label="Toplam yatırım"
              value={formatMoney(stats.balances.totalDeposits)}
              sub={`Emanet kilitli: ${formatMoney(stats.balances.totalEscrowLocked)}`}
              accent="text-amber-300"
              icon={<Wallet className="h-3.5 w-3.5 text-amber-400" />}
            />
            <StatCard
              label="Kullanılabilir rezerv"
              value={formatMoney(stats.balances.availableReserve)}
              sub={`Kullanıcı borcu: ${formatMoney(stats.balances.userLiabilities)}`}
              accent="text-emerald-300"
              icon={<ShieldCheck className="h-3.5 w-3.5 text-emerald-400" />}
            />
          </div>

          <div className="rounded-xl border border-zinc-800/80 bg-[#09090e]/80 p-3 sm:p-4">
            <p className="font-mono text-[9px] text-zinc-400 uppercase tracking-wider mb-2">Kampanya havuzu</p>
            <p className="text-sm text-white font-mono">
              {formatMoney(stats.campaign.remaining)} / {formatMoney(stats.campaign.total)}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
