import React, { useMemo } from 'react';
import OptimizedPicture from './OptimizedPicture';

interface ZineshLogoProps {
  className?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  showText?: boolean;
  interactive?: boolean;
  pulseGlow?: boolean;
  transparentBg?: boolean;
  /** Header/footer: image file. Hero medallion: CSS starfield + brackets. */
  variant?: 'image' | 'medallion';
}

const imageSizing = {
  sm: 'h-12 w-12',
  md: 'h-20 w-20',
  lg: 'h-40 w-40',
  xl: 'h-52 w-52 md:h-60 md:w-60',
};

const headerMarkSizes = new Set<ZineshLogoProps['size']>(['sm', 'md']);

const medallionSizing = {
  sm: {
    container: 'h-10 w-10',
    borderThickness: 'border-[2px]',
    cornerSize: 'w-2 h-2',
    fontClass: 'text-[6.5px] font-bold tracking-[0.08em]',
  },
  md: {
    container: 'h-20 w-20',
    borderThickness: 'border-[3px]',
    cornerSize: 'w-4 h-4',
    fontClass: 'text-[11px] font-black tracking-[0.1em]',
  },
  lg: {
    container: 'h-40 w-40',
    borderThickness: 'border-[4.5px]',
    cornerSize: 'w-8 h-8',
    fontClass: 'text-lg font-black tracking-[0.1em]',
  },
  xl: {
    container: 'h-52 w-52 md:h-60 md:w-60',
    borderThickness: 'border-[6px]',
    cornerSize: 'w-12 h-12',
    fontClass: 'text-2xl sm:text-3xl font-extrabold tracking-[0.1em]',
  },
};

function seededRandom(seed: number) {
  const x = Math.sin(seed * 9999.12) * 10000;
  return x - Math.floor(x);
}

function MedallionLogo({
  className = '',
  size = 'md',
  showText = true,
  interactive = true,
  pulseGlow = false,
  transparentBg = true,
}: Omit<ZineshLogoProps, 'variant'>) {
  const stars = useMemo(() => {
    const starCount = size === 'sm' ? 36 : size === 'md' ? 64 : size === 'lg' ? 100 : 140;
    const colors = ['#ffffff', '#f8fafc', '#e0f2fe', '#7dd3fc', '#c4b5fd', '#fde68a', '#fdba74'];

    return Array.from({ length: starCount }).map((_, i) => {
      const r1 = seededRandom(i + 1);
      const r2 = seededRandom(i + 17);
      const r3 = seededRandom(i + 41);
      const r4 = seededRandom(i + 73);
      const r5 = seededRandom(i + 101);
      const r6 = seededRandom(i + 137);
      const r7 = seededRandom(i + 179);
      const r8 = seededRandom(i + 211);

      return {
        id: i,
        x: r1 * 100,
        y: r2 * 100,
        starSize: r3 * 3.2 + 1.2,
        color: colors[Math.floor(r4 * colors.length)],
        twinkleDelay: r6 * 2.8,
        driftX: (r7 * 28 - 14).toFixed(1),
        driftY: (r8 * 28 - 14).toFixed(1),
        driftDuration: 1.8 + r5 * 2.4,
      };
    });
  }, [size]);

  const currentSize = medallionSizing[size];

  return (
    <div
      className={`relative rounded-none overflow-hidden select-none ${
        transparentBg ? 'bg-transparent' : 'bg-[#020204]'
      } ${currentSize.container} ${className} ${
        interactive ? 'transition-all duration-300 hover:scale-[1.02] group' : ''
      } ${pulseGlow ? 'zinesh-medallion-pulse' : ''}`}
      style={{
        aspectRatio: '1/1',
      }}
    >
      <div className="absolute inset-0 bg-[#020204]" />
      <div
        className="absolute inset-0 pointer-events-none"
        style={{
          backgroundImage: 'radial-gradient(circle, rgba(168, 85, 247, 0.18) 0%, transparent 68%)',
        }}
      />

      <div className="absolute inset-0 pointer-events-none overflow-hidden" aria-hidden="true">
        {stars.map((star) => (
          <span
            key={star.id}
            className="zinesh-star absolute block rounded-full"
            style={
              {
                top: `${star.y}%`,
                left: `${star.x}%`,
                width: `${star.starSize}px`,
                height: `${star.starSize}px`,
                backgroundColor: star.color,
                boxShadow: `0 0 ${Math.max(6, star.starSize * 2.8)}px ${star.color}`,
                '--star-dx': `${star.driftX}px`,
                '--star-dy': `${star.driftY}px`,
                '--star-drift': `${star.driftDuration}s`,
                '--star-delay': `${star.twinkleDelay}s`,
              } as React.CSSProperties
            }
          />
        ))}
      </div>

      <div className="absolute top-[18%] left-[18%] right-[18%] bottom-[18%] pointer-events-none">
        <div
          className={`absolute top-0 left-0 border-t border-l border-white ${currentSize.borderThickness} ${currentSize.cornerSize}`}
          style={{ borderRightColor: 'transparent', borderBottomColor: 'transparent' }}
        />
        <div
          className={`absolute top-0 right-0 border-t border-r border-white ${currentSize.borderThickness} ${currentSize.cornerSize}`}
          style={{ borderLeftColor: 'transparent', borderBottomColor: 'transparent' }}
        />
        <div
          className={`absolute bottom-0 left-0 border-b border-l border-white ${currentSize.borderThickness} ${currentSize.cornerSize}`}
          style={{ borderRightColor: 'transparent', borderTopColor: 'transparent' }}
        />
        <div
          className={`absolute bottom-0 right-0 border-b border-r border-white ${currentSize.borderThickness} ${currentSize.cornerSize}`}
          style={{ borderLeftColor: 'transparent', borderTopColor: 'transparent' }}
        />
      </div>

      {showText && (
        <div className="absolute inset-0 flex items-center justify-center p-4 text-center pointer-events-none z-10">
          <span
            className={`font-display font-extrabold text-white uppercase select-none ${currentSize.fontClass}`}
            style={{
              textShadow: '0 0 12px rgba(255, 255, 255, 0.55), 0 0 28px rgba(168, 85, 247, 0.35)',
            }}
          >
            {size === 'sm' ? 'Z' : 'ZINESH'}
          </span>
        </div>
      )}
    </div>
  );
}

