import React, { useEffect, useState } from 'react';
import { ArrowLeft, FileText } from 'lucide-react';
import docRaw from '../../docs/Zinesh_Teknik_Dokumani_v1.0.md?raw';
import { extractToc, MarkdownDocument } from '../lib/markdownDoc';

const toc = extractToc(docRaw);
const sectionIds = toc.filter((t) => t.level === 2).map((t) => t.id);

export default function TeknikDokumanPage() {
  const [active, setActive] = useState(sectionIds[0] ?? '');

  useEffect(() => {
    const elements = sectionIds
      .map((id) => document.getElementById(id))
      .filter((el): el is HTMLElement => el !== null);
    if (elements.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]?.target.id) {
          setActive(visible[0].target.id);
        }
      },
      { rootMargin: '-18% 0px -62% 0px', threshold: [0, 0.2, 0.5] },
    );

    elements.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, []);

  return (
    <div className="teknik-dokuman-page min-h-screen bg-[#020203] text-zinc-100 overflow-x-hidden">
      <div className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div className="absolute top-0 left-1/4 h-[520px] w-[520px] rounded-full bg-purple-600/[0.07] blur-[140px]" />
        <div className="absolute bottom-1/4 right-0 h-[400px] w-[400px] rounded-full bg-amber-500/[0.04] blur-[120px]" />
        <div className="absolute top-1/2 left-0 h-[280px] w-[280px] rounded-full bg-indigo-500/[0.04] blur-[100px]" />
      </div>

      <header className="sticky top-0 z-30 border-b border-white/[0.06] bg-[#020203]/80 backdrop-blur-xl">
        <div className="mx-auto max-w-6xl px-4 sm:px-6 py-3.5 flex items-center justify-between gap-4">
          <a
            href="/"
            className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-2 text-xs font-mono text-zinc-400 hover:text-white hover:border-white/20 transition"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            Ana sayfa
          </a>
          <div className="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-500">
            <FileText className="h-3.5 w-3.5 text-purple-400/80" />
            White Paper v1.1
          </div>
        </div>
      </header>

      <div className="mx-auto max-w-6xl px-4 sm:px-6 pb-20 pt-10 lg:pt-14">
        <div className="lg:grid lg:grid-cols-[240px_minmax(0,900px)] lg:gap-12 lg:justify-center">
          <aside className="hidden lg:block">
            <nav
              aria-label="İçindekiler"
              className="sticky top-24 max-h-[calc(100vh-7rem)] overflow-y-auto pr-1"
            >
              <p className="font-mono text-[10px] uppercase tracking-[0.22em] text-zinc-500 mb-4">
                İçindekiler
              </p>
              <ul className="space-y-0.5 border-l border-white/[0.08]">
                {toc.map((item) => {
                  const isActive = item.level === 2 && active === item.id;
                  return (
                    <li key={item.id}>
                      <a
                        href={`#${item.id}`}
                        className={`block py-1.5 no-underline transition border-l-2 -ml-px ${
                          item.level === 3
                            ? 'pl-6 text-xs text-zinc-500 hover:text-zinc-300'
                            : `pl-4 text-[13px] ${
                                isActive
                                  ? 'text-white border-amber-400/70 font-medium'
                                  : 'text-zinc-500 hover:text-zinc-200 border-transparent'
                              }`
                        }`}
                      >
                        {item.text.replace(/^\d+\.\s*/, '')}
                      </a>
                    </li>
                  );
                })}
              </ul>
            </nav>
          </aside>

          <article className="w-full max-w-[900px] min-w-0 mx-auto lg:mx-0">
            <div className="mb-10 sm:mb-12">
              <span className="inline-flex items-center gap-2 rounded-full border border-purple-500/25 bg-purple-500/[0.08] px-3.5 py-1.5 font-mono text-[10px] uppercase tracking-[0.18em] text-purple-300">
                Zinesh Protocol
              </span>
              <p className="mt-6 font-mono text-xs text-zinc-500 tracking-wide">
                White Paper · Sürüm 1.1 · Temmuz 2026
              </p>
            </div>

            <nav
              aria-label="İçindekiler (mobil)"
              className="lg:hidden mb-10 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5"
            >
              <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-500 mb-3">
                İçindekiler
              </p>
              <ul className="space-y-2 text-sm">
                {toc.filter((t) => t.level === 2).map((item) => (
                  <li key={item.id}>
                    <a
                      href={`#${item.id}`}
                      className="text-zinc-400 hover:text-white no-underline transition font-sans"
                    >
                      {item.text}
                    </a>
                  </li>
                ))}
              </ul>
            </nav>

            <div className="rounded-3xl border border-white/[0.06] bg-gradient-to-b from-white/[0.03] to-transparent p-6 sm:p-10 shadow-[0_24px_80px_rgba(0,0,0,0.35)]">
              <MarkdownDocument markdown={docRaw} />
            </div>

            <footer className="mt-12 pt-8 border-t border-white/[0.06] text-center">
              <p className="font-sans text-sm text-zinc-500 leading-relaxed max-w-lg mx-auto">
                Bu belge yatırım tavsiyesi değildir. Güncel üretim davranışı için kaynak kod ve API
                yanıtları esas alınır.
              </p>
              <a
                href="/"
                className="inline-flex mt-6 rounded-full bg-white px-6 py-3 text-sm font-bold text-black hover:bg-zinc-200 transition"
              >
                zinesh.com
              </a>
            </footer>
          </article>
        </div>
      </div>
    </div>
  );
}
