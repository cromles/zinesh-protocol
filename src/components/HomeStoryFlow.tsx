import React from 'react';
import {
  ArrowRight,
  BadgeCheck,
  FileCheck,
  Link2,
  Lock,
  MessageSquare,
  Scale,
  Shield,
} from 'lucide-react';
import ZineshLogo from './ZineshLogo';
import HomeStarfield from './HomeStarfield';
import { DISPUTE_OUTCOMES_NOTE, ESCROW_ONLY_TAGLINE, PLAIN_ESCROW_HERO } from '../lib/plainLanguage';
import { CURRENCY_NAME } from '../lib/productMode';

interface HomeStoryFlowProps {
  isLoggedIn?: boolean;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
}

const JOURNEY = [
  {
    icon: Link2,
    phase: 'Eşleş',
    title: 'Üye ID ile buluşun',
    text: 'Karşı tarafın numarasını girin, rolünüzü seçin — alıcı veya satıcı.',
  },
  {
    icon: MessageSquare,
    phase: 'Konuş',
    title: 'Talepleri netleştirin',
    text: 'Ne isteniyor, ne teslim edilecek; beğenmezseniz düzeltir veya başlamazsınız.',
  },
  {
    icon: FileCheck,
    phase: 'Yaz',
    title: 'Sözleşmeyi yazın',
    text: 'Kapsam, teslim ve tutar tek metinde. Karşı taraf onaylamadan para kilitlenmez.',
  },
  {
    icon: Lock,
    phase: 'Kilitle',
    title: 'Para kasada bekler',
    text: `Anlaşınca tutar Zinesh kasasında durur; iş şartlara göre yürür.`,
  },
  {
    icon: BadgeCheck,
    phase: 'Bitir',
    title: 'Teslim ve onay',
    text: 'Satıcı teslim eder, alıcı kontrol eder. Onaylanınca ödeme serbest kalır.',
  },
] as const;

