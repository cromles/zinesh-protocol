import React, { useEffect, useState } from 'react';
import { ChevronUp } from 'lucide-react';

const SHOW_AFTER_PX = 420;

export default function BackToTop() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(() => {
        setVisible(window.scrollY > SHOW_AFTER_PX);
        ticking = false;
      });
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  if (!visible) return null;

  return (
    <button
      type="button"
      aria-label="Sayfanın başına dön"
      title="Yukarı"
      onClick={() => {
        window.scrollTo({ top: 0, behavior: 'auto' });
        try {
          history.replaceState(null, '', '#hero');
        } catch {
          /* ignore */
        }
      }}
      className="fixed z-[80] touch-target inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/15 bg-[#0a0a0e]/90 text-white shadow-[0_8px_28px_rgba(0,0,0,0.45)] backdrop-blur-md transition active:scale-95 hover:bg-white/10"
      style={{
        right: 'max(1rem, env(safe-area-inset-right, 0px))',
        bottom: 'max(1.25rem, env(safe-area-inset-bottom, 0px))',
      }}
    >
      <ChevronUp className="h-5 w-5" strokeWidth={2.25} />
    </button>
  );
}
