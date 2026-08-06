/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { Suspense, lazy, useState, useEffect, useRef } from 'react';
import Header from './components/Header';
import HomeStoryFlow from './components/HomeStoryFlow';
import ErrorBoundary from './components/ErrorBoundary';
import BackToTop from './components/BackToTop';
import type { UserProfile } from './lib/userProfile';
import {
  clearSession,
  clearSessionAndReload,
  getSession,
  getSessionToken,
  refreshSession,
  saveSession,
  exchangeGoogleOAuthCode,
} from './lib/auth';
import { bindSessionInactivityTimeout } from './lib/sessionIdle';
import { clearPendingReferralCode, normalizeReferralCode, readPendingReferralCode, storePendingReferralCode } from './lib/referral';
import { emitZineshEvent } from './lib/zineshEvents';

const AlphaConsole = lazy(() => import('./components/AlphaConsole'));
const LeadModal = lazy(() => import('./components/LeadModal'));
const Footer = lazy(() => import('./components/Footer'));

function ConsoleLoadingScreen() {
  return (
    <div className="min-h-screen bg-[#030307] flex flex-col items-center justify-center text-zinc-100 font-mono">
      <div className="relative flex h-16 w-16 items-center justify-center mb-4">
        <span className="absolute animate-ping h-full w-full rounded-full bg-purple-500/20 opacity-75" />
        <div className="h-10 w-10 border-2 border-t-purple-500 border-r-transparent border-l-transparent border-b-purple-500 rounded-full animate-spin" />
      </div>
      <div className="text-zinc-400 text-xs tracking-widest uppercase">Konsol yükleniyor...</div>
    </div>
  );
}

