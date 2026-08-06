import React, { useState, useRef, useEffect } from 'react';
import { Menu, X, ArrowRight, ChevronDown, LogOut, LayoutDashboard } from 'lucide-react';
import ZineshLogo from './ZineshLogo';

interface HeaderProps {
  isLoggedIn?: boolean;
  username?: string;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
  onLogout?: () => void;
}

export default function Header({
  isLoggedIn = false,
  username = '',
  onJoinClick,
  onGoToConsole,
  onLogout,
}: HeaderProps) {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const userMenuRef = useRef<HTMLDivElement>(null);

  const navItems = [
    { label: 'Ana Sayfa', href: '#hero' },
    { label: 'Nasıl Çalışır?', href: '#how-it-works' },
    { label: 'İletişim', href: '#footer' },
  ];

  useEffect(() => {
    if (!userMenuOpen) return;
    const handleClickOutside = (e: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(e.target as Node)) {
        setUserMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [userMenuOpen]);

  const handleSmoothScroll = (e: React.MouseEvent<HTMLAnchorElement>, href: string) => {
    e.preventDefault();
    setMobileMenuOpen(false);
    const id = href.replace(/^#/, '');
    if (!id) return;

    try {
      window.dispatchEvent(new CustomEvent('zinesh-reveal-sections'));
    } catch {
      /* ignore */
    }

    const run = () => {
      const el = document.getElementById(id);
      if (!el) return;
      const coarse =
        window.matchMedia('(hover: none), (pointer: coarse), (max-width: 1023px)').matches;
      const top = Math.max(0, Math.round(el.getBoundingClientRect().top + window.scrollY));
      window.scrollTo({ top, behavior: coarse ? 'auto' : 'smooth' });
      try {
        history.replaceState(null, '', `#${id}`);
      } catch {
        /* ignore */
      }
    };

    run();
    window.requestAnimationFrame(() => {
      run();
      window.setTimeout(run, 120);
    });
  };

  const goToConsole = () => {
    setUserMenuOpen(false);
    setMobileMenuOpen(false);
    onGoToConsole?.();
  };

  const desktopCta = isLoggedIn ? (
    <div className="flex items-center gap-2">
      <button
        onClick={goToConsole}
        id="header-cta-btn"
        className="group relative flex items-center justify-center gap-2 overflow-hidden rounded-full bg-white px-5 py-2 font-sans text-sm font-semibold text-black transition-all hover:scale-[1.02] hover:shadow-lg hover:shadow-purple-500/15 cursor-pointer"
      >
        <span className="relative z-10 flex items-center gap-1.5">
          Hesabıma Git <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
        </span>
        <div className="absolute inset-0 -translate-x-full bg-gradient-to-r from-sky-100 via-white to-orange-100 group-hover:translate-x-0 transition-transform duration-500" />
      </button>

      {username && (
        <div className="relative ml-2" ref={userMenuRef}>
          <button
            type="button"
            onClick={() => setUserMenuOpen((v) => !v)}
            className="flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-zinc-200 hover:bg-white/10 hover:text-white transition cursor-pointer"
            aria-expanded={userMenuOpen}
            aria-haspopup="menu"
          >
            <span className="max-w-[100px] truncate">{username}</span>
            <ChevronDown className={`h-3.5 w-3.5 text-zinc-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
          </button>

          {userMenuOpen && (
              <div
                className="absolute right-0 top-full mt-2 w-48 rounded-xl border border-white/10 bg-zinc-950/95 backdrop-blur-md shadow-xl overflow-hidden z-50 menu-pop-in"
                role="menu"
              >
                <div className="px-3 py-2.5 border-b border-white/5">
                  <p className="text-[10px] font-mono text-zinc-400 uppercase">Hesap</p>
                  <p className="text-sm font-semibold text-white truncate">{username}</p>
                </div>
                <button
                  type="button"
                  onClick={goToConsole}
                  className="w-full flex items-center gap-2 px-3 py-2.5 text-sm text-zinc-200 hover:bg-white/5 hover:text-white transition cursor-pointer"
                  role="menuitem"
                >
                  <LayoutDashboard className="h-4 w-4 text-purple-400" />
                  Konsoluma Git
                </button>
                {onLogout && (
                  <button
                    type="button"
                    onClick={() => {
                      setUserMenuOpen(false);
                      onLogout();
                    }}
                    className="w-full flex items-center gap-2 px-3 py-2.5 text-sm text-zinc-400 hover:bg-red-500/10 hover:text-red-300 transition cursor-pointer border-t border-white/5"
                    role="menuitem"
                  >
                    <LogOut className="h-4 w-4" />
                    Çıkış Yap
                  </button>
                )}
              </div>
            )}
        </div>
      )}
    </div>
  ) : (
    <button
      onClick={onJoinClick}
      id="header-cta-btn"
      className="group relative flex items-center justify-center gap-2 overflow-hidden rounded-full bg-white px-5 py-2 font-sans text-sm font-semibold text-black transition-all hover:scale-[1.02] hover:shadow-lg hover:shadow-purple-500/15 cursor-pointer"
    >
      <span className="relative z-10 flex items-center gap-1.5">
        Üye Ol / Giriş Yap <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
      </span>
      <div className="absolute inset-0 -translate-x-full bg-gradient-to-r from-sky-100 via-white to-orange-100 group-hover:translate-x-0 transition-transform duration-500" />
    </button>
  );

  return (
    <header className="sticky top-0 z-50 w-full border-b border-white/10 bg-[#020203]/80 backdrop-blur-md safe-pad-t">
      <div className="mx-auto flex max-w-7xl h-16 sm:h-20 items-center justify-between safe-pad-x">
        
        <a href="#hero" className="flex items-center gap-3 group min-w-0">
          <ZineshLogo 
            size="sm" 
            showText={false}
            transparentBg={true}
            pulseGlow={false}
          />
          
          <div className="flex flex-col min-w-0">
            <span className="font-display text-xl font-black uppercase text-white tracking-[0.02em] group-hover:text-purple-300 transition-colors duration-150">
              ZINESH
            </span>
            <span className="font-mono text-[8px] uppercase tracking-[0.25em] text-purple-400/95 font-bold -mt-0.5">
              GÜVENLİ EMANET
            </span>
          </div>
        </a>

        <nav className="hidden md:flex items-center gap-1">
          {navItems.map((item) => (
            <a
              key={item.label}
              href={item.href}
              onClick={(e) => handleSmoothScroll(e, item.href)}
              className="px-2.5 lg:px-3.5 py-2 font-sans text-xs lg:text-sm font-medium text-zinc-400 hover:text-white transition-colors duration-150 relative group"
            >
              {item.label}
              <span className="absolute bottom-0 left-3 right-3 h-[1px] bg-gradient-to-r from-sky-400 to-orange-400 scale-x-0 group-hover:scale-x-100 transition-transform duration-200" />
            </a>
          ))}
        </nav>

        <div className="hidden md:flex items-center gap-3">
          {desktopCta}
        </div>

        <button
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
          className="flex md:hidden h-11 w-11 items-center justify-center rounded-lg border border-white/10 text-zinc-400 hover:text-white touch-target"
          aria-label="Toggle Menu"
        >
          {mobileMenuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
        </button>
      </div>

      <div
        className={`md:hidden border-t border-white/5 bg-zinc-950 safe-pad-x overflow-hidden transition-[max-height,opacity] duration-200 ease-out ${
          mobileMenuOpen ? 'max-h-[640px] opacity-100 py-6' : 'max-h-0 opacity-0 py-0'
        }`}
      >
        <div className="flex flex-col gap-4">
              {navItems.map((item) => (
                <a
                  key={item.label}
                  href={item.href}
                  onClick={(e) => handleSmoothScroll(e, item.href)}
                  className="font-display text-lg font-medium text-zinc-300 hover:text-white py-1.5 border-b border-white/5"
                >
                  {item.label}
                </a>
              ))}
              
              <div className="pt-4 space-y-3">
                {isLoggedIn ? (
                  <>
                    {username && (
                      <p className="text-center text-sm text-zinc-400">
                        Merhaba, <span className="text-white font-semibold">{username}</span>
                      </p>
                    )}
                    <button
                      onClick={goToConsole}
                      className="flex w-full items-center justify-center gap-2 rounded-full bg-white px-5 py-3 font-semibold text-black hover:bg-zinc-100"
                    >
                      Konsoluma Git <ArrowRight className="h-4 w-4" />
                    </button>
                    {onLogout && (
                      <button
                        onClick={() => {
                          setMobileMenuOpen(false);
                          onLogout();
                        }}
                        className="flex w-full items-center justify-center gap-2 rounded-full border border-white/10 px-5 py-3 text-sm font-semibold text-zinc-300 hover:text-white"
                      >
                        Çıkış Yap
                      </button>
                    )}
                  </>
                ) : (
                  <button
                    onClick={() => {
                      setMobileMenuOpen(false);
                      onJoinClick();
                    }}
                    className="flex w-full items-center justify-center gap-2 rounded-full bg-white px-5 py-3 font-semibold text-black hover:bg-zinc-100"
                  >
                    Üye Ol / Giriş Yap <ArrowRight className="h-4 w-4" />
                  </button>
                )}
              </div>
            </div>
      </div>
    </header>
  );
}
