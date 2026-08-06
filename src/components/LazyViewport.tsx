import React, { useEffect, useRef, useState } from 'react';

interface LazyViewportProps {
  children: React.ReactNode;
  /** Placeholder yüksekliği — layout shift önler */
  minHeight?: string;
  /** Viewport dışından ne kadar erken yüklensin */
  rootMargin?: string;
  className?: string;
}

const REVEAL_EVENT = 'zinesh-reveal-sections';

/**
 * IntersectionObserver ile viewport'a yaklaşınca children render eder.
 * Hash / gezinme `zinesh-reveal-sections` ile erken açabilir.
 */
export default function LazyViewport({
  children,
  minHeight = '280px',
  rootMargin = '240px 0px',
  className = '',
}: LazyViewportProps) {
  const hostRef = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const forceReveal = () => setVisible(true);
    window.addEventListener(REVEAL_EVENT, forceReveal);
    return () => window.removeEventListener(REVEAL_EVENT, forceReveal);
  }, []);

  useEffect(() => {
    const node = hostRef.current;
    if (visible || !node) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) {
          setVisible(true);
          observer.disconnect();
        }
      },
      { rootMargin, threshold: 0.01 }
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, [visible, rootMargin]);

  return (
    <div ref={hostRef} className={className} style={visible ? undefined : { minHeight }}>
      {visible ? children : null}
    </div>
  );
}
