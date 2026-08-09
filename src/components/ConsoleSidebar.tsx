import React from 'react';
import {
  Bell,
  FolderOpen,
  HelpCircle,
  LayoutDashboard,
  LogOut,
  PlusCircle,
  Settings,
  Wallet,
  X,
} from 'lucide-react';
import ZineshLogo from './ZineshLogo';
import { displayMemberTicket } from '../lib/memberTicket';
import { formatMoney } from '../lib/currencyFormat';
import type { ConsolePaneId } from './consolePanelCopy';

export type ConsoleSidebarNavId = ConsolePaneId | 'destek';

type NavItem = {
  id: ConsoleSidebarNavId;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
};

const NAV_ITEMS: NavItem[] = [
  { id: 'dashboard', label: 'Genel Bakış', icon: LayoutDashboard },
  { id: 'sozlesmelerim', label: 'Anlaşmalarım', icon: FolderOpen },
  { id: 'hizmet-al', label: 'Yeni Anlaşma', icon: PlusCircle },
  { id: 'cuzdan', label: 'Cüzdan', icon: Wallet },
  { id: 'bildirimler', label: 'Bildirimler', icon: Bell },
  { id: 'bilgilerim', label: 'Ayarlar', icon: Settings },
  { id: 'destek', label: 'Destek', icon: HelpCircle },
];

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'Z';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

interface ConsoleSidebarProps {
  activePane: ConsolePaneId;
  userName: string;
  ticketNumber?: string;
  availableBalance: number;
  emailVerified?: boolean;
  kycVerified?: boolean;
  mobileOpen: boolean;
  onCloseMobile: () => void;
  onNavigate: (pane: ConsolePaneId) => void;
  onDeposit: () => void;
  onLogout: () => void;
  onSupport: () => void;
  onHome: () => void;
}

export default function ConsoleSidebar({
  activePane,
  userName,
  ticketNumber,
  availableBalance,
  emailVerified,
  kycVerified,
  mobileOpen,
  onCloseMobile,
  onNavigate,
  onDeposit,
  onLogout,
  onSupport,
  onHome,
}: ConsoleSidebarProps) {
  const memberId = displayMemberTicket(ticketNumber ?? '');
  const verified = Boolean(emailVerified && kycVerified);

  const handleNav = (id: ConsoleSidebarNavId) => {
    if (id === 'destek') {
      onSupport();
      onCloseMobile();
      return;
    }
    onNavigate(id);
    onCloseMobile();
  };

  const sidebarContent = (
    <div className="flex h-full flex-col">
      <div className="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-4">
        <div className="flex min-w-0 items-center gap-2">
          <button
            type="button"
            onClick={() => {
              onHome();
              onCloseMobile();
            }}
            className="shrink-0 cursor-pointer transition active:scale-95"
            title="Ana sayfa"
            aria-label="Ana sayfa"
          >
            <ZineshLogo size="sm" showText transparentBg pulseGlow={false} interactive={false} />
          </button>
          <button
            type="button"
            onClick={() => {
              onHome();
              onCloseMobile();
            }}
            className="shrink-0 rounded-lg px-1.5 py-1 text-xs font-semibold text-slate-400 transition hover:bg-slate-900/60 hover:text-slate-100"
          >
            Anasayfa
          </button>
        </div>
        <button
          type="button"
          onClick={onCloseMobile}
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-800 text-slate-400 lg:hidden"
          aria-label="Menüyü kapat"
        >
          <X className="h-4 w-4" />
        </button>
      </div>

      <div className="space-y-4 p-4">
        <div className="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
          <div className="flex items-center gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-emerald-500/30 to-teal-700/30 text-sm font-bold text-emerald-100">
              {initials(userName)}
            </span>
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-white">{userName}</p>
              {memberId && (
                <p className="font-mono text-[10px] text-emerald-400">ZN-ID: {memberId}</p>
              )}
            </div>
          </div>
          <p className="mt-3 inline-flex items-center gap-1.5 text-[10px] font-semibold text-slate-400">
            <span
              className={`h-1.5 w-1.5 rounded-full ${verified ? 'bg-emerald-400' : 'bg-amber-400'}`}
              aria-hidden
            />
            {verified ? 'Doğrulanmış' : 'Doğrulama bekleniyor'}
          </p>
        </div>

        <div className="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 via-slate-950 to-slate-950 p-4">
          <p className="text-[10px] font-mono uppercase tracking-wider text-slate-400">
            Cüzdan Bakiyesi
          </p>
          <p className="mt-2 text-2xl font-black tabular-nums text-emerald-300">
            {formatMoney(availableBalance)}
          </p>
          <p className="mt-1 text-[10px] text-slate-500">Kullanılabilir bakiye</p>
          <button
            type="button"
            onClick={() => {
              onDeposit();
              onCloseMobile();
            }}
            className="mt-4 w-full rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 py-2.5 text-sm font-bold text-slate-950 transition hover:from-emerald-400 hover:to-teal-400"
          >
            + Bakiye Yükle
          </button>
        </div>
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto px-3 pb-3" aria-label="Konsol menüsü">
        {NAV_ITEMS.map((item) => {
          const Icon = item.icon;
          const isActive = item.id !== 'destek' && activePane === item.id;
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => handleNav(item.id)}
              className={`flex w-full min-h-[44px] items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium transition ${
                isActive
                  ? 'bg-emerald-500/15 text-emerald-200'
                  : 'text-slate-400 hover:bg-slate-900 hover:text-slate-100'
              }`}
            >
              <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-emerald-400' : ''}`} aria-hidden />
              {item.label}
            </button>
          );
        })}
      </nav>

      <div className="border-t border-slate-800 p-3">
        <button
          type="button"
          onClick={onLogout}
          className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-red-300 transition hover:bg-red-950/30"
        >
          <LogOut className="h-4 w-4" aria-hidden />
          Çıkış Yap
        </button>
      </div>
    </div>
  );

  return (
    <>
      {mobileOpen && (
        <button
          type="button"
          className="fixed inset-0 z-40 bg-black/60 lg:hidden"
          aria-label="Menüyü kapat"
          onClick={onCloseMobile}
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-50 w-[min(88vw,280px)] border-r border-slate-800 bg-slate-950 transition-transform duration-300 lg:translate-x-0 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
        aria-label="Konsol kenar çubuğu"
      >
        {sidebarContent}
      </aside>
    </>
  );
}
