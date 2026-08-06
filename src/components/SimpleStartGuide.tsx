import { useState } from 'react';
import { ChevronDown, HelpCircle } from 'lucide-react';
import { PLAIN, SIMPLE_START_STEPS, TWO_WALLETS_EXPLAIN } from '../lib/plainLanguage';
import { ESCROW_ONLY } from '../lib/productMode';

interface SimpleStartGuideProps {
  defaultOpen?: boolean;
  compact?: boolean;
}

export default function SimpleStartGuide({ defaultOpen = true, compact = false }: SimpleStartGuideProps) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div className="rounded-2xl border border-sky-500/25 bg-gradient-to-br from-sky-500/[0.08] to-transparent overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center justify-between gap-3 p-4 sm:p-5 text-left cursor-pointer hover:bg-white/[0.02] transition"
      >
        <div className="flex items-start gap-3 min-w-0">
          <div className="h-9 w-9 rounded-xl bg-sky-500/15 border border-sky-500/30 flex items-center justify-center shrink-0">
            <HelpCircle className="h-4 w-4 text-sky-300" />
          </div>
          <div>
            <p className="font-mono text-[9px] text-sky-400 uppercase tracking-wider font-bold">5 adımda emanet</p>
            <h3 className="text-sm font-bold text-white">Zinesh nasıl çalışır?</h3>
            {!open && !compact && (
              <p className="text-[11px] text-zinc-400 mt-1 line-clamp-1">{TWO_WALLETS_EXPLAIN}</p>
            )}
          </div>
        </div>
        <ChevronDown
          className={`h-4 w-4 text-zinc-500 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && (
        <div className="px-4 pb-4 sm:px-5 sm:pb-5 space-y-4 border-t border-sky-500/10 pt-4">
          <p className="text-xs text-zinc-300 leading-relaxed">{TWO_WALLETS_EXPLAIN}</p>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {SIMPLE_START_STEPS.map((step) => (
              <div
                key={step.title}
                className="rounded-xl border border-white/10 bg-black/20 p-3 text-left"
              >
                <span className="text-lg" aria-hidden>
                  {step.emoji}
                </span>
                <p className="text-sm font-bold text-white mt-2">{step.title}</p>
                <p className="text-[11px] text-zinc-400 mt-1 leading-relaxed">{step.text}</p>
              </div>
            ))}
          </div>

          {!ESCROW_ONLY && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px]">
              <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2">
                <span className="font-bold text-emerald-300">{PLAIN.siteWallet}</span>
                <span className="text-zinc-400"> — {PLAIN.siteWalletHint}</span>
              </div>
              <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 py-2">
                <span className="font-bold text-amber-300">{PLAIN.jobWallet}</span>
                <span className="text-zinc-400"> — {PLAIN.jobWalletHint}</span>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
