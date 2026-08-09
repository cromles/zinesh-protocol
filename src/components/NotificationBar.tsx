import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  Bell,
  LayoutDashboard,
  Lock,
  Menu,
  CheckCircle2,
  XCircle,
  Scale,
  LogOut,
  User,
} from 'lucide-react';
import ZineshLogo from './ZineshLogo';
import { displayMemberTicket } from '../lib/memberTicket';
import { formatMoney } from '../lib/currencyFormat';
import {
  eslesmeSinyalActions,
  eslesmeSinyalIconKind,
  eslesmeSinyalKey,
  fetchEslesmeSinyaller,
  fetchEslesmeSinyalOkunmamis,
  formatEslesmeSinyalRelative,
  isEslesmeSinyalArsiv,
  markAllEslesmeSinyalOkundu,
  markEslesmeSinyalOkundu,
  type EslesmeSinyal,
  type SinyalActionLabel,
  type SinyalIconKind,
} from '../lib/eslesmeSinyalApi';
import { CONSOLE_POLL_UNREAD_MS } from '../lib/consolePoll';

const BAR_HEIGHT = 56;
const PAGE_SIZE = 10;
const POLL_MS = CONSOLE_POLL_UNREAD_MS;
const SHAKE_MS = 3000;

export interface NotificationBarProps {
  sessionToken?: string;
  userName?: string;
  userTicket?: string;
  userEmail?: string;
  availableBalance?: number;
  onHome: () => void;
  onLogout: () => void;
  onOpenProfile: () => void;
  onOpenDashboard?: () => void;
  onOpenEslesme: (eslesmeId: string) => void;
  onApproveComplete: (eslesmeId: string) => void | Promise<void>;
  onApproveCancel: (eslesmeId: string) => void | Promise<void>;
  /** Mobilde üst bar; masaüstünde sidebar kullanıldığında gizlenir */
  hideBar?: boolean;
  panelOpen?: boolean;
  onPanelOpenChange?: (open: boolean) => void;
  /** Konsol mobil header: hamburger + logo, ana sayfa linki yok */
  consoleMobile?: boolean;
  onOpenMenu?: () => void;
}

