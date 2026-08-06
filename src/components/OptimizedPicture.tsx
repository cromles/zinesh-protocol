import React from 'react';

type Source = {
  srcSet: string;
  type?: string;
  sizes?: string;
};

interface OptimizedPictureProps {
  alt: string;
  width: number;
  height: number;
  className?: string;
  loading?: 'eager' | 'lazy';
  decoding?: 'async' | 'sync' | 'auto';
  draggable?: boolean;
  fetchPriority?: 'high' | 'low' | 'auto';
  sources?: Source[];
  fallbackSrc: string;
  fallbackSrcSet?: string;
  sizes?: string;
  onError?: React.ReactEventHandler<HTMLImageElement>;
}

/** WebP + srcset destekli picture; PNG/JPG yedek. */
export default function OptimizedPicture({
  alt,
  width,
  height,
  className = '',
  loading = 'lazy',
  decoding = 'async',
  draggable,
  fetchPriority,
  sources = [],
  fallbackSrc,
  fallbackSrcSet,
  sizes,
  onError,
}: OptimizedPictureProps) {
  return (
    <picture>
      {sources.map((source) => (
        <source
          key={`${source.type ?? 'img'}-${source.srcSet}`}
          type={source.type}
          srcSet={source.srcSet}
          sizes={source.sizes ?? sizes}
        />
      ))}
      <img
        src={fallbackSrc}
        srcSet={fallbackSrcSet}
        sizes={sizes}
        alt={alt}
        width={width}
        height={height}
        className={className}
        loading={loading}
        decoding={decoding}
        draggable={draggable}
        fetchPriority={fetchPriority}
        onError={onError}
      />
    </picture>
  );
}
