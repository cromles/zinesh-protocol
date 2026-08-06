import React, { useMemo } from 'react';

/** Deterministic pseudo-random — SSR/hydration uyumlu sabit yıldız konumları */
function mulberry32(seed: number) {
  return () => {
    let t = (seed += 0x6d2b79f5);
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

type StarDot = {
  id: number;
  left: string;
  top: string;
  size: number;
  delay: string;
  duration: string;
  opacity: number;
};

export default function HomeStarfield() {
  const stars = useMemo<StarDot[]>(() => {
    const rand = mulberry32(20260805);
    return Array.from({ length: 52 }, (_, i) => ({
      id: i,
      left: `${(rand() * 100).toFixed(2)}%`,
      top: `${(rand() * 92).toFixed(2)}%`,
      size: rand() > 0.88 ? 2.5 : rand() > 0.6 ? 1.5 : 1,
      delay: `${(rand() * 6).toFixed(2)}s`,
      duration: `${(2.2 + rand() * 3.5).toFixed(2)}s`,
      opacity: 0.15 + rand() * 0.55,
    }));
  }, []);

  return (
    <div className="home-starfield pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
      {stars.map((s) => (
        <span
          key={s.id}
          className="home-star-dot"
          style={{
            left: s.left,
            top: s.top,
            width: s.size,
            height: s.size,
            ['--star-base-opacity' as string]: String(s.opacity),
            animationDelay: s.delay,
            animationDuration: s.duration,
          }}
        />
      ))}
      <span className="home-shooting-star home-shooting-star-1" />
      <span className="home-shooting-star home-shooting-star-2" />
      <span className="home-shooting-star home-shooting-star-3" />
    </div>
  );
}
