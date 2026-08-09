import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Bell, Check } from 'lucide-react';
import {
  eslesmeSinyalEylemLabel,
  eslesmeSinyalKey,
  fetchEslesmeSinyaller,
  fetchEslesmeSinyalOkunmamis,
  formatEslesmeSinyalTarih,
  isEslesmeSinyalArsiv,
  markEslesmeSinyalOkundu,
  type EslesmeSinyal,
} from '../lib/eslesmeSinyalApi';
import { CONSOLE_POLL_UNREAD_MS } from '../lib/consolePoll';

const POLL_MS = CONSOLE_POLL_UNREAD_MS;

export interface EslesmeSinyalCenterProps {
  sessionToken?: string;
  /** Belirli bir eşleşme odası; yoksa tüm eşleşmeler */
  eslesmeId?: string | null;
  /** Eylem bekleyen sinyalde buton */
  onEylem?: (sinyal: EslesmeSinyal) => void | Promise<void>;
  /** Kompakt: yalnızca zil (konsol başlığı) */
  compact?: boolean;
}

export default function EslesmeSinyalCenter({
  sessionToken,
  eslesmeId = null,
  onEylem,
  compact = false,
}: EslesmeSinyalCenterProps) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [sinyaller, setSinyaller] = useState<EslesmeSinyal[]>([]);
  const [okunmamis, setOkunmamis] = useState(0);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [showArsiv, setShowArsiv] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);

  const loadFull = useCallback(async () => {
    if (!sessionToken) return;
    try {
      const data = await fetchEslesmeSinyaller(eslesmeId || undefined, 100, 0);
      setSinyaller(data.sinyaller);
      setOkunmamis(data.okunmamis);
      setError('');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sinyaller yüklenemedi.');
    }
  }, [sessionToken, eslesmeId]);

  const loadUnread = useCallback(async () => {
    if (!sessionToken) return;
    try {
      const count = await fetchEslesmeSinyalOkunmamis(eslesmeId || undefined);
      setOkunmamis(count);
      setError('');
    } catch {
      /* sessiz — zil rozeti kritik değil */
    }
  }, [sessionToken, eslesmeId]);

  const loadList = useCallback(async () => {
    if (!sessionToken) return;
    setLoading(true);
    try {
      await loadFull();
    } finally {
      setLoading(false);
    }
  }, [sessionToken, loadFull]);

  useEffect(() => {
    if (!sessionToken) return;
    if (open) {
      void loadFull();
    } else {
      void loadUnread();
    }
    const tick = () => {
      if (document.visibilityState === 'hidden') return;
      if (open) void loadFull();
      else void loadUnread();
    };
    const t = window.setInterval(tick, POLL_MS);
    return () => window.clearInterval(t);
  }, [sessionToken, open, loadFull, loadUnread]);

  useEffect(() => {
    if (open) void loadList();
  }, [open, loadList]);

  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  const handleOkundu = async (s: EslesmeSinyal) => {
    if (s.durum !== 'okunmadi') return;
    const key = eslesmeSinyalKey(s);
    setBusyKey(key);
    try {
      const count = await markEslesmeSinyalOkundu(s);
      setOkunmamis(count);
      setSinyaller((prev) =>
        prev.map((x) =>
          eslesmeSinyalKey(x) === key ? { ...x, durum: 'okundu' as const } : x,
        ),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Okundu işaretlenemedi.');
    } finally {
      setBusyKey(null);
    }
  };

  const handleEylem = async (s: EslesmeSinyal) => {
    if (!onEylem || s.durum !== 'eylem_bekleniyor') return;
    const key = eslesmeSinyalKey(s);
    setBusyKey(key);
    try {
      await onEylem(s);
      await loadFull();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Eylem başarısız.');
    } finally {
      setBusyKey(null);
    }
  };

  if (!sessionToken) return null;

  const aktif = sinyaller.filter((s) => !isEslesmeSinyalArsiv(s));
  const arsiv = sinyaller.filter((s) => isEslesmeSinyalArsiv(s));
  const liste = showArsiv ? arsiv : aktif;
  const hasUnread = okunmamis > 0;

  return (
    <div className={`relative ${compact ? '' : 'w-full'}`} ref={rootRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`min-h-[44px] px-3 sm:px-4 py-2.5 text-xs font-semibold border rounded-full transition cursor-pointer flex items-center gap-2 active:scale-95 bg-zinc-900/50 hover:bg-zinc-800 border-zinc-800 text-zinc-300 ${
          hasUnread ? 'eslesme-sinyal-bell-shake' : ''
        }`}
        title="Eşleşme sinyalleri"
        aria-expanded={open}
        aria-haspopup="dialog"
      >
        <span className="relative inline-flex">
          <Bell className={`w-4 h-4 ${hasUnread ? 'text-red-400' : 'text-zinc-400'}`} />
          {hasUnread && (
            <span
              className="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-red-500"
              aria-hidden
            />
          )}
        </span>
        {!compact && <span>Sinyaller{hasUnread ? ` (${okunmamis})` : ''}</span>}
        {compact && hasUnread && (
          <span className="text-red-400 font-bold">{okunmamis}</span>
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Eşleşme sinyalleri"
          className={`${
            compact
              ? 'fixed left-2 right-2 z-[70] mx-auto w-full max-w-[420px]'
              : 'absolute left-0 right-0 z-40 mt-2 w-full'
          } rounded-2xl border border-zinc-800 bg-[#0a0a10] shadow-2xl shadow-black/50 overflow-hidden box-border`}
          style={
            compact
              ? {
                  top: 'max(5.5rem, calc(env(safe-area-inset-top, 0px) + 4.75rem))',
                }
              : undefined
          }
        >
          <div className="px-4 py-3 border-b border-zinc-900 flex items-center justify-between gap-3">
            <h3 className="text-sm font-semibold text-zinc-100">Eşleşme sinyalleri</h3>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setShowArsiv(false)}
                className={`text-[10px] font-semibold px-2 py-1 rounded ${
                  !showArsiv ? 'text-zinc-100 bg-zinc-800' : 'text-zinc-500'
                }`}
              >
                Aktif
              </button>
              <button
                type="button"
                onClick={() => setShowArsiv(true)}
                className={`text-[10px] font-semibold px-2 py-1 rounded ${
                  showArsiv ? 'text-emerald-300 bg-emerald-950/40' : 'text-zinc-500'
                }`}
              >
                Arşiv ({arsiv.length})
              </button>
            </div>
          </div>

          {error && (
            <p className="mx-4 mt-3 text-xs text-red-400 bg-red-950/30 border border-red-500/20 rounded-lg px-3 py-2">
              {error}
            </p>
          )}

          <div className="max-h-[min(50vh,360px)] overflow-y-auto">
            {loading ? (
              <p className="px-4 py-8 text-center text-xs text-zinc-400">Yükleniyor…</p>
            ) : liste.length === 0 ? (
              <p className="px-4 py-8 text-center text-xs text-zinc-400">
                {showArsiv ? 'Arşivde sinyal yok.' : 'Aktif sinyal yok.'}
              </p>
            ) : (
              <ul className="divide-y divide-zinc-900">
                {liste.map((s) => {
                  const key = eslesmeSinyalKey(s);
                  const eylemLabel = eslesmeSinyalEylemLabel(s.tip);
                  const isEylem = s.durum === 'eylem_bekleniyor';
                  const isOkunmadi = s.durum === 'okunmadi';
                  const isArsivItem = isEslesmeSinyalArsiv(s);

                  if (isEylem) {
                    return (
                      <li
                        key={key}
                        className="px-4 py-3 bg-amber-950/30 border-l-2 border-amber-400"
                      >
                        <p className="text-xs font-semibold text-amber-100">{s.mesaj}</p>
                        <p className="text-[10px] text-amber-500/80 mt-1">
                          {formatEslesmeSinyalTarih(s.timestamp)}
                        </p>
                        {eylemLabel && onEylem && (
                          <button
                            type="button"
                            disabled={busyKey === key}
                            onClick={() => void handleEylem(s)}
                            className="mt-2 min-h-[40px] px-4 py-2 text-xs font-bold rounded-xl bg-amber-500 text-zinc-950 hover:bg-amber-400 disabled:opacity-50 cursor-pointer"
                          >
                            {eylemLabel}
                          </button>
                        )}
                      </li>
                    );
                  }

                  if (isArsivItem) {
                    return (
                      <li key={key} className="px-4 py-3 bg-emerald-950/15">
                        <div className="flex items-start gap-2">
                          <Check className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                          <div>
                            <p className="text-xs font-medium text-emerald-200/90">{s.mesaj}</p>
                            <p className="text-[10px] text-zinc-500 mt-1">
                              {formatEslesmeSinyalTarih(s.timestamp)}
                            </p>
                          </div>
                        </div>
                      </li>
                    );
                  }

                  return (
                    <li
                      key={key}
                      className={`px-4 py-3 ${isOkunmadi ? 'bg-red-950/10' : 'opacity-70'}`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <div className="flex items-center gap-2">
                            {isOkunmadi && (
                              <span
                                className="w-2 h-2 rounded-full bg-red-500 shrink-0"
                                aria-hidden
                              />
                            )}
                            <p
                              className={`text-xs font-medium ${
                                isOkunmadi ? 'text-zinc-100' : 'text-zinc-500'
                              }`}
                            >
                              {s.mesaj}
                            </p>
                          </div>
                          <p className="text-[10px] text-zinc-600 mt-1">
                            {formatEslesmeSinyalTarih(s.timestamp)}
                          </p>
                        </div>
                        {isOkunmadi && (
                          <button
                            type="button"
                            disabled={busyKey === key}
                            onClick={() => void handleOkundu(s)}
                            className="text-[10px] font-semibold text-zinc-400 hover:text-zinc-200 disabled:opacity-50 shrink-0"
                          >
                            Okundu
                          </button>
                        )}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
