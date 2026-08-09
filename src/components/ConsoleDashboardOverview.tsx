import React, { useCallback, useEffect, useState } from 'react';
import {
  Bell,
  FolderOpen,
  Handshake,
  HelpCircle,
  Rocket,
  ShieldCheck,
  Wallet,
} from 'lucide-react';
import { formatMoney } from '../lib/currencyFormat';
import { fetchEscrowRooms } from '../lib/escrowRoomApi';
import { CONSOLE_POLL_LIST_MS } from '../lib/consolePoll';
import {
  computeDashboardMetrics,
  sortActiveEscrowRooms,
  type ConsoleDashboardMetrics,
} from '../lib/consoleDashboardMetrics';
import type { EscrowRoom } from '../lib/escrowRoomApi';
import type { ConsolePaneId } from './consolePanelCopy';
import ConsoleActiveDealsList from './ConsoleActiveDealsList';
import ConsoleSecureProcessCard from './ConsoleSecureProcessCard';

interface ConsoleDashboardOverviewProps {
  userName: string;
  availableBalance: number;
  onNavigate: (pane: ConsolePaneId) => void;
  onOpenRoom: (roomId: string) => void;
  onOpenNotifications: () => void;
}

type MetricCard = {
  key: keyof ConsoleDashboardMetrics;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  format: (value: number) => string;
};

const METRIC_CARDS: MetricCard[] = [
  {
    key: 'totalAgreements',
    label: 'Toplam Anlaşma',
    icon: Handshake,
    accent: 'from-emerald-500/20 to-emerald-950/40 border-emerald-500/25 text-emerald-300',
    format: (v) => String(v),
  },
  {
    key: 'activeAgreements',
    label: 'Aktif Anlaşma',
    icon: FolderOpen,
    accent: 'from-sky-500/20 to-sky-950/40 border-sky-500/25 text-sky-300',
    format: (v) => String(v),
  },
  {
    key: 'completedAgreements',
    label: 'Tamamlanan',
    icon: ShieldCheck,
    accent: 'from-violet-500/20 to-violet-950/40 border-violet-500/25 text-violet-300',
    format: (v) => String(v),
  },
  {
    key: 'totalVolumeTry',
    label: 'Toplam Hacim',
    icon: Wallet,
    accent: 'from-amber-500/20 to-amber-950/40 border-amber-500/25 text-amber-300',
    format: (v) => formatMoney(v),
  },
];

type QuickAction = {
  pane: ConsolePaneId;
  title: string;
  shortTitle?: string;
  description: string;
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  mobileOnly?: boolean;
  desktopOnly?: boolean;
  onClick?: () => void;
};

const QUICK_ACTIONS: QuickAction[] = [
  {
    pane: 'hizmet-al',
    title: 'Yeni Anlaşma Başlat',
    shortTitle: 'Yeni Anlaşma',
    description: "Karşı tarafın ZN-ID'si ile yeni bir emanet anlaşması başlatın.",
    icon: Rocket,
    accent: 'border-emerald-500/30 bg-gradient-to-br from-emerald-500/15 via-emerald-950/30 to-slate-950 hover:border-emerald-400/50',
  },
  {
    pane: 'sozlesmelerim',
    title: 'Anlaşmalarım',
    description: 'Tüm anlaşmalarınızı görüntüleyin ve yönetin.',
    icon: FolderOpen,
    accent: 'border-sky-500/30 bg-gradient-to-br from-sky-500/15 via-sky-950/30 to-slate-950 hover:border-sky-400/50',
  },
  {
    pane: 'cuzdan',
    title: 'Bakiye Yükle',
    shortTitle: 'Cüzdan',
    description: 'Hesabınıza bakiye ekleyin ve işlemlere başlayın.',
    icon: Wallet,
    accent: 'border-violet-500/30 bg-gradient-to-br from-violet-500/15 via-violet-950/30 to-slate-950 hover:border-violet-400/50',
  },
  {
    pane: 'bildirimler',
    title: 'Bildirimler',
    description: 'Anlaşma ve süreç bildirimlerinizi görüntüleyin.',
    icon: Bell,
    accent: 'border-sky-500/30 bg-gradient-to-br from-sky-500/12 via-slate-950 to-slate-950 hover:border-sky-400/50',
    mobileOnly: true,
  },
  {
    pane: 'dashboard',
    title: 'Nasıl Çalışır?',
    description: 'Zinesh güvenli emanet sürecini adım adım öğrenin.',
    icon: HelpCircle,
    accent: 'border-amber-500/30 bg-gradient-to-br from-amber-500/15 via-amber-950/30 to-slate-950 hover:border-amber-400/50',
    desktopOnly: true,
  },
];

function QuickActionButton({
  action,
  compact,
  onActivate,
}: {
  action: QuickAction;
  compact?: boolean;
  onActivate: () => void;
}) {
  const Icon = action.icon;
  const label = compact && action.shortTitle ? action.shortTitle : action.title;
  return (
    <button
      type="button"
      onClick={onActivate}
      className={`group flex min-h-[88px] flex-col justify-between rounded-2xl border p-3 text-left transition sm:min-h-[148px] sm:p-5 ${action.accent}`}
    >
      <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-950/50 text-white sm:h-11 sm:w-11">
        <Icon className="h-4 w-4 sm:h-5 sm:w-5" aria-hidden />
      </span>
      <div className="mt-2 min-w-0 sm:mt-4">
        <h3 className="text-sm font-bold leading-snug text-white sm:text-base">{label}</h3>
        <p className="mt-1 hidden text-xs leading-relaxed text-slate-400 sm:block">{action.description}</p>
      </div>
    </button>
  );
}

