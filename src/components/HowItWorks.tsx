import React from 'react';
import { Link2, MessageSquare, FileCheck, Lock, BadgeCheck, Scale } from 'lucide-react';
import { DISPUTE_OUTCOMES_NOTE } from '../lib/plainLanguage';

const FLOW = [
  {
    icon: Link2,
    title: 'Üye ID ile eşleş',
    text: 'Karşı tarafın üye numarasını girin. Alıcı veya satıcı rolünüzü seçin.',
  },
  {
    icon: MessageSquare,
    title: 'Talepleri konuşun',
    text: 'Hizmet alan ne istediğini, hizmet veren neler yapabileceğini yazar. Beğenmezseniz düzeltir veya başlamazsınız.',
  },
  {
    icon: FileCheck,
    title: 'Sözleşme + tutar',
    text: 'Kapsam, teslim ve tutar yazılı teklife dönüşür. Karşı taraf onaylamadan para kilitlenmez.',
  },
  {
    icon: Lock,
    title: 'Kasa kilitlenir',
    text: 'Anlaşınca ödeme Zinesh kasasında bekler. İş bu şartlara göre yürür.',
  },
  {
    icon: BadgeCheck,
    title: 'Teslim ve onay',
    text: 'Satıcı teslim eder; alıcı kontrol eder. Onaylanınca ödeme serbest kalır.',
  },
] as const;

export default function HowItWorks() {
  return (
    <section id="how-it-works" className="section-screen relative border-t border-white/10 bg-[#020204]">
      <div className="section-screen-inner mx-auto max-w-3xl w-full px-4 sm:px-6 relative z-10">
        <div className="max-w-2xl mb-10">
          <span className="font-mono text-xs uppercase tracking-[0.2em] text-purple-400 font-bold block mb-2">
            Akış
          </span>
          <h2 className="font-display text-3xl sm:text-4xl font-black text-white tracking-tighter">
            Sözleşme net olursa iş ölçülür
          </h2>
          <p className="mt-3 font-sans text-sm sm:text-base text-zinc-400 leading-relaxed">
            Zinesh pazar yeri değildir. İki tarafın yazılı anlaşmasını ve ödemesini koruyan emanet
            kasasıdır. Anlaşmazlıkta karar, başta yazılan sözleşmeye göre verilir.
          </p>
        </div>

        <ol className="space-y-4">
          {FLOW.map((step, idx) => (
            <li
              key={step.title}
              className="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.03] p-4 sm:p-5"
            >
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-purple-500/25 bg-purple-500/10 text-purple-300">
                <step.icon className="h-5 w-5" aria-hidden />
              </div>
              <div className="min-w-0">
                <p className="font-mono text-[10px] uppercase tracking-wider text-zinc-500">
                  Adım {idx + 1}
                </p>
                <h3 className="mt-0.5 font-display text-lg font-bold text-white">{step.title}</h3>
                <p className="mt-1.5 text-sm text-zinc-400 leading-relaxed">{step.text}</p>
              </div>
            </li>
          ))}
        </ol>

        <div className="mt-8 rounded-2xl border border-amber-500/25 bg-amber-500/[0.06] p-5">
          <div className="flex items-start gap-3">
            <Scale className="h-5 w-5 text-amber-300 shrink-0 mt-0.5" aria-hidden />
            <div>
              <h3 className="font-display text-base font-bold text-white">İtiraz olursa</h3>
              <p className="mt-1.5 text-sm text-zinc-400 leading-relaxed">{DISPUTE_OUTCOMES_NOTE}</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
