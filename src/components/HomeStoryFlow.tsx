import React, { useEffect, useState } from 'react';
import { ArrowRight } from 'lucide-react';
import ZineshFrame from './ZineshFrame';
import { CURRENCY_NAME } from '../lib/productMode';

interface HomeStoryFlowProps {
  isLoggedIn?: boolean;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
}

const ESCROW_PHASE_COUNT = 5;

function useEscrowPhase(intervalMs = 3200) {
  const [phase, setPhase] = useState(0);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const timer = window.setInterval(() => {
      setPhase((current) => (current + 1) % ESCROW_PHASE_COUNT);
    }, intervalMs);
    return () => window.clearInterval(timer);
  }, [intervalMs]);

  return phase;
}

function TrustEscrowCinema({ phase, compact = false }: { phase: number; compact?: boolean }) {
  return (
    <div
      className={`trust-cinema ${compact ? 'trust-cinema-compact' : ''}`}
      data-phase={phase}
      aria-hidden={compact}
      {...(!compact ? { 'aria-label': 'Emanet işlemi görsel akışı', role: 'img' as const } : {})}
    >
      <div className="trust-cinema-stage">
        <div className="trust-direct-rail">
          <span className="trust-direct-line" />
          <span className="trust-direct-block">✕</span>
        </div>

        <div className="trust-actor trust-actor-buyer">
          <div className="trust-avatar trust-avatar-buyer" />
          <span className="trust-actor-name">Alıcı</span>
        </div>

        <div className="trust-lane trust-lane-in">
          <span className="trust-coin trust-coin-in" />
        </div>

        <div className="trust-vault-shell">
          <ZineshFrame accent size="lg" className="trust-vault">
            <div className="trust-vault-core">
              <span className="trust-vault-lock" />
            </div>
          </ZineshFrame>
        </div>

        <div className="trust-lane trust-lane-out">
          <span className="trust-coin trust-coin-out" />
        </div>

        <div className="trust-actor trust-actor-seller">
          <div className="trust-avatar trust-avatar-seller" />
          <span className="trust-actor-name">Satıcı</span>
        </div>

        <div className="trust-document">
          <span className="trust-doc-line trust-doc-line-1" />
          <span className="trust-doc-line trust-doc-line-2" />
          <span className="trust-doc-line trust-doc-line-3" />
        </div>

        <div className="trust-delivery">
          <span className="trust-delivery-mark" />
        </div>

        <div className="trust-release">
          <span className="trust-release-mark">✓</span>
        </div>
      </div>

      <div className="trust-phase-rail">
        {Array.from({ length: ESCROW_PHASE_COUNT }).map((_, index) => (
          <span
            key={index}
            className={`trust-phase-dot ${index < phase ? 'is-done' : ''} ${index === phase ? 'is-active' : ''}`}
          />
        ))}
      </div>
    </div>
  );
}

const TRUST_PILLARS = [
  { title: 'Yazılı', visual: 'document' as const },
  { title: 'Emanet', visual: 'vault' as const },
  { title: 'Tarafsız', visual: 'balance' as const },
] as const;

export default function HomeStoryFlow({
  isLoggedIn = false,
  onJoinClick,
  onGoToConsole,
}: HomeStoryFlowProps) {
  const primaryAction = () => (isLoggedIn ? onGoToConsole?.() : onJoinClick());
  const escrowPhase = useEscrowPhase();

  return (
    <div className="home-canvas trust-canvas relative overflow-x-clip">
      <div className="pointer-events-none absolute inset-0" aria-hidden="true">
        <div className="trust-warmth trust-warmth-a" />
        <div className="trust-warmth trust-warmth-b" />
      </div>

      <section id="hero" className="relative z-10 pt-[calc(4rem+env(safe-area-inset-top))] pb-12 sm:pb-16">
        <div className="mx-auto w-full max-w-4xl px-5 sm:px-8">
          <div className="hero-enter hero-enter-1">
            <TrustEscrowCinema phase={escrowPhase} />
          </div>

          <div className="hero-enter hero-enter-3 mt-10 sm:mt-12 text-center">
            <h1 className="font-display text-[clamp(1.85rem,4.5vw,2.75rem)] font-bold leading-[1.1] tracking-[-0.03em] text-white">
              Paran, şartlar gerçekleşene kadar güvende.
            </h1>
            <p className="sr-only">
              {CURRENCY_NAME} emanet kasasında tutulur; ödeme doğrudan karşı tarafa gitmez.
            </p>
          </div>

          <div className="hero-enter hero-enter-5 mt-8 flex flex-col sm:flex-row gap-3 justify-center">
            <button
              type="button"
              onClick={primaryAction}
              aria-label={isLoggedIn ? 'Hesabıma git' : 'Ücretsiz başla'}
              className="home-btn-primary group inline-flex h-[52px] items-center justify-center gap-2 rounded-full bg-white px-9 text-[15px] font-semibold text-[#1a1510]"
            >
              {isLoggedIn ? 'Hesabıma Git' : 'Ücretsiz Başla'}
              <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
            </button>
            <a
              href="#how-it-works"
              className="home-btn-secondary inline-flex h-[52px] items-center justify-center rounded-full border border-white/15 bg-white/[0.05] px-9 text-[15px] font-semibold text-white/90"
            >
              Nasıl çalışır?
            </a>
          </div>
        </div>
      </section>

      <section id="how-it-works" className="relative z-10 py-16 sm:py-20" aria-labelledby="how-heading">
        <div className="mx-auto w-full max-w-4xl px-5 sm:px-8 text-center">
          <h2 id="how-heading" className="sr-only">
            Emanet nasıl çalışır
          </h2>
          <TrustEscrowCinema phase={escrowPhase} compact />
        </div>
      </section>

      <section className="relative z-10 py-14 sm:py-16 border-t border-white/[0.06]" aria-label="Güven ilkeleri">
        <div className="mx-auto w-full max-w-3xl px-5 sm:px-8">
          <ul className="trust-pillars grid grid-cols-3 gap-4 sm:gap-8">
            {TRUST_PILLARS.map((pillar) => (
              <li key={pillar.title} className="trust-pillar flex flex-col items-center text-center">
                <div className={`trust-pillar-visual trust-pillar-${pillar.visual}`} aria-hidden />
                <p className="mt-4 font-display text-base sm:text-lg font-semibold text-white/90">{pillar.title}</p>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="relative z-10 pb-20 sm:pb-28">
        <div className="mx-auto max-w-lg px-5 text-center">
          <div className="trust-cta">
            <h2 className="font-display text-xl sm:text-2xl font-bold text-white">
              Tanımadığın biriyle de güvenle iş yap
            </h2>
            <button
              type="button"
              onClick={primaryAction}
              aria-label={isLoggedIn ? 'Konsola git' : 'Ücretsiz başla'}
              className="home-btn-primary mt-8 inline-flex h-[50px] items-center justify-center gap-2 rounded-full bg-white px-9 text-[15px] font-semibold text-[#1a1510]"
            >
              {isLoggedIn ? 'Konsola Git' : 'Ücretsiz Başla'}
              <ArrowRight className="h-4 w-4" aria-hidden />
            </button>
          </div>
        </div>
      </section>
    </div>
  );
}
