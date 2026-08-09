import React from 'react';
import { Handshake, Lock, Package, CheckCircle2 } from 'lucide-react';
import { CONSOLE_CARD } from '../lib/consoleSkin';

const STEPS = [
  {
    icon: Handshake,
    label: 'Anlaşma',
    description: 'Taraflar dışarıda anlaşır; Zinesh üzerinde ZN-ID ile bağlanırlar.',
    color: 'text-emerald-400',
    bg: 'bg-emerald-500/15 border-emerald-500/30',
  },
  {
    icon: Lock,
    label: 'Emanet',
    description: 'Karşılıklı onay sonrası tutar emanet sürecinde kilitlenir.',
    color: 'text-sky-400',
    bg: 'bg-sky-500/15 border-sky-500/30',
  },
  {
    icon: Package,
    label: 'Teslimat',
    description: 'İş gerçekleştirilir; taraflar konsolda durumu takip eder.',
    color: 'text-violet-400',
    bg: 'bg-violet-500/15 border-violet-500/30',
  },
  {
    icon: CheckCircle2,
    label: 'Sonuç',
    description: 'Onay sonrası ödeme serbest bırakılır; sorun varsa anlaşmazlık süreci başlar.',
    color: 'text-amber-400',
    bg: 'bg-amber-500/15 border-amber-500/30',
  },
] as const;

export default function ConsoleSecureProcessCard() {
  return (
    <section className={`${CONSOLE_CARD} p-4 sm:p-6`} aria-labelledby="secure-process-heading">
      <h2 id="secure-process-heading" className="text-base font-bold text-white sm:text-lg">
        Zinesh Güvenli Emanet Süreci
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        Pazaryeri değil — mevcut anlaşmanızı kayıt altına alan emanet süreci.
      </p>

      <ol className="mt-5 space-y-4">
        {STEPS.map((step, index) => {
          const Icon = step.icon;
          return (
            <li key={step.label} className="flex gap-3">
              <div className="flex flex-col items-center">
                <span
                  className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border ${step.bg}`}
                >
                  <Icon className={`h-4 w-4 ${step.color}`} aria-hidden />
                </span>
                {index < STEPS.length - 1 && (
                  <span className="mt-1 h-full w-px flex-1 bg-slate-800" aria-hidden />
                )}
              </div>
              <div className="min-w-0 pb-1 pt-0.5">
                <p className="text-sm font-semibold text-slate-100">
                  {index + 1}. {step.label}
                </p>
                <p className="mt-0.5 text-xs leading-relaxed text-slate-400">{step.description}</p>
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
