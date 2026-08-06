import React from 'react';
import { Info } from 'lucide-react';
import type { PanelGuide } from './consolePanelCopy';

interface ConsolePanelIntroProps {
  guide: PanelGuide;
  compact?: boolean;
}

export default function ConsolePanelIntro({ guide, compact }: ConsolePanelIntroProps) {
  if (compact) {
    return (
      <div className="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-4 space-y-2 text-left">
        <p className="text-xs text-zinc-300 leading-relaxed">{guide.purpose}</p>
        <p className="text-[10px] font-mono text-purple-300/90 border-t border-zinc-800 pt-2">{guide.protocolNote}</p>
      </div>
    );
  }

  return (
    <div className="rounded-2xl border border-zinc-800 bg-gradient-to-br from-zinc-900/50 to-[#09090e] p-5 md:p-6 text-left space-y-4">
      <div className="flex items-start gap-3">
        <div className="h-9 w-9 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
          <Info className="h-4 w-4 text-purple-300" />
        </div>
        <p className="text-xs text-zinc-300 leading-relaxed pt-1.5">{guide.purpose}</p>
      </div>

      <div className="space-y-2">
        <span className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider">Ne yapmalısın?</span>
        <ol className="space-y-1.5 list-decimal list-inside text-xs text-zinc-400">
          {guide.steps.map((step) => (
            <li key={step} className="leading-relaxed">{step}</li>
          ))}
        </ol>
      </div>

      <div className="rounded-xl bg-purple-500/5 border border-purple-500/15 px-3 py-2.5">
        <span className="text-[9px] font-mono text-purple-400 font-bold uppercase tracking-wider block mb-1">Bilmen gereken</span>
        <p className="text-[11px] text-zinc-400 leading-relaxed">{guide.protocolNote}</p>
      </div>
    </div>
  );
}