export default function HomeStoryFlow({
  isLoggedIn = false,
  onJoinClick,
  onGoToConsole,
}: HomeStoryFlowProps) {
  const primaryAction = () => (isLoggedIn ? onGoToConsole?.() : onJoinClick());

  return (
    <div className="home-canvas relative overflow-x-clip">
      {/* Tek arka plan — tüm sayfa */}
      <div className="pointer-events-none absolute inset-0" aria-hidden="true">
        <HomeStarfield />
        <div className="home-ambient home-ambient-a" />
        <div className="home-ambient home-ambient-b" />
        <div className="home-grid-fade" />
      </div>

      {/* —— Hero —— */}
      <section id="hero" className="relative z-10 pt-[calc(4.5rem+env(safe-area-inset-top))] pb-16 sm:pb-20">
        <div className="mx-auto w-full max-w-6xl px-5 sm:px-8">
          <div className="grid items-center gap-10 lg:grid-cols-[1fr_0.95fr] lg:gap-14">
            <div className="text-center lg:text-left">
              <p className="hero-enter hero-enter-1 font-mono text-[10px] sm:text-[11px] uppercase tracking-[0.22em] text-violet-300/90">
                Güvenli ödeme · {CURRENCY_NAME} emanet
              </p>

              <h1 className="hero-enter hero-enter-2 mt-3 font-display text-[clamp(1.75rem,4.5vw,2.85rem)] font-bold leading-[1.1] tracking-[-0.035em] text-white">
                Önce net sözleşme,
                <span className="block bg-gradient-to-r from-violet-200 via-white to-amber-100/90 bg-clip-text text-transparent">
                  sonra para
                </span>
              </h1>

              <p className="hero-enter hero-enter-3 mt-4 max-w-xl mx-auto lg:mx-0 text-[clamp(0.95rem,1.85vw,1.06rem)] leading-relaxed text-white/72">
                {PLAIN_ESCROW_HERO}
              </p>

              <p className="hero-enter hero-enter-4 mt-2 text-sm text-violet-200/70 max-w-lg mx-auto lg:mx-0">
                {ESCROW_ONLY_TAGLINE}
              </p>

              <div className="hero-enter hero-enter-5 mt-8 flex flex-col sm:flex-row gap-3 justify-center lg:justify-start">
                <button
                  type="button"
                  onClick={primaryAction}
                  className="group inline-flex h-[50px] items-center justify-center gap-2 rounded-full bg-white px-8 text-[15px] font-semibold text-black shadow-[0_0_40px_rgba(255,255,255,0.12)] transition hover:bg-zinc-100"
                >
                  {isLoggedIn ? 'Hesabıma Git' : 'Ücretsiz Başla'}
                  <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                </button>
                <a
                  href="#how-it-works"
                  className="inline-flex h-[50px] items-center justify-center rounded-full border border-white/12 bg-white/[0.04] px-8 text-[15px] font-semibold text-white/88 backdrop-blur-sm transition hover:bg-white/[0.08]"
                >
                  Nasıl çalışır?
                </a>
              </div>
            </div>

            {/* Ürün önizlemesi — soyut sözleşme → kasa akışı */}
            <div className="hero-enter hero-enter-6 relative mx-auto w-full max-w-md lg:max-w-none">
              <div className="home-preview-glass relative rounded-3xl border border-white/[0.09] p-5 sm:p-6">
                <div className="absolute -top-px left-8 right-8 h-px bg-gradient-to-r from-transparent via-violet-400/50 to-transparent" />
                <div className="flex items-center justify-between gap-3 border-b border-white/[0.06] pb-4">
                  <div className="flex items-center gap-2">
                    <Shield className="h-4 w-4 text-emerald-400" strokeWidth={2} aria-hidden />
                    <span className="font-mono text-[10px] uppercase tracking-wider text-zinc-400">
                      Yazılı sözleşme
                    </span>
                  </div>
                  <span className="rounded-full bg-emerald-500/15 px-2.5 py-0.5 font-mono text-[10px] text-emerald-300">
                    Onay bekliyor
                  </span>
                </div>
                <div className="mt-4 space-y-2.5 font-mono text-[11px] text-zinc-500">
                  <p className="text-zinc-300">· Logo tasarımı, 3 revizyon, 7 gün teslim</p>
                  <p>· Tutar: 4.500 {CURRENCY_NAME}</p>
                  <p>· İtirazda bu metin esas alınır</p>
                </div>
                <div className="home-preview-flow mt-5 flex items-center justify-between gap-2 rounded-2xl border border-white/[0.06] bg-black/30 px-3 py-3">
                  {['Eşleş', 'Yaz', 'Kilit', 'Onay'].map((label, i) => (
                    <React.Fragment key={label}>
                      <div className="flex flex-col items-center gap-1 min-w-0 flex-1">
                        <div
                          className={`flex h-8 w-8 items-center justify-center rounded-full text-[10px] font-bold ${
                            i < 3
                              ? 'bg-violet-500/20 text-violet-200 ring-1 ring-violet-400/30'
                              : 'bg-white/10 text-zinc-400 ring-1 ring-white/10'
                          }`}
                        >
                          {i + 1}
                        </div>
                        <span className="text-[9px] text-zinc-500 truncate w-full text-center">{label}</span>
                      </div>
                      {i < 3 && (
                        <div className="h-px flex-1 max-w-[20px] bg-gradient-to-r from-violet-500/40 to-white/10" />
                      )}
                    </React.Fragment>
                  ))}
                </div>
                <p className="mt-4 text-center text-[11px] text-zinc-500">
                  Para kilitlenmeden önce her iki taraf da şartları görür
                </p>
              </div>
              <div className="absolute -right-4 -bottom-4 -z-10 h-32 w-32 rounded-full bg-violet-600/20 blur-3xl" />
              <div className="absolute -left-6 -top-6 -z-10 h-28 w-28 rounded-full bg-amber-500/15 blur-3xl" />
            </div>
          </div>
        </div>
      </section>

      {/* —— Problem → Çözüm köprüsü —— */}
      <section className="relative z-10 py-14 sm:py-16">
        <div className="mx-auto max-w-3xl px-5 text-center">
          <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-amber-300/80">Neden Zinesh</p>
          <h2 className="mt-3 font-display text-[clamp(1.35rem,3.2vw,2rem)] font-bold tracking-tight text-white">
            Başlangıçta net olmayan iş, sonda bozulur
          </h2>
          <p className="mt-3 text-[15px] leading-relaxed text-white/60">
            Yazılı şartlar ve emanet kasası birlikte çalışır — hem iş ölçülür hem anlaşmazlıkta
            sorumluluk bellidir.
          </p>
        </div>
      </section>

      {/* —— Tek yolculuk timeline —— */}
      <section id="how-it-works" className="relative z-10 pb-16 sm:pb-24">
        <div className="mx-auto w-full max-w-3xl px-5 sm:px-8">
          <div className="mb-10 text-center sm:text-left">
            <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-violet-400">
              5 adımda
            </span>
            <h2 className="mt-2 font-display text-2xl sm:text-3xl font-bold text-white tracking-tight">
              Sözleşmeden ödemeye tek hat
            </h2>
          </div>

          <ol className="home-journey relative space-y-0">
            {JOURNEY.map((step, idx) => (
              <li key={step.title} className="home-journey-step relative flex gap-4 sm:gap-5 pb-8 last:pb-0">
                <div className="relative flex flex-col items-center">
                  <div className="relative z-10 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-violet-500/30 bg-violet-500/10 text-violet-200 shadow-[0_0_24px_rgba(139,92,246,0.15)]">
                    <step.icon className="h-5 w-5" strokeWidth={1.75} aria-hidden />
                  </div>
                  {idx < JOURNEY.length - 1 && (
                    <div className="home-journey-line absolute top-11 bottom-0 w-px bg-gradient-to-b from-violet-500/40 via-white/10 to-transparent" />
                  )}
                </div>
                <div className="home-journey-card flex-1 rounded-2xl border border-white/[0.07] bg-white/[0.025] px-4 py-4 sm:px-5 sm:py-4 backdrop-blur-[2px]">
                  <p className="font-mono text-[10px] uppercase tracking-wider text-violet-300/70">
                    {step.phase}
                  </p>
                  <h3 className="mt-1 font-display text-lg font-bold text-white">{step.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-white/58">{step.text}</p>
                </div>
              </li>
            ))}
          </ol>

          <div className="mt-10 rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/[0.07] to-transparent p-5 sm:p-6">
            <div className="flex items-start gap-3">
              <Scale className="h-5 w-5 text-amber-300 shrink-0 mt-0.5" aria-hidden />
              <div>
                <h3 className="font-display text-base font-bold text-white">İtiraz olursa</h3>
                <p className="mt-1.5 text-sm text-white/55 leading-relaxed">{DISPUTE_OUTCOMES_NOTE}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* —— Kapanış CTA —— */}
      <section className="relative z-10 pb-20 sm:pb-28">
        <div className="mx-auto max-w-2xl px-5">
          <div className="home-cta-band relative overflow-hidden rounded-3xl border border-white/[0.1] px-6 py-10 sm:px-10 sm:py-12 text-center">
            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-violet-600/10 via-transparent to-amber-500/10" />
            <div className="relative">
              <div className="mx-auto mb-4 flex justify-center scale-75 sm:scale-90">
                <ZineshLogo variant="medallion" size="md" showText={false} pulseGlow={false} interactive={false} />
              </div>
              <p className="font-mono text-[10px] uppercase tracking-[0.18em] text-zinc-400">
                Önce sözleşme · sonra kasa
              </p>
              <h2 className="mt-3 font-display text-xl sm:text-2xl font-bold text-white">
                Tanımadığın biriyle güvenle iş yap
              </h2>
              <p className="mt-2 text-sm text-white/55 max-w-md mx-auto">
                Ücretsiz kayıt ol, üye numaranla eşleş, yazılı anlaşmayla başla.
              </p>
              <button
                type="button"
                onClick={primaryAction}
                className="mt-7 inline-flex h-[48px] items-center justify-center gap-2 rounded-full bg-white px-8 text-[15px] font-semibold text-black transition hover:bg-zinc-100"
              >
                {isLoggedIn ? 'Konsola Git' : 'Ücretsiz Başla'}
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
