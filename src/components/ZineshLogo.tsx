import OptimizedPicture from './OptimizedPicture';

interface ZineshLogoProps {
  className?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  showText?: boolean;
  interactive?: boolean;
  pulseGlow?: boolean;
  transparentBg?: boolean;
  /** All variants use the same Zinesh logo asset. */
  variant?: 'image' | 'medallion';
}

const imageSizing = {
  sm: 'h-12 w-12',
  md: 'h-20 w-20',
  lg: 'h-40 w-40',
  xl: 'h-52 w-52 md:h-60 md:w-60',
};

const headerMarkSizes = new Set<ZineshLogoProps['size']>(['sm', 'md']);
const medallionBoxSizing = {
  sm: 'h-10 w-10',
  md: 'h-20 w-20',
  lg: 'h-40 w-40',
  xl: 'h-52 w-52 md:h-60 md:w-60',
};

export default function ZineshLogo({
  className = '',
  size = 'md',
  interactive = true,
  pulseGlow = false,
  variant = 'image',
}: ZineshLogoProps) {
  const isHeaderMark = variant === 'image' && headerMarkSizes.has(size);
  const containerSizing = variant === 'medallion' ? medallionBoxSizing[size] : imageSizing[size];
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
      className={`relative shrink-0 overflow-hidden ${variant === 'medallion' ? 'rounded-none' : 'rounded-lg'} ${containerSizing} ${className} ${
        interactive ? `${variant === 'medallion' ? 'transition-all' : 'transition-transform'} duration-300 hover:scale-[1.02]` : ''
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
          if (img.dataset.fallback !== '1') {
            img.dataset.fallback = '1';
            img.src = '/brand/zinesh-logo.png';
            img.removeAttribute('srcset');
            return;
          }
          img.onerror = null;
        }}
      />
    </div>
  );
}