function SinyalIcon({ kind }: { kind: SinyalIconKind }) {
  const cls = 'w-4 h-4 shrink-0';
  if (kind === 'lock') return <Lock className={`${cls} text-sky-400`} aria-hidden />;
  if (kind === 'cancel') return <XCircle className={`${cls} text-red-400`} aria-hidden />;
  if (kind === 'arbitrator') return <Scale className={`${cls} text-amber-400`} aria-hidden />;
  return <CheckCircle2 className={`${cls} text-emerald-400`} aria-hidden />;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'Z';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

function badgeLabel(n: number): string {
  if (n <= 0) return '';
  return n > 99 ? '99+' : String(n);
}

export default function NotificationBar({
  sessionToken,
  userName = 'Üye',
  userTicket = '',
  userEmail = '',
  availableBalance,
  onHome,
  onLogout,
  onOpenProfile,
  onOpenDashboard,
  onOpenEslesme,
  onApproveComplete,
  onApproveCancel,
  hideBar = false,
  panelOpen: panelOpenProp,
  onPanelOpenChange,
  consoleMobile = false,
  onOpenMenu,
}: NotificationBarProps) {
  const [panelOpenInternal, setPanelOpenInternal] = useState(false);
  const panelOpen = panelOpenProp ?? panelOpenInternal;
  const setPanelOpen = onPanelOpenChange ?? setPanelOpenInternal;
  const [profileOpen, setProfileOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const [sinyaller, setSinyaller] = useState<EslesmeSinyal[]>([]);
  const [total, setTotal] = useState(0);
  const [okunmamis, setOkunmamis] = useState(0);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [shaking, setShaking] = useState(false);

  const rootRef = useRef<HTMLElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const profileRef = useRef<HTMLDivElement>(null);
  const prevOkunmamis = useRef<number | null>(null);
  const shakeTimer = useRef<ReturnType<typeof window.setTimeout> | undefined>(undefined);

  const triggerShake = useCallback(() => {
    setShaking(true);
    if (shakeTimer.current) window.clearTimeout(shakeTimer.current);
    shakeTimer.current = window.setTimeout(() => setShaking(false), SHAKE_MS);
  }, []);

  const refreshUnread = useCallback(async () => {
    if (!sessionToken) return;
    try {
      const count = await fetchEslesmeSinyalOkunmamis();
      if (prevOkunmamis.current !== null && count > prevOkunmamis.current) {
        triggerShake();
      }
      prevOkunmamis.current = count;
      setOkunmamis(count);
    } catch {
      /* sessiz */
    }
  }, [sessionToken, triggerShake]);

  const loadPage = useCallback(
    async (offset: number, append: boolean) => {
      if (!sessionToken) return;
      if (append) setLoadingMore(true);
      else setLoading(true);
      setError('');
      try {
        const data = await fetchEslesmeSinyaller(undefined, PAGE_SIZE, offset);
        setSinyaller((prev) => (append ? [...prev, ...data.sinyaller] : data.sinyaller));
        setTotal(data.total);
        if (prevOkunmamis.current !== null && data.okunmamis > prevOkunmamis.current) {
          triggerShake();
        }
        prevOkunmamis.current = data.okunmamis;
        setOkunmamis(data.okunmamis);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Sinyaller yüklenemedi.');
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [sessionToken, triggerShake],
  );

  useEffect(() => {
    if (!sessionToken) return;
    void refreshUnread();
    const tick = () => {
      if (document.visibilityState === 'hidden') return;
      void refreshUnread();
    };
    const t = window.setInterval(tick, POLL_MS);
    return () => {
      window.clearInterval(t);
      if (shakeTimer.current) window.clearTimeout(shakeTimer.current);
    };
  }, [sessionToken, refreshUnread]);

  useEffect(() => {
    if (!panelOpen || !sessionToken) return;
    void loadPage(0, false);
  }, [panelOpen, sessionToken, loadPage]);

  useEffect(() => {
    if (!panelOpen && !profileOpen) return;
    const onDocClick = (e: MouseEvent) => {
      const target = e.target as Node;
      if (panelOpen && panelRef.current && !panelRef.current.contains(target)) {
        const bell = rootRef.current?.querySelector('[data-notif-bell]');
        if (!bell?.contains(target)) setPanelOpen(false);
      }
      if (profileOpen && profileRef.current && !profileRef.current.contains(target)) {
        setProfileOpen(false);
      }
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [panelOpen, profileOpen]);

  const handleMarkAllRead = async () => {
    setBusyKey('all');
    try {
      const count = await markAllEslesmeSinyalOkundu();
      setOkunmamis(count);
      prevOkunmamis.current = count;
      setSinyaller((prev) =>
        prev.map((s) => (s.durum === 'okunmadi' ? { ...s, durum: 'okundu' as const } : s)),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Toplu okundu işaretlenemedi.');
    } finally {
      setBusyKey(null);
    }
  };

  const openEslesme = useCallback(
    (id: string) => {
      setPanelOpen(false);
      setProfileOpen(false);
      onOpenEslesme(id);
    },
    [onOpenEslesme],
  );

  const handleAction = async (s: EslesmeSinyal, action: SinyalActionLabel) => {
    const key = eslesmeSinyalKey(s);
    setBusyKey(key);
    try {
      if (action === 'İncele' || action === 'Reddet') {
        if (s.durum === 'okunmadi') {
          try {
            const count = await markEslesmeSinyalOkundu(s);
            setOkunmamis(count);
            prevOkunmamis.current = count;
          } catch {
            /* incele yine açılsın */
          }
        }
        openEslesme(s.eslesme_id);
        return;
      }
      if (action === 'Onayla') {
        if (s.tip === 'is_tamamlandi') {
          await onApproveComplete(s.eslesme_id);
        } else if (s.tip === 'iptal_istegi') {
          await onApproveCancel(s.eslesme_id);
        }
        await loadPage(0, false);
        openEslesme(s.eslesme_id);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Eylem başarısız.');
    } finally {
      setBusyKey(null);
    }
  };

  const hasMore = sinyaller.length < total;
  const unreadBadge = badgeLabel(okunmamis);
  const hasUnread = okunmamis > 0;
  const memberId = displayMemberTicket(userTicket);

  return (
    <>
    {!hideBar && (
    <header
      ref={rootRef}
      className="notification-bar safe-pad-t fixed left-0 right-0 top-0 z-[60] border-b border-slate-800/80 bg-slate-950/95 backdrop-blur-md lg:hidden"
      style={{ height: `calc(${BAR_HEIGHT}px + env(safe-area-inset-top, 0px))` }}
    >
      <div
        className="h-[56px] max-w-7xl mx-auto safe-pad-x flex items-center justify-between gap-3"
        style={{ marginTop: 'env(safe-area-inset-top, 0px)' }}
      >
        {/* Sol: konsol menü veya landing */}
        <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
          {consoleMobile && onOpenMenu ? (
            <>
              <button
                type="button"
                onClick={onOpenMenu}
                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-800 bg-slate-900 text-slate-200"
                aria-label="Menüyü aç"
              >
                <Menu className="h-5 w-5" aria-hidden />
              </button>
              <ZineshLogo size="sm" showText transparentBg pulseGlow={false} interactive={false} className="!h-8 min-w-0" />
            </>
          ) : (
            <>
              <button
                type="button"
                onClick={onHome}
                className="shrink-0 flex items-center cursor-pointer active:scale-95 transition"
                title="Ana sayfa"
                aria-label="Ana sayfa"
              >
                <ZineshLogo size="sm" showText={false} transparentBg pulseGlow={false} interactive={false} className="!h-9 !w-9" />
              </button>
              <button
                type="button"
                onClick={onHome}
                className="shrink-0 text-[11px] sm:text-xs font-semibold text-zinc-400 hover:text-zinc-100 transition cursor-pointer px-1.5 py-1 rounded-lg hover:bg-zinc-900/60"
              >
                Ana Sayfa
              </button>
            </>
          )}
        </div>

        {/* Sağ: zil + avatar */}
        <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
          {!consoleMobile && typeof availableBalance === 'number' && (
            <span
              className="inline-flex items-center rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-[10px] font-semibold tabular-nums text-emerald-200 sm:text-[11px]"
              aria-label={`Bakiye: ${formatMoney(availableBalance)}`}
            >
              {formatMoney(availableBalance)}
            </span>
          )}
          <button
            type="button"
            data-notif-bell
            onClick={() => {
              setProfileOpen(false);
              setPanelOpen((v) => !v);
            }}
              className={`notification-bar-bell-shake relative flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-slate-800 bg-slate-900/70 transition hover:bg-slate-800 ${
              shaking ? 'notification-bar-bell-shake' : ''
            }`}
            title="Bildirimler"
            aria-expanded={panelOpen}
            aria-haspopup="dialog"
            aria-label={hasUnread ? `Bildirimler, ${okunmamis} okunmamış` : 'Bildirimler'}
            disabled={!sessionToken}
          >
            <Bell className={`w-4 h-4 ${hasUnread ? 'text-zinc-100' : 'text-zinc-400'}`} aria-hidden />
            {hasUnread && (
              <span
                className="absolute top-2 right-2 w-2 h-2 rounded-full bg-red-500"
                aria-hidden
              />
            )}
            {unreadBadge && (
              <span className="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-[10px] font-bold text-white flex items-center justify-center leading-none border border-[#06060a]">
                {unreadBadge}
              </span>
            )}
          </button>

          <div className="relative" ref={profileRef}>
            <button
              type="button"
              onClick={() => {
                setPanelOpen(false);
                setProfileOpen((v) => !v);
              }}
              className="touch-target flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-emerald-500/30 bg-gradient-to-br from-emerald-500/20 to-teal-700/20 text-[11px] font-bold text-emerald-100 transition hover:border-emerald-400/50"
              title="Profil menüsü"
              aria-label={`Profil menüsü, ${userName}`}
              aria-expanded={profileOpen}
              aria-haspopup="menu"
            >
              {initials(userName)}
            </button>
            {profileOpen && (
              <div
                role="menu"
                className="absolute right-0 top-[calc(100%+8px)] z-[70] w-56 overflow-hidden rounded-xl border border-slate-800 bg-slate-950 shadow-2xl shadow-black/50"
              >
                <div className="border-b border-slate-800 px-3 py-3">
                  <p className="truncate text-xs font-semibold text-slate-100">{userName}</p>
                  {userEmail && (
                    <p className="mt-0.5 truncate text-[10px] text-slate-500">{userEmail}</p>
                  )}
                  {memberId && (
                    <p className="mt-0.5 truncate font-mono text-[10px] text-emerald-400">ZN-{memberId}</p>
                  )}
                </div>
                <button
                  type="button"
                  role="menuitem"
                  onClick={() => {
                    setProfileOpen(false);
                    onOpenProfile();
                  }}
                  className="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-xs text-slate-300 hover:bg-slate-900"
                >
                  <User className="h-3.5 w-3.5" />
                  Profil / Bilgilerim
                </button>
                {onOpenDashboard && (
                  <button
                    type="button"
                    role="menuitem"
                    onClick={() => {
                      setProfileOpen(false);
                      onOpenDashboard();
                    }}
                    className="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-xs text-slate-300 hover:bg-slate-900"
                  >
                    <LayoutDashboard className="h-3.5 w-3.5" />
                    Genel Bakış
                  </button>
                )}
                <button
                  type="button"
                  role="menuitem"
                  onClick={() => {
                    setProfileOpen(false);
                    onLogout();
                  }}
                  className="w-full flex items-center gap-2 px-3 py-2.5 text-xs text-red-300 hover:bg-red-950/30 cursor-pointer border-t border-zinc-900"
                >
                  <LogOut className="w-3.5 h-3.5" />
                  Çıkış
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
    )}

      {/* Bildirim paneli */}
      {panelOpen && (
        <>
          <div
            className="fixed inset-0 z-[65] bg-black/40 md:bg-transparent"
            aria-hidden
            onClick={() => setPanelOpen(false)}
          />
          <div
            ref={panelRef}
            role="dialog"
            aria-label="Bildirimler"
            className="notification-bar-panel fixed z-[70] flex flex-col bg-[#0a0a10] border border-zinc-800 shadow-2xl shadow-black/50
              max-md:inset-x-0 max-md:bottom-0 max-md:top-auto max-md:h-[min(92vh,640px)] max-md:rounded-t-2xl max-md:border-b-0
              md:top-0 md:right-0 md:bottom-0 md:w-[360px] md:rounded-none md:border-r-0 md:border-t-0 lg:left-[280px]"
          >
            <div className="px-4 py-3 border-b border-zinc-900 flex items-center justify-between gap-3 shrink-0">
              <h2 className="text-sm font-semibold text-zinc-100">Bildirimler</h2>
              <button
                type="button"
                disabled={busyKey === 'all' || okunmamis === 0}
                onClick={() => void handleMarkAllRead()}
                className="text-[11px] font-semibold text-sky-400 hover:text-sky-300 disabled:opacity-40 disabled:cursor-default cursor-pointer"
              >
                Tümünü okundu işaretle
              </button>
            </div>

            {error && (
              <p className="mx-4 mt-3 text-xs text-red-400 bg-red-950/30 border border-red-500/20 rounded-lg px-3 py-2 shrink-0">
                {error}
              </p>
            )}

            <div className="flex-1 overflow-y-auto min-h-0">
              {loading ? (
                <p className="px-4 py-10 text-center text-xs text-zinc-400">Yükleniyor…</p>
              ) : sinyaller.length === 0 ? (
                <p className="px-4 py-10 text-center text-xs text-zinc-400">Henüz sinyal yok.</p>
              ) : (
                <ul className="divide-y divide-zinc-900">
                  {sinyaller.map((s) => {
                    const key = eslesmeSinyalKey(s);
                    const kind = eslesmeSinyalIconKind(s.tip);
                    const actions = eslesmeSinyalActions(s);
                    const isEylem = s.durum === 'eylem_bekleniyor';
                    const isOkunmadi = s.durum === 'okunmadi';
                    const isArsiv = isEslesmeSinyalArsiv(s);

                    if (isEylem) {
                      return (
                        <li
                          key={key}
                          className="px-4 py-3 bg-amber-950/35 border-l-2 border-amber-400"
                        >
                          <div className="flex items-start gap-3">
                            <div className="mt-0.5 w-8 h-8 rounded-lg bg-amber-950/50 border border-amber-500/30 flex items-center justify-center shrink-0">
                              <SinyalIcon kind={kind} />
                            </div>
                            <div className="min-w-0 flex-1">
                              <p className="text-xs font-semibold text-amber-100 leading-relaxed">
                                {s.mesaj}
                              </p>
                              <p className="text-[10px] text-amber-500/80 mt-1">
                                {formatEslesmeSinyalRelative(s.timestamp)}
                              </p>
                              {actions.length > 0 && (
                                <div className="flex flex-wrap gap-2 mt-2">
                                  {actions.map((label) => (
                                    <button
                                      key={label}
                                      type="button"
                                      disabled={busyKey === key}
                                      onClick={() => void handleAction(s, label)}
                                      className={`min-h-[36px] px-3 py-1.5 text-[11px] font-bold rounded-lg cursor-pointer disabled:opacity-50 ${
                                        label === 'Onayla'
                                          ? 'bg-amber-400 text-zinc-950 hover:bg-amber-300'
                                          : label === 'Reddet'
                                            ? 'bg-red-500/15 text-red-300 border border-red-500/30 hover:bg-red-500/25'
                                            : 'bg-zinc-800 text-zinc-300 border border-zinc-700 hover:bg-zinc-700'
                                      }`}
                                    >
                                      {label}
                                    </button>
                                  ))}
                                </div>
                              )}
                            </div>
                          </div>
                        </li>
                      );
                    }

                    if (isArsiv) {
                      return (
                        <li key={key} className="px-4 py-3 bg-emerald-950/15">
                          <div className="flex items-start gap-3">
                            <div className="mt-0.5 w-8 h-8 rounded-lg bg-emerald-950/40 border border-emerald-500/25 flex items-center justify-center shrink-0">
                              <CheckCircle2 className="w-4 h-4 text-emerald-400" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1">
                              <p className="text-xs font-medium text-emerald-200/90 leading-relaxed">
                                {s.mesaj}
                              </p>
                              <p className="text-[10px] text-zinc-500 mt-1">
                                {formatEslesmeSinyalRelative(s.timestamp)}
                              </p>
                              {actions.includes('İncele') && (
                                <button
                                  type="button"
                                  disabled={busyKey === key}
                                  onClick={() => void handleAction(s, 'İncele')}
                                  className="mt-2 min-h-[32px] px-3 py-1.5 text-[11px] font-semibold rounded-lg bg-zinc-800 text-zinc-300 border border-zinc-700 hover:bg-zinc-700 cursor-pointer disabled:opacity-50"
                                >
                                  İncele
                                </button>
                              )}
                            </div>
                          </div>
                        </li>
                      );
                    }

                    return (
                      <li
                        key={key}
                        className={`px-4 py-3 ${isOkunmadi ? 'bg-red-950/15' : 'opacity-70'}`}
                      >
                        <div className="flex items-start gap-3">
                          <div className="relative mt-0.5 w-8 h-8 rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center shrink-0">
                            <SinyalIcon kind={kind} />
                            {isOkunmadi && (
                              <span
                                className="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-red-500"
                                aria-hidden
                              />
                            )}
                          </div>
                          <div className="min-w-0 flex-1">
                            <p
                              className={`text-xs leading-relaxed ${
                                isOkunmadi ? 'text-zinc-100 font-medium' : 'text-zinc-500'
                              }`}
                            >
                              {s.mesaj}
                            </p>
                            <p className="text-[10px] text-zinc-600 mt-1">
                              {formatEslesmeSinyalRelative(s.timestamp)}
                            </p>
                            {actions.length > 0 && (
                              <div className="flex flex-wrap gap-2 mt-2">
                                {actions.map((label) => (
                                  <button
                                    key={label}
                                    type="button"
                                    disabled={busyKey === key}
                                    onClick={() => void handleAction(s, label)}
                                    className="min-h-[32px] px-3 py-1.5 text-[11px] font-semibold rounded-lg bg-zinc-800 text-zinc-300 border border-zinc-700 hover:bg-zinc-700 cursor-pointer disabled:opacity-50"
                                  >
                                    {label}
                                  </button>
                                ))}
                              </div>
                            )}
                          </div>
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>

            {hasMore && (
              <div className="shrink-0 border-t border-zinc-900 p-3">
                <button
                  type="button"
                  disabled={loadingMore}
                  onClick={() => void loadPage(sinyaller.length, true)}
                  className="w-full min-h-[40px] text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900/80 hover:bg-zinc-800 border border-zinc-800 rounded-xl cursor-pointer disabled:opacity-50"
                >
                  {loadingMore ? 'Yükleniyor…' : 'Daha fazla göster'}
                </button>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}