export default function App() {
  const [isLeadModalOpen, setIsLeadModalOpen] = useState(false);
  const [viewMode, setViewMode] = useState<'landing' | 'alpha'>('landing');
  const [registeredUser, setRegisteredUser] = useState<UserProfile | null>(null);
  const [loadingSession, setLoadingSession] = useState(true);
  const [pendingReferralCode, setPendingReferralCode] = useState('');
  const [referralInvitePending, setReferralInvitePending] = useState(false);
  const [oauthCallbackState, setOauthCallbackState] = useState('');
  const [oauthNeedsTotp, setOauthNeedsTotp] = useState<'1' | 'setup' | undefined>(undefined);
  const [oauthLinkState, setOauthLinkState] = useState('');
  const [oauthLinkEmail, setOauthLinkEmail] = useState('');
  const sessionEpochRef = useRef(0);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const ref = normalizeReferralCode(params.get('ref') || '');
    if (ref) {
      storePendingReferralCode(ref);
      setPendingReferralCode(ref);
      setReferralInvitePending(true);
      emitZineshEvent('referral_landing', { ref });
      const url = new URL(window.location.href);
      url.searchParams.delete('ref');
      if (!url.hash) url.hash = 'kayit';
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    } else {
      const stored = readPendingReferralCode();
      if (stored) {
        setPendingReferralCode(stored);
        setReferralInvitePending(true);
      }
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const payment = params.get('payment');
    if (payment !== 'success' && payment !== 'failed') return;

    const url = new URL(window.location.href);
    url.searchParams.delete('payment');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);

    const message =
      payment === 'success'
        ? 'Ödeme alındı. TL bakiyen birkaç dakika içinde güncellenir.'
        : 'Ödeme tamamlanamadı. Tekrar deneyebilir veya havale bildirimi yapabilirsin.';
    try {
      sessionStorage.setItem('zinesh_flash_toast', message);
    } catch {
      /* ignore */
    }

    const session = getSession();
    if (session?.sessionToken) {
      setRegisteredUser(session);
      setViewMode('alpha');
    } else {
      setIsLeadModalOpen(true);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const oauthPending = params.get('oauth_pending');
    const oauthCode = params.get('oauth_code');
    const googleError = params.get('google_error');
    const oauthState = params.get('oauth_state');
    const needsTotp = params.get('needs_totp');
    const oauthLink = params.get('oauth_link');
    const oauthLinkEmail = params.get('email');

    const cleanOAuthParams = () => {
      const url = new URL(window.location.href);
      url.searchParams.delete('oauth_pending');
      url.searchParams.delete('oauth_code');
      url.searchParams.delete('google_error');
      url.searchParams.delete('oauth_state');
      url.searchParams.delete('needs_totp');
      url.searchParams.delete('oauth_link');
      url.searchParams.delete('email');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    };

    if (googleError) {
      cleanOAuthParams();
      try {
        sessionStorage.setItem('zinesh_login_notice', decodeURIComponent(googleError));
      } catch {
        sessionStorage.setItem('zinesh_login_notice', googleError);
      }
      setIsLeadModalOpen(true);
      return;
    }

    if (oauthState && needsTotp) {
      setOauthCallbackState(oauthState);
      setOauthNeedsTotp(needsTotp === 'setup' ? 'setup' : '1');
      cleanOAuthParams();
      setIsLeadModalOpen(true);
      return;
    }

    if (oauthLink) {
      setOauthLinkState(oauthLink);
      setOauthLinkEmail(oauthLinkEmail || '');
      cleanOAuthParams();
      setIsLeadModalOpen(true);
      return;
    }

    if (oauthPending === '1' || oauthCode) {
      cleanOAuthParams();
      exchangeGoogleOAuthCode(oauthCode || undefined)
        .then((user) => {
          setRegisteredUser(user);
          setViewMode('alpha');
        })
        .catch((err) => {
          const msg = err instanceof Error ? err.message : 'Google ile giriş başarısız.';
          try {
            sessionStorage.setItem('zinesh_login_notice', msg);
          } catch {
            /* ignore */
          }
          setIsLeadModalOpen(true);
        });
      return;
    }
  }, []);

  const invalidateSession = () => {
    // Mobilde geçici API hatalarında oturumu silme — önce depodan kurtar.
    const restored = getSession();
    if (restored?.sessionToken) {
      setRegisteredUser(restored);
      setViewMode('alpha');
      refreshSession({ timeoutMs: 20000 })
        .then((user) => setRegisteredUser(user))
        .catch(() => {
          /* token yerelde duruyor; kullanıcı paneli kullanmaya devam edebilir */
        });
      return;
    }
    clearSession();
    setRegisteredUser(null);
    setViewMode('landing');
    setIsLeadModalOpen(true);
  };

  useEffect(() => {
    let profile: UserProfile | null = null;
    try {
      profile = getSession();
    } catch {
      clearSession();
      setLoadingSession(false);
      return;
    }
    if (!profile?.sessionToken?.trim()) {
      setLoadingSession(false);
      return;
    }

    setRegisteredUser(profile);
    setViewMode('alpha');
    setLoadingSession(false);

    const epochAtStart = sessionEpochRef.current;

    refreshSession({ timeoutMs: 25000 })
      .then((user) => {
        if (sessionEpochRef.current !== epochAtStart) return;
        setRegisteredUser(user);
      })
      .catch(() => {
        // Mobil: yenileme başarısız olsa bile yerel oturumu açık tut
        if (sessionEpochRef.current !== epochAtStart) return;
        const local = getSession();
        if (local?.sessionToken) {
          setRegisteredUser(local);
          setViewMode('alpha');
        }
      });
  }, []);

  useEffect(() => {
    if (loadingSession || !referralInvitePending || !pendingReferralCode) return;

    const hasSession = !!registeredUser?.sessionToken || !!getSessionToken();
    if (hasSession) {
      return;
    }

    setViewMode('landing');
    setIsLeadModalOpen(true);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [loadingSession, referralInvitePending, pendingReferralCode, registeredUser]);

  useEffect(() => {
    const hasSession = !!registeredUser?.sessionToken || !!getSessionToken();
    if (!hasSession) return;

    return bindSessionInactivityTimeout(() => {
      setRegisteredUser(null);
      setViewMode('landing');
      setIsLeadModalOpen(true);
    });
  }, [registeredUser?.sessionToken]);

  useEffect(() => {
    if (loadingSession) return;
    emitZineshEvent('app_loaded', { view: viewMode });
  }, [loadingSession]);

  useEffect(() => {
    if (loadingSession || viewMode !== 'landing') return;
    emitZineshEvent('landing_view');
  }, [loadingSession, viewMode]);

  useEffect(() => {
    if (loadingSession || viewMode !== 'alpha') return;
    emitZineshEvent('console_open');
  }, [loadingSession, viewMode]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const verifyToken = params.get('verify_token');
    const verified = params.get('verified');
    const verifyError = params.get('verify_error');
    const reward = params.get('reward');

    const cleanUrl = () => {
      const url = new URL(window.location.href);
      url.searchParams.delete('verify_token');
      url.searchParams.delete('verified');
      url.searchParams.delete('verify_error');
      url.searchParams.delete('reward');
      window.history.replaceState({}, '', url.pathname + url.hash);
    };

    if (verifyToken) {
      cleanUrl();
      setViewMode('alpha');
      sessionStorage.setItem(
        'zinesh_verify_toast',
        'Doğrulama artık e-posta kodu ile yapılır. Konsoldan "Kod gönder" butonunu kullan.'
      );
      return;
    }

    if (!verified && !verifyError) return;

    if (verified === '1') {
      const session = getSession();
      if (session?.sessionToken) {
        refreshSession()
          .then((user) => {
            setRegisteredUser(user);
            setViewMode('alpha');
            sessionStorage.setItem('zinesh_verify_toast', 'E-posta adresin doğrulandı.');
          })
          .catch(() => {
            sessionStorage.setItem('zinesh_verify_toast', 'E-posta doğrulandı. Giriş yaparak devam edebilirsin.');
          })
          .finally(cleanUrl);
      } else {
        sessionStorage.setItem(
          'zinesh_verify_toast',
          'E-posta doğrulandı. Giriş yaparak konsola geç.'
        );
        cleanUrl();
        setIsLeadModalOpen(true);
      }
      return;
    }

    if (verifyError) {
      const messages: Record<string, string> = {
        link_disabled: 'Doğrulama artık e-posta kodu ile yapılır. Konsoldan "Kod gönder" butonunu kullan.',
        token_expired: 'Doğrulama bağlantısının süresi dolmuş. Konsoldan yeni kod iste.',
        token_not_found: 'Doğrulama bağlantısı geçersiz veya kullanılmış.',
        invalid_token: 'Geçersiz doğrulama bağlantısı.',
      };
      sessionStorage.setItem('zinesh_verify_toast', messages[verifyError] || 'E-posta doğrulaması başarısız.');
      cleanUrl();
    }
  }, []);

  useEffect(() => {
    if (!loadingSession && viewMode === 'alpha' && !registeredUser) {
      const restored = getSession();
      if (restored?.sessionToken) {
        setRegisteredUser(restored);
        return;
      }
      setViewMode('landing');
      setIsLeadModalOpen(true);
    }
  }, [viewMode, registeredUser, loadingSession]);

  const isLoggedIn = !!registeredUser?.sessionToken || !!getSessionToken();

  const handleGoToConsole = () => {
    if (!registeredUser?.sessionToken) {
      setIsLeadModalOpen(true);
      return;
    }
    setViewMode('alpha');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleOpenLeadModal = () => {
    if (isLoggedIn) {
      handleGoToConsole();
      return;
    }
    setIsLeadModalOpen(true);
  };

  const handleCloseLeadModal = () => {
    setIsLeadModalOpen(false);
  };

  const handleEnterAlpha = (user: UserProfile) => {
    sessionEpochRef.current += 1;
    saveSession(user);
    setRegisteredUser(user);
    setIsLeadModalOpen(false);
    setReferralInvitePending(false);
    clearPendingReferralCode();
    setPendingReferralCode('');
    setViewMode('alpha');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleUserUpdate = (user: UserProfile) => {
    const token = (user.sessionToken ?? getSessionToken() ?? getSession()?.sessionToken ?? '').trim();
    if (!token) return;
    const merged = { ...getSession(), ...user, sessionToken: token };
    saveSession(merged);
    setRegisteredUser(merged);
  };

  const handleBrowseLanding = () => {
    setViewMode('landing');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleLogout = () => {
    clearSession();
    setRegisteredUser(null);
    setViewMode('landing');
    window.scrollTo({ top: 0, behavior: 'instant' });
  };

  if (loadingSession) {
    return (
      <div className="min-h-screen bg-[#030307] flex flex-col items-center justify-center text-zinc-100 font-mono">
        <div className="relative flex h-16 w-16 items-center justify-center mb-4">
          <span className="absolute animate-ping h-full w-full rounded-full bg-purple-500/20 opacity-75" />
          <div className="h-10 w-10 border-2 border-t-purple-500 border-r-transparent border-l-transparent border-b-purple-500 rounded-full animate-spin" />
        </div>
        <div className="text-zinc-400 text-xs tracking-widest uppercase">Zinesh Güven Katmanı Yükleniyor...</div>
      </div>
    );
  }

  if (viewMode === 'alpha' && registeredUser) {
    return (
      <>
        <ErrorBoundary
          label="console"
          fallback={({ reset }) => (
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#030307] px-6 text-center font-sans text-zinc-100">
              <h1 className="text-lg font-semibold">Konsol yüklenemedi</h1>
              <p className="mt-2 max-w-md text-sm text-zinc-400">
                Giriş başarılı olmuş olabilir ama panel açılırken hata oluştu. Oturumu temizleyip tekrar
                deneyin.
              </p>
              <div className="mt-6 flex flex-wrap justify-center gap-3">
                <button
                  type="button"
                  onClick={reset}
                  className="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold"
                >
                  Tekrar dene
                </button>
                <button
                  type="button"
                  onClick={() => clearSessionAndReload()}
                  className="rounded-full bg-purple-600 px-5 py-2.5 text-sm font-semibold text-white"
                >
                  Oturumu temizle ve giriş yap
                </button>
              </div>
            </div>
          )}
        >
          <Suspense fallback={<ConsoleLoadingScreen />}>
            <AlphaConsole
              onBrowseLanding={handleBrowseLanding}
              onLogout={handleLogout}
              onSessionExpired={invalidateSession}
              registeredUser={registeredUser}
              onUserUpdate={handleUserUpdate}
            />
          </Suspense>
        </ErrorBoundary>
      </>
    );
  }

  return (
    <div id="zinesh-app-root" className="min-h-screen w-full max-w-full overflow-x-clip bg-[#040408] text-zinc-100 flex flex-col justify-between selection:bg-purple-500/30 selection:text-white">
      <Header
        isLoggedIn={isLoggedIn}
        username={registeredUser?.name}
        onJoinClick={handleOpenLeadModal}
        onGoToConsole={handleGoToConsole}
        onLogout={handleLogout}
      />

      <main className="flex-1 w-full max-w-full overflow-x-clip overflow-y-visible">
        <ErrorBoundary variant="section" label="home-story">
          <HomeStoryFlow
            isLoggedIn={isLoggedIn}
            onJoinClick={handleOpenLeadModal}
            onGoToConsole={handleGoToConsole}
          />
        </ErrorBoundary>
      </main>

      <Suspense fallback={<div className="min-h-[280px]" aria-hidden />}>
        <Footer />
      </Suspense>

      {isLeadModalOpen && (
        <Suspense fallback={null}>
          <LeadModal
            isOpen={isLeadModalOpen}
            onClose={handleCloseLeadModal}
            onEnterAlpha={handleEnterAlpha}
            initialReferralCode={pendingReferralCode}
            referralInvite={referralInvitePending && !!pendingReferralCode}
            oauthState={oauthCallbackState}
            oauthNeedsTotp={oauthNeedsTotp}
            oauthLinkState={oauthLinkState}
            oauthLinkEmail={oauthLinkEmail}
            onOAuthParamsConsumed={() => {
              setOauthCallbackState('');
              setOauthNeedsTotp(undefined);
              setOauthLinkState('');
              setOauthLinkEmail('');
            }}
          />
        </Suspense>
      )}

      {!isLeadModalOpen && <BackToTop />}
    </div>
  );
}
