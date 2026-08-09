import React from 'react';
import type { QuickNavIconId } from './consolePanelCopy';

const ICON_META: Record<
  QuickNavIconId,
  {
    label: string;
    glow: string;
    ring: string;
    shell: string;
    shellActive: string;
  }
> = {
  'new-contract': {
    label: 'Yeni sözleşme',
    glow: 'from-emerald-500/50 via-teal-500/30 to-emerald-600/40',
    ring: 'ring-emerald-400/50',
    shell: 'from-emerald-600 via-teal-600 to-emerald-800',
    shellActive: 'from-emerald-400 via-teal-400 to-emerald-600',
  },
  'past-contracts': {
    label: 'Eski sözleşmeler',
    glow: 'from-teal-500/45 via-emerald-400/25 to-slate-600/35',
    ring: 'ring-teal-400/50',
    shell: 'from-teal-600 via-emerald-700 to-slate-800',
    shellActive: 'from-teal-400 via-emerald-400 to-teal-500',
  },
  wallet: {
    label: 'Cüzdan',
    glow: 'from-emerald-500/45 via-teal-400/25 to-amber-500/35',
    ring: 'ring-emerald-400/50',
    shell: 'from-emerald-600 via-teal-600 to-emerald-800',
    shellActive: 'from-emerald-400 via-teal-400 to-emerald-600',
  },
};

function NewContractGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" aria-hidden>
      <path
        d="M7 3h7l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"
        fill="rgba(255,255,255,0.95)"
      />
      <path d="M14 3v4h4" fill="rgba(255,255,255,0.35)" />
      <path
        d="M8.5 12.5h7M8.5 15h5.5M8.5 17.5h4"
        stroke="#6d28d9"
        strokeWidth="1.35"
        strokeLinecap="round"
      />
      <circle cx="17.5" cy="6.5" r="3.2" fill="#fbbf24" stroke="#fff" strokeWidth="0.9" />
      <path d="M16.4 6.5h2.2M17.5 5.4v2.2" stroke="#78350f" strokeWidth="0.95" strokeLinecap="round" />
    </svg>
  );
}

function PastContractsGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" aria-hidden>
      <path
        d="M4.5 8.5A1.5 1.5 0 0 1 6 7h9.2l3.3 3.3V18a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 18V8.5Z"
        fill="rgba(255,255,255,0.88)"
      />
      <path d="M15.2 7v3.3H18.5" fill="rgba(255,255,255,0.35)" />
      <path
        d="M7.5 11.5h8M7.5 14h6M7.5 16.5h4.5"
        stroke="#0369a1"
        strokeWidth="1.2"
        strokeLinecap="round"
      />
      <rect x="5.5" y="4.5" width="8.5" height="5.5" rx="1" fill="rgba(255,255,255,0.55)" />
      <rect x="7" y="3" width="8.5" height="5.5" rx="1" fill="rgba(255,255,255,0.78)" />
      <circle cx="17.8" cy="16.2" r="3" fill="#38bdf8" stroke="#fff" strokeWidth="0.85" />
      <path
        d="M17.8 14.7v3M16.3 16.2h3"
        stroke="#0c4a6e"
        strokeWidth="0.9"
        strokeLinecap="round"
      />
    </svg>
  );
}

function WalletGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" aria-hidden>
      <path
        d="M4.5 7.5A2 2 0 0 1 6.5 5.5h9a2 2 0 0 1 2 2v1.2H7.2A2.2 2.2 0 0 0 5 10.9v7.6A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-4.2"
        fill="rgba(255,255,255,0.92)"
      />
      <path
        d="M7.2 8.7h12.3a1.5 1.5 0 0 1 1.5 1.5v5.3a1.5 1.5 0 0 1-1.5 1.5H7.2A2.2 2.2 0 0 1 5 14.8V10.9a2.2 2.2 0 0 1 2.2-2.2Z"
        fill="rgba(255,255,255,0.72)"
      />
      <circle cx="16.8" cy="12.6" r="1.35" fill="#fbbf24" stroke="#fff" strokeWidth="0.7" />
      <path
        d="M8.5 14.2h3.8"
        stroke="#047857"
        strokeWidth="1.2"
        strokeLinecap="round"
      />
      <circle cx="18.2" cy="8.2" r="2.1" fill="#fde68a" stroke="#fff" strokeWidth="0.75" />
      <path d="M17.3 8.2h1.8" stroke="#92400e" strokeWidth="0.8" strokeLinecap="round" />
    </svg>
  );
}

const GLYPHS: Record<QuickNavIconId, () => React.JSX.Element> = {
  'new-contract': NewContractGlyph,
  'past-contracts': PastContractsGlyph,
  wallet: WalletGlyph,
};

interface ConsoleNavIconProps {
  id: QuickNavIconId;
  active?: boolean;
  size?: 'sm' | 'md';
}

export default function ConsoleNavIcon({ id, active = false, size = 'sm' }: ConsoleNavIconProps) {
  const meta = ICON_META[id];
  const Glyph = GLYPHS[id];
  const box = size === 'md' ? 'h-11 w-11 rounded-2xl' : 'h-9 w-9 rounded-xl';

  return (
    <span className={`relative inline-flex shrink-0 ${box}`} aria-hidden>
      <span
        className={`absolute inset-0 bg-gradient-to-br ${meta.glow} blur-md scale-110 transition-opacity duration-300 ${
          active ? 'opacity-90' : 'opacity-35'
        }`}
      />
      <span
        className={`relative flex h-full w-full items-center justify-center bg-gradient-to-br shadow-lg transition-all duration-300 ${
          active ? `${meta.shellActive} ${meta.ring} ring-2 scale-[1.03]` : meta.shell
        } ${size === 'md' ? 'rounded-2xl' : 'rounded-xl'}`}
      >
        <Glyph />
      </span>
    </span>
  );
}

export function quickNavAccentClass(id: QuickNavIconId, active: boolean): string {
  if (!active) return 'bg-zinc-900/70 border-zinc-700/80 text-zinc-200 hover:border-zinc-600';
  switch (id) {
    case 'new-contract':
      return 'bg-violet-500/12 border-violet-400/45 text-violet-100 shadow-[0_0_24px_rgba(139,92,246,0.18)]';
    case 'past-contracts':
      return 'bg-sky-500/12 border-sky-400/45 text-sky-100 shadow-[0_0_24px_rgba(56,189,248,0.16)]';
    case 'wallet':
      return 'bg-emerald-500/12 border-emerald-400/45 text-emerald-100 shadow-[0_0_24px_rgba(52,211,153,0.16)]';
    default:
      return 'bg-amber-500/15 border-amber-500/45 text-amber-100';
  }
}
