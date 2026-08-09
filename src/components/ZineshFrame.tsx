import React from 'react';

interface ZineshFrameProps {
  children: React.ReactNode;
  className?: string;
  /** Denser padding — lists, panels */
  tight?: boolean;
  /** Active / focus state — accent corners */
  accent?: boolean;
  /** Modal / hero scale */
  size?: 'sm' | 'md' | 'lg';
  /** Drives phase-based CSS (hero contract, etc.) */
  dataPhase?: number;
}

/**
 * Logo köşe geometrisi — Zinesh görsel kimliğinin temel primitive'i.
 * Medallion'daki L-bracket'ler tüm yüzeylerde tekrarlanır.
 */
export default function ZineshFrame({
  children,
  className = '',
  tight = false,
  accent = false,
  size = 'md',
  dataPhase,
}: ZineshFrameProps) {
  return (
    <div
      className={[
        'zinesh-frame',
        tight ? 'zinesh-frame-tight' : '',
        accent ? 'zinesh-frame-accent' : '',
        size === 'sm' ? 'zinesh-frame-sm' : '',
        size === 'lg' ? 'zinesh-frame-lg' : '',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
      {...(dataPhase !== undefined ? { 'data-phase': dataPhase } : {})}
    >
      <span className="zinesh-frame-corner zinesh-frame-corner-tl" aria-hidden />
      <span className="zinesh-frame-corner zinesh-frame-corner-tr" aria-hidden />
      <span className="zinesh-frame-corner zinesh-frame-corner-bl" aria-hidden />
      <span className="zinesh-frame-corner zinesh-frame-corner-br" aria-hidden />
      {children}
    </div>
  );
}
