import React from 'react';
import { ArrowRight } from 'lucide-react';
import { NAV_CARDS } from './consolePanelCopy';
import ConsoleNavIcon, { quickNavAccentClass } from './ConsoleNavIcons';
import type { ConsolePaneId } from './consolePanelCopy';

interface ConsoleActionCardsProps {
  activePane: ConsolePaneId;
  onNavigate: (pane: ConsolePaneId) => void;
}

const CARD_DETAILS: Record<string, { description: string; hint: string }> = {
  'hizmet-al': {
    description: 'Karşı tarafın üye numarasıyla yazılı sözleşme başlat. Rolünü seç, şartları kilitle.',
    hint: 'Alıcı veya satıcı',
  },
  sozlesmelerim: {
    description: 'Devam eden, onay bekleyen ve tamamlanan tüm sözleşmelerin tek listede.',
    hint: 'Onay · itiraz · arşiv',
  },
};

export default function ConsoleActionCards({ activePane, onNavigate }: ConsoleActionCardsProps) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4" role="group" aria-label="Konsol kısayolları">
      {NAV_CARDS.map((card) => {
        const isActive = activePane === card.id;
        const details = CARD_DETAILS[card.id];
        return (
          <button
            key={card.id}
            type="button"
            onClick={() => onNavigate(card.id)}
            aria-label={`${card.label}: ${details.description}`}
            className={`group relative overflow-hidden rounded-2xl p-5 sm:p-6 min-h-[148px] text-left border transition-all duration-300 cursor-pointer flex flex-col justify-between gap-4 ${quickNavAccentClass(
              card.icon,
              isActive,
            )}`}
          >
            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity bg-gradient-to-br from-white/[0.03] to-transparent pointer-events-none" />
            <div className="relative flex items-start justify-between gap-3">
              <ConsoleNavIcon id={card.icon} active={isActive} size="md" />
              <span className="text-[10px] font-mono uppercase tracking-wider text-slate-500">{details.hint}</span>
            </div>
            <div className="relative min-w-0">
              <h3 className="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                {card.label}
                <ArrowRight className="h-4 w-4 -translate-x-1 text-slate-400 opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" aria-hidden />
              </h3>
              <p className="text-[11px] sm:text-xs text-zinc-400 mt-2 leading-relaxed line-clamp-2">
                {details.description}
              </p>
            </div>
          </button>
        );
      })}
    </div>
  );
}
