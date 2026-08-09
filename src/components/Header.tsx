import React, { useState, useRef, useEffect } from 'react';
import { Menu, X, ArrowRight, ChevronDown, LogOut, LayoutDashboard } from 'lucide-react';
import ZineshLogo from './ZineshLogo';
import ThemeToggle from './ThemeToggle';
import { displayMemberTicket } from '../lib/memberTicket';

interface HeaderProps {
  isLoggedIn?: boolean;
  username?: string;
  ticketNumber?: string;
  onLoginClick: () => void;
  onRegisterClick: () => void;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
  onLogout?: () => void;
}

export default function Header({
  isLoggedIn = false,
  username = '',
  ticketNumber = '',
  onLoginClick,
  onRegisterClick,
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
    { label: 'Simülatör', href: '#simulator' },
    { label: 'Komisyon', href: '#calculator' },
    { label: 'SSS', href: '#faq' },
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

  const memberId = displayMemberTicket(ticketNumber);

  const desktopCta = isLoggedIn ? (
    <div className="flex items-center gap-2">
      <button
        onClick={goToConsole}
        id="header-cta-btn"
        type="button"
        aria-label="Hesabıma git"
        className="group relative flex cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-full bg-emerald-400 px-5 py-2 font-sans text-sm font-semibold text-slate-950 shadow-md shadow-emerald-950/30 transition-all hover:bg-emerald-300"
      >
        <span className="relative z-10 flex items-center gap-1.5">
          Hesabıma Git <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
        </span>
      </button>

      {username && (
        <div className="relative ml-2" ref={userMenuRef}>
          <button
            type="button"
            onClick={() => setUserMenuOpen((v) => !v)}
            className="flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-700 bg-slate-900/80 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-emerald-500/40 hover:text-white"
            aria-expanded={userMenuOpen}
            aria-haspopup="menu"
            aria-label={`Hesap menüsü, ${username}`}
          >
            <span className="max-w-[100px] truncate">{username}</span>
            <ChevronDown className={`h-3.5 w-3.5 text-zinc-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} aria-hidden />
          </button>

          {userMenuOpen && (
              <div
                className="menu-pop-in absolute right-0 top-full z-50 mt-2 w-48 overflow-hidden rounded-xl border border-slate-800 bg-slate-950/95 shadow-xl backdrop-blur-md"
                role="menu"
              >
                <div className="px-3 py-2.5 border-b border-slate-800">
                  <p className="text-[10px] font-mono uppercase text-slate-500">Hesap</p>
                  <p className="text-sm font-semibold text-white truncate">{username}</p>
                  {memberId && (
                    <p className="text-[10px] font-mono text-emerald-400 mt-0.5">ZN-{memberId}</p>
                  )}
                </div>
                <button
                  type="button"
                  onClick={goToConsole}
                  className="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-sm text-slate-200 transition hover:bg-slate-800 hover:text-white"
                  role="menuitem"
                >
                  <LayoutDashboard className="h-4 w-4 text-emerald-400" aria-hidden />
                  Konsol
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
                    <LogOut className="h-4 w-4" aria-hidden />
                    Çıkış Yap
                  </button>
                )}
              </div>
            )}
        </div>
      )}
    </div>
  ) : (
    <div className="flex items-center gap-2">
      <button
        onClick={onLoginClick}
        type="button"
        aria-label="Giriş yap"
        className="cursor-pointer rounded-full border border-slate-700 bg-slate-900/80 px-4 py-2 font-sans text-sm font-semibold text-slate-200 transition hover:border-emerald-500/40 hover:text-white"
      >
        Giriş Yap
      </button>
      <button
        onClick={onRegisterClick}
        id="header-cta-btn"
        type="button"
        aria-label="Kayıt ol"
        className="group relative flex cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-full bg-emerald-400 px-5 py-2 font-sans text-sm font-semibold text-slate-950 shadow-md shadow-emerald-950/30 transition-all hover:bg-emerald-300"
      >
        <span className="relative z-10 flex items-center gap-1.5">
          Kayıt Ol <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
        </span>
      </button>
    </div>
  );

  return (
    <header className="safe-pad-t sticky top-0 z-50 w-full border-b border-slate-800/80 bg-slate-950/90 backdrop-blur-xl">
      <div className="mx-auto flex max-w-7xl h-16 sm:h-20 items-center justify-between safe-pad-x">
        
        <a
          href="#hero"
          onClick={(e) => handleSmoothScroll(e, '#hero')}
          className="flex items-center gap-3 group min-w-0"
          aria-label="Zinesh ana sayfa"
        >
          <ZineshLogo 
            size="sm" 
            showText={false}
            transparentBg={true}
            pulseGlow={false}
          />
          
          <div className="flex flex-col min-w-0">
            <span className="font-display text-xl font-black uppercase tracking-[0.02em] text-white transition-colors duration-150 group-hover:text-emerald-300">
              ZINESH
            </span>
            <span className="-mt-0.5 font-mono text-[8px] font-bold uppercase tracking-[0.25em] text-emerald-400/95">
              GÜVENLİ EMANET
            </span>
          </div>
        </a>

        <nav className="hidden md:flex items-center gap-1" aria-label="Ana menü">
          {navItems.map((item) => (
            <a
              key={item.label}
              href={item.href}
              onClick={(e) => handleSmoothScroll(e, item.href)}
              className="home-nav-link group relative px-2.5 py-2 font-sans text-xs font-medium text-slate-400 transition-colors duration-150 hover:text-emerald-300 lg:px-3.5 lg:text-sm"
            >
              {item.label}
              <span className="absolute bottom-0 left-3 right-3 h-[1px] scale-x-0 bg-gradient-to-r from-emerald-400 to-teal-400 transition-transform duration-200 group-hover:scale-x-100" />
            </a>
          ))}
        </nav>

        <div className="hidden md:flex items-center gap-3">
          <ThemeToggle />
          {desktopCta}
        </div>

        <button
          type="button"
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
          className="flex md:hidden h-11 w-11 items-center justify-center rounded-xl border border-white/[0.1] bg-white/[0.03] text-zinc-400 hover:text-white hover:border-white/15 hover:bg-white/[0.06] transition-colors duration-200 touch-target"
          aria-label={mobileMenuOpen ? 'Menüyü kapat' : 'Menüyü aç'}
          aria-expanded={mobileMenuOpen}
          aria-controls="mobile-nav"
        >
          {mobileMenuOpen ? <X className="h-5 w-5" aria-hidden /> : <Menu className="h-5 w-5" aria-hidden />}
        </button>
      </div>

      <nav
        id="mobile-nav"
        aria-label="Mobil menü"
        className={`safe-pad-x overflow-hidden border-t border-slate-800 bg-slate-950 transition-[max-height,opacity] duration-200 ease-out md:hidden ${
          mobileMenuOpen ? 'max-h-[640px] opacity-100 py-6' : 'max-h-0 opacity-0 py-0'
        }`}
      >
        <div className="flex flex-col gap-4">
              <ThemeToggle layout="full" />
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
              
              <div className="space-y-3 pt-4">
                {isLoggedIn ? (
                  <>
                    {username && (
                      <p className="text-center text-sm text-slate-400">
                        Merhaba, <span className="font-semibold text-white">{username}</span>
                        {memberId && (
                          <span className="mt-1 block font-mono text-[10px] text-emerald-400">ZN-{memberId}</span>
                        )}
                      </p>
                    )}
                    <button
                      type="button"
                      onClick={goToConsole}
                      aria-label="Konsola git"
                      className="flex w-full items-center justify-center gap-2 rounded-full bg-emerald-400 px-5 py-3 font-semibold text-slate-950"
                    >
                      Konsol <ArrowRight className="h-4 w-4" aria-hidden />
                    </button>
                    {onLogout && (
                      <button
                        type="button"
                        onClick={() => {
                          setMobileMenuOpen(false);
                          onLogout();
                        }}
                        aria-label="Çıkış yap"
                        className="flex w-full items-center justify-center gap-2 rounded-full border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300 hover:text-white"
                      >
                        Çıkış Yap
                      </button>
                    )}
                  </>
                ) : (
                  <div className="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        setMobileMenuOpen(false);
                        onLoginClick();
                      }}
                      className="flex w-full items-center justify-center rounded-full border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-200"
                    >
                      Giriş Yap
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setMobileMenuOpen(false);
                        onRegisterClick();
                      }}
                      className="flex w-full items-center justify-center rounded-full bg-emerald-400 px-4 py-3 font-semibold text-slate-950"
                    >
                      Kayıt Ol
                    </button>
                  </div>
                )}
              </div>
            </div>
      </nav>
    </header>
  );
}