export default function ZineshLogo({
  className = '',
  size = 'md',
  showText = true,
  interactive = true,
  pulseGlow = false,
  transparentBg = true,
  variant = 'image',
}: ZineshLogoProps) {
  if (variant === 'medallion') {
    return (
      <MedallionLogo
        className={className}
        size={size}
        showText={showText}
        interactive={interactive}
        pulseGlow={pulseGlow}
        transparentBg={transparentBg}
      />
    );
  }

  const isHeaderMark = headerMarkSizes.has(size);
  const assetBase = isHeaderMark ? 'zinesh-logo-header' : 'zinesh-logo';
  const displaySize = isHeaderMark ? 48 : size === 'lg' ? 160 : size === 'xl' ? 240 : 80;
  const imgSizes = isHeaderMark ? '48px' : size === 'xl' ? '(max-width: 768px) 13rem, 15rem' : size === 'lg' ? '10rem' : '5rem';
  const webpSrcSet = isHeaderMark
    ? '/brand/zinesh-logo-header-64.webp 64w, /brand/zinesh-logo-header-128.webp 128w, /brand/zinesh-logo-header-256.webp 256w'
    : '/brand/zinesh-logo-128.webp 128w, /brand/zinesh-logo-256.webp 256w, /brand/zinesh-logo-512.webp 512w';
  const pngSrcSet = isHeaderMark
    ? '/brand/zinesh-logo-header-64.png 64w, /brand/zinesh-logo-header-128.png 128w, /brand/zinesh-logo-header-256.png 256w'
    : '/brand/zinesh-logo-128.png 128w, /brand/zinesh-logo-256.png 256w, /brand/zinesh-logo-512.png 512w';

  return (
    <div
      className={`relative shrink-0 overflow-hidden rounded-lg ${imageSizing[size]} ${className} ${
        interactive ? 'transition-transform duration-300 hover:scale-[1.02]' : ''
      }`}
      style={{
        boxShadow: pulseGlow ? '0 0 40px rgba(168, 85, 247, 0.12)' : undefined,
      }}
    >
      <OptimizedPicture
        alt="Zinesh logosu"
        width={displaySize}
        height={displaySize}
        className="h-full w-full object-cover"
        loading={isHeaderMark ? 'eager' : 'lazy'}
        decoding="async"
        fetchPriority={isHeaderMark ? 'high' : 'auto'}
        draggable={false}
        sizes={imgSizes}
        sources={[{ type: 'image/webp', srcSet: webpSrcSet, sizes: imgSizes }]}
        fallbackSrc={`/brand/${assetBase}.png`}
        fallbackSrcSet={pngSrcSet}
        onError={(e) => {
          const img = e.currentTarget;
          if (img.dataset.fallback === 'full') {
            img.src = '/logo-1024.png';
            img.removeAttribute('srcset');
            return;
          }
          if (img.dataset.fallback !== '1') {
            img.dataset.fallback = '1';
            img.src = '/brand/zinesh-logo.png';
            img.removeAttribute('srcset');
            return;
          }
          img.dataset.fallback = 'full';
          img.src = '/logo-1024.png';
          img.removeAttribute('srcset');
        }}
      />
    </div>
  );
}