export default function ConsoleDashboardOverview({
  userName,
  availableBalance,
  onNavigate,
  onOpenRoom,
  onOpenNotifications,
}: ConsoleDashboardOverviewProps) {
  const [rooms, setRooms] = useState<EscrowRoom[]>([]);
  const [loading, setLoading] = useState(true);

  const loadRooms = useCallback(async () => {
    try {
      const list = await fetchEscrowRooms();
      setRooms(list);
    } catch {
      /* sessiz — metrikler 0 kalır */
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadRooms();
    const timer = window.setInterval(() => void loadRooms(), CONSOLE_POLL_LIST_MS);
    return () => window.clearInterval(timer);
  }, [loadRooms]);

  const metrics = computeDashboardMetrics(rooms);
  const activeRooms = sortActiveEscrowRooms(rooms);

  const scrollToProcess = () => {
    document.getElementById('console-secure-process')?.scrollIntoView({ behavior: 'smooth' });
  };

  const activateAction = (action: QuickAction) => {
    if (action.title === 'Nasıl Çalışır?') {
      scrollToProcess();
      return;
    }
    if (action.pane === 'bildirimler') {
      onOpenNotifications();
      return;
    }
    onNavigate(action.pane);
  };

  const mobileActions = QUICK_ACTIONS.filter((a) => !a.desktopOnly);
  const desktopActions = QUICK_ACTIONS.filter((a) => !a.mobileOnly);

  return (
    <div id="console-dashboard" className="console-scroll-target min-w-0 space-y-4 sm:space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div className="min-w-0 space-y-1">
          <p className="hidden text-[10px] font-mono font-semibold uppercase tracking-wider text-emerald-400/90 sm:block">
            Genel Bakış
          </p>
          <h1 className="font-display text-lg font-black leading-tight text-white sm:text-2xl">
            Merhaba, <span className="break-words">{userName}</span>{' '}
            <span aria-hidden>👋</span>
          </h1>
          <p className="text-xs text-slate-400 sm:text-sm">Güvenli anlaşmalar, şeffaf süreçler.</p>
        </div>

        <div className="hidden shrink-0 items-center gap-2 lg:flex">
          <button
            type="button"
            onClick={onOpenNotifications}
            className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-800 bg-slate-900/80 text-slate-300 transition hover:border-slate-700 hover:text-white"
            aria-label="Bildirimler"
          >
            <Bell className="h-4 w-4" aria-hidden />
          </button>
          <span className="inline-flex items-center gap-2 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-3 py-2 text-xs font-semibold text-emerald-200">
            <span className="h-2 w-2 rounded-full bg-emerald-400" aria-hidden />
            Sistem Güvenli
          </span>
        </div>
      </header>

      {/* Mobil: bakiye + güven durumu */}
      <div className="flex min-w-0 flex-col gap-2 rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 via-slate-950 to-slate-950 p-4 lg:hidden">
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="text-[10px] font-mono uppercase tracking-wider text-slate-400">Kullanılabilir bakiye</p>
            <p className="mt-1 text-xl font-black tabular-nums text-emerald-300">{formatMoney(availableBalance)}</p>
          </div>
          <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-[10px] font-semibold text-emerald-200">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden />
            Güvenli
          </span>
        </div>
      </div>

      <section aria-labelledby="quick-actions-heading" className="min-w-0">
        <h2 id="quick-actions-heading" className="mb-2 text-sm font-bold text-white sm:mb-3">
          Hızlı İşlemler
        </h2>
        <div className="grid grid-cols-2 gap-2 sm:hidden">
          {mobileActions.map((action) => (
            <QuickActionButton
              key={action.title}
              action={action}
              compact
              onActivate={() => activateAction(action)}
            />
          ))}
        </div>
        <div className="hidden gap-3 sm:grid sm:grid-cols-2 xl:grid-cols-4">
          {desktopActions.map((action) => (
            <QuickActionButton
              key={action.title}
              action={action}
              onActivate={() => activateAction(action)}
            />
          ))}
        </div>
      </section>

      <div className="hidden grid-cols-2 gap-3 lg:grid lg:grid-cols-4">
        {METRIC_CARDS.map((card) => {
          const Icon = card.icon;
          const value = metrics[card.key];
          return (
            <div
              key={card.key}
              className={`rounded-2xl border bg-gradient-to-br p-4 ${card.accent}`}
            >
              <div className="flex items-start justify-between gap-2">
                <p className="text-[10px] font-mono uppercase tracking-wider text-slate-400">
                  {card.label}
                </p>
                <Icon className="h-4 w-4 opacity-80" aria-hidden />
              </div>
              <p className="mt-3 text-xl font-black tabular-nums text-white sm:text-2xl">
                {loading && rooms.length === 0 ? '—' : card.format(value)}
              </p>
            </div>
          );
        })}
      </div>

      <div className="grid min-w-0 grid-cols-1 gap-4 sm:gap-5 xl:grid-cols-3">
        <div className="min-w-0 xl:col-span-2">
          <ConsoleActiveDealsList
            rooms={activeRooms}
            loading={loading}
            onOpenRoom={onOpenRoom}
            onViewAll={() => onNavigate('sozlesmelerim')}
          />
        </div>
        <div id="console-secure-process" className="min-w-0">
          <ConsoleSecureProcessCard />
        </div>
      </div>
    </div>
  );
}
