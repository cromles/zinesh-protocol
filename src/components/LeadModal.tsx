import React, { useEffect, useState, useRef } from 'react';
import { 
  X, 
  ShieldCheck, 
  Mail, 
  Copy, 
  Check, 
  Lock, 
  User, 
  Fingerprint, 
  ArrowRight,
  ShieldAlert,
  Eye,
  EyeOff,
  Loader2,
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import type { UserProfile } from '../lib/userProfile';
import { loginWithApi, markWelcomeSeen, registerWithApi, loginWithGoogleGsi, AuthTotpRequiredError, AuthHttpError, AuthLoginVerificationRequiredError, AuthPasswordLinkRequiredError, sendTotpResetCode, confirmTotpReset, fetchGoogleOAuthConfig, startGoogleRedirectLogin, completeGoogleOAuthTotp, completeGoogleOAuthLink } from '../lib/auth';
import { clearPendingReferralCode, normalizeReferralCode } from '../lib/referral';
import { OAUTH_PROVIDERS } from '../lib/oauthProviders';
import ForgotPasswordPanel from './ForgotPasswordPanel';
import { apiUrl } from '../lib/apiBase';
import { displayMemberTicket } from '../lib/memberTicket';

function parseTotpSetupSecret(uri: string | null): string | null {
  if (!uri) return null;
  try {
    return new URL(uri).searchParams.get('secret');
  } catch {
    const match = uri.match(/[?&]secret=([^&]+)/i);
    return match ? decodeURIComponent(match[1]) : null;
  }
}

function GoogleIcon({ className = '' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" width={18} height={18} aria-hidden>
      <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
      <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
      <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
      <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
    </svg>
  );
}

const LOGIN_VERIFY_CODE_TTL_MS = 15 * 60 * 1000;
const LOGIN_VERIFY_EMAIL_COOLDOWN_MS = 5 * 60 * 1000;
const REMEMBER_ME_MS = 30 * 24 * 60 * 60 * 1000;
const SESSION_STORAGE_KEY = 'zinesh_user';
const REMEMBER_ME_PREF_KEY = 'zinesh_remember_me';
const SESSION_EXPIRES_KEY = 'zinesh_session_expires_at';
const AUTH_API = apiUrl('/api/auth.php');

function formatCountdown(totalSeconds: number): string {
  const clamped = Math.max(0, totalSeconds);
  const minutes = Math.floor(clamped / 60);
  const seconds = clamped % 60;
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function applySessionPersistence(user: UserProfile, _rememberMe: boolean): void {
  // Mobilde "beni hatırlama" kapalı olsa bile localStorage + çerez zorunlu;
  // aksi halde Safari sekme/uygulama geçişinde oturum düşüyor.
  const token = user.sessionToken?.trim();
  if (!token) return;
  const json = JSON.stringify({ ...user, sessionToken: token });
  try {
    localStorage.setItem(SESSION_STORAGE_KEY, json);
    localStorage.setItem(REMEMBER_ME_PREF_KEY, '1');
    localStorage.setItem(SESSION_EXPIRES_KEY, String(Date.now() + REMEMBER_ME_MS));
    sessionStorage.setItem(SESSION_STORAGE_KEY, json);
  } catch {
    /* storage kapalı */
  }
  try {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    const host = location.hostname;
    const domain =
      host === 'zinesh.com' || host.endsWith('.zinesh.com') ? '; Domain=.zinesh.com' : '';
    document.cookie = `zinesh_session=${encodeURIComponent(token)}; Path=/; Max-Age=${Math.floor(REMEMBER_ME_MS / 1000)}; SameSite=Lax${secure}${domain}`;
  } catch {
    /* ignore */
  }
}

function readRememberMeDefault(): boolean {
  try {
    const pref = localStorage.getItem(REMEMBER_ME_PREF_KEY);
    if (pref === '0') return false;
  } catch {
    /* ignore */
  }
  return true;
}

interface PasswordFieldProps {
  id: string;
  value: string;
  onChange: (value: string) => void;
  autoComplete: string;
  visible: boolean;
  onToggleVisible: () => void;
}

function PasswordField({
  id,
  value,
  onChange,
  autoComplete,
  visible,
  onToggleVisible,
}: PasswordFieldProps) {
  return (
    <div className="relative min-w-0">
      <input
        id={id}
        type={visible ? 'text' : 'password'}
        required
        placeholder="Şifre"
        autoComplete={autoComplete}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full bg-zinc-900 border border-white/10 rounded-xl px-4 py-3 pr-11 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/30 transition-colors"
      />
      <button
        type="button"
        onClick={onToggleVisible}
        className="absolute right-1.5 top-1/2 -translate-y-1/2 h-11 w-11 inline-flex items-center justify-center text-zinc-500 hover:text-zinc-300 transition cursor-pointer touch-target"
        aria-label={visible ? 'Şifreyi gizle' : 'Şifreyi göster'}
        tabIndex={-1}
      >
        {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  );
}

interface LeadModalProps {
  isOpen: boolean;
  onClose: () => void;
  onEnterAlpha?: (user: UserProfile) => void;
  initialReferralCode?: string;
  referralInvite?: boolean;
  oauthState?: string;
  oauthNeedsTotp?: '1' | 'setup';
  oauthLinkState?: string;
  oauthLinkEmail?: string;
  onOAuthParamsConsumed?: () => void;
}

export default function LeadModal({
  isOpen,
  onClose,
  onEnterAlpha,
  initialReferralCode = '',
  referralInvite = false,
  oauthState = '',
  oauthNeedsTotp,
  oauthLinkState = '',
  oauthLinkEmail = '',
  onOAuthParamsConsumed,
}: LeadModalProps) {
  const [activeTab, setActiveTab] = useState<'register' | 'login'>('register');
  const [step, setStep] = useState<'form' | 'loading' | 'ticket'>('form');
  const [loadingMode, setLoadingMode] = useState<'register' | 'login'>('login');
  
  // Registration Form States
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [totpQrUri, setTotpQrUri] = useState<string | null>(null);
  const [needsTotp, setNeedsTotp] = useState(false);
  const [needsTotpSetup, setNeedsTotpSetup] = useState(false);
  const [totpResetStep, setTotpResetStep] = useState<'idle' | 'code_sent'>('idle');
  const [totpResetCode, setTotpResetCode] = useState('');
  const [totpResetLoading, setTotpResetLoading] = useState(false);
  const [uriCopied, setUriCopied] = useState(false);
  const [referralCode, setReferralCode] = useState('');
  const [needsLoginVerification, setNeedsLoginVerification] = useState(false);
  const [loginVerificationCode, setLoginVerificationCode] = useState('');
  const [showLoginPassword, setShowLoginPassword] = useState(false);
  const [showRegisterPassword, setShowRegisterPassword] = useState(false);
  const [verificationExpiresAt, setVerificationExpiresAt] = useState<number | null>(null);
  const [emailResendAvailableAt, setEmailResendAvailableAt] = useState<number | null>(null);
  const [verificationSecondsLeft, setVerificationSecondsLeft] = useState(0);
  const [verificationCodeExpired, setVerificationCodeExpired] = useState(false);
  const [resendLoading, setResendLoading] = useState(false);
  const [nowMs, setNowMs] = useState(() => Date.now());
  
  // UX States
  const [errorMsg, setErrorMsg] = useState('');
  const [copied, setCopied] = useState(false);
  const [ticketNumber, setTicketNumber] = useState('');
  const [generatedProfile, setGeneratedProfile] = useState<UserProfile | null>(null);
  const [showForgotPassword, setShowForgotPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(readRememberMeDefault);
  const [loginSubmitting, setLoginSubmitting] = useState(false);
  const [googleSubmitting, setGoogleSubmitting] = useState(false);
  const [pendingGoogleIdToken, setPendingGoogleIdToken] = useState('');
  const [googleOAuthMode, setGoogleOAuthMode] = useState<'redirect' | 'popup'>('popup');
  const [pendingOAuthState, setPendingOAuthState] = useState('');
  const [needsPasswordLink, setNeedsPasswordLink] = useState(false);
  const [pendingOAuthLinkState, setPendingOAuthLinkState] = useState('');
  const submitBtnRef = useRef<HTMLButtonElement>(null);
  const modalBodyRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = prev;
    };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    const code = normalizeReferralCode(initialReferralCode);
    if (code || referralInvite) {
      if (code) setReferralCode(code);
      setActiveTab('register');
      setStep('form');
      setLoadingMode('register');
    }
  }, [isOpen, initialReferralCode, referralInvite]);

  useEffect(() => {
    if (!isOpen) return;
    fetchGoogleOAuthConfig().then((cfg) => {
      if (cfg.mode === 'redirect') setGoogleOAuthMode('redirect');
    });
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !oauthLinkState) return;
    setPendingOAuthLinkState(oauthLinkState);
    setNeedsPasswordLink(true);
    setActiveTab('login');
    setStep('form');
    if (oauthLinkEmail) setEmail(oauthLinkEmail);
    setErrorMsg('Bu e-posta zaten kayıtlı. Google hesabını bağlamak için şifreni gir.');
    onOAuthParamsConsumed?.();
  }, [isOpen, oauthLinkState, oauthLinkEmail, onOAuthParamsConsumed]);

  useEffect(() => {
    if (!isOpen || !oauthState) return;
    setPendingOAuthState(oauthState);
    setActiveTab('login');
    setStep('form');
    setNeedsTotp(true);
    setNeedsTotpSetup(oauthNeedsTotp === 'setup');
    setErrorMsg(
      oauthNeedsTotp === 'setup'
        ? 'Google Authenticator kurulumunu tamamla, ardından 6 haneli kodu gir.'
        : 'Google ile giriş için Authenticator kodunu gir.'
    );
    onOAuthParamsConsumed?.();
  }, [isOpen, oauthState, oauthNeedsTotp, onOAuthParamsConsumed]);

  useEffect(() => {
    if (!isOpen) return;
    try {
      const notice = sessionStorage.getItem('zinesh_login_notice');
      if (notice) {
        setErrorMsg(notice);
        setActiveTab('login');
        setStep('form');
        sessionStorage.removeItem('zinesh_login_notice');
      }
    } catch {
      /* ignore */
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || step !== 'form' || activeTab !== 'register') return;
    if (!referralInvite && !initialReferralCode) return;

    const timer = window.setTimeout(() => {
      submitBtnRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
      modalBodyRef.current?.scrollTo({
        top: modalBodyRef.current.scrollHeight,
        behavior: 'smooth',
      });
    }, 400);

    return () => window.clearTimeout(timer);
  }, [isOpen, step, activeTab, referralInvite, initialReferralCode]);

  const beginLoginVerificationTimers = () => {
    const now = Date.now();
    setVerificationExpiresAt(now + LOGIN_VERIFY_CODE_TTL_MS);
    setEmailResendAvailableAt(now + LOGIN_VERIFY_EMAIL_COOLDOWN_MS);
    setVerificationCodeExpired(false);
    setVerificationSecondsLeft(Math.floor(LOGIN_VERIFY_CODE_TTL_MS / 1000));
  };

  useEffect(() => {
    if (!needsLoginVerification) return;
    const tick = () => {
      const current = Date.now();
      setNowMs(current);
      if (verificationExpiresAt) {
        const left = Math.max(0, Math.ceil((verificationExpiresAt - current) / 1000));
        setVerificationSecondsLeft(left);
        setVerificationCodeExpired(left <= 0);
      }
    };
    tick();
    const timer = window.setInterval(tick, 1000);
    return () => window.clearInterval(timer);
  }, [needsLoginVerification, verificationExpiresAt]);

  const emailResendCooldownActive =
    needsLoginVerification &&
    emailResendAvailableAt !== null &&
    nowMs < emailResendAvailableAt;

  const translateAuthError = (code: string): string => {
    switch (code) {
      case 'auth/email-already-in-use':
        return 'Bu e-posta zaten kayıtlı. Giriş yapmayı dene.';
      case 'auth/invalid-email':
        return 'Lütfen geçerli bir e-posta adresi giriniz.';
      case 'auth/weak-password':
        return 'Şifreniz en az 6 karakterden oluşmalı ve yeterince güçlü olmalıdır.';
      case 'auth/user-not-found':
      case 'auth/wrong-password':
      case 'auth/invalid-credential':
        return 'Girilen e-posta adresi veya şifre hatalı. Lütfen bilgilerinizi kontrol edin.';
      case 'auth/unauthorized-domain':
        return 'Google girişi bu sitede yapılandırılmamış. Sayfayı yenileyip tekrar dene.';
      case 'auth/operation-not-allowed':
        return 'E-posta ile kayıt şu an kapalı. Lütfen daha sonra tekrar dene.';
      case 'auth/network-request-failed':
        return 'İnternet bağlantısı kurulamadı. Bağlantınızı kontrol edip tekrar deneyin.';
      case 'permission-denied':
        return 'Profil kaydı reddedildi. Destek ile iletişime geçin.';
      default:
        return code?.trim()
          ? code
          : 'Giriş tamamlanamadı. Lütfen tekrar deneyin.';
    }
  };

  const resetModal = () => {
    setStep('form');
    setErrorMsg('');
    setCopied(false);
    setGeneratedProfile(null);
    setShowForgotPassword(false);
    setRememberMe(readRememberMeDefault());
    setLoginSubmitting(false);
    setGoogleSubmitting(false);
    setPendingGoogleIdToken('');
    setTicketNumber('');
    setLoadingMode('login');
    setNeedsTotp(false);
    setNeedsTotpSetup(false);
    setTotpQrUri(null);
    setTotpCode('');
    setTotpResetStep('idle');
    setTotpResetCode('');
    setTotpResetLoading(false);
    setUriCopied(false);
    setNeedsLoginVerification(false);
    setLoginVerificationCode('');
    setShowLoginPassword(false);
    setShowRegisterPassword(false);
    setVerificationExpiresAt(null);
    setEmailResendAvailableAt(null);
    setVerificationSecondsLeft(0);
    setVerificationCodeExpired(false);
    setResendLoading(false);
  };

  const showFounderTotpReset = needsTotpSetup;
  const totpSetupSecret = parseTotpSetupSecret(totpQrUri);

  const handleCopyTotpUri = () => {
    if (!totpQrUri) return;
    navigator.clipboard.writeText(totpQrUri);
    setUriCopied(true);
    window.setTimeout(() => setUriCopied(false), 2000);
  };

  const handleCopyTotpSecret = () => {
    if (!totpSetupSecret) return;
    navigator.clipboard.writeText(totpSetupSecret);
    setUriCopied(true);
    window.setTimeout(() => setUriCopied(false), 2000);
  };

  const handleTotpResetSend = async () => {
    if (!password) {
      setErrorMsg('Authenticator sıfırlamak için önce şifreni gir.');
      return;
    }
    setErrorMsg('');
    setTotpResetLoading(true);
    try {
      const result = await sendTotpResetCode(email, password);
      setTotpResetStep('code_sent');
      setErrorMsg(result.message);
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Kod gönderilemedi.');
    } finally {
      setTotpResetLoading(false);
    }
  };

  const handleTotpResetConfirm = async () => {
    if (!password) {
      setErrorMsg('Şifren gerekli.');
      return;
    }
    if (totpResetCode.trim().length !== 6) {
      setErrorMsg('E-posta doğrulama kodunu gir.');
      return;
    }
    setErrorMsg('');
    setTotpResetLoading(true);
    try {
      const result = await confirmTotpReset(email, password, totpResetCode);
      setTotpQrUri(result.totpQrUri ?? null);
      setNeedsTotpSetup(true);
      setNeedsTotp(true);
      setTotpResetStep('idle');
      setTotpResetCode('');
      setTotpCode('');
      setErrorMsg(result.message);
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Sıfırlama başarısız.');
    } finally {
      setTotpResetLoading(false);
    }
  };

  const enterAlpha = (profile: UserProfile) => {
    if (!profile?.sessionToken?.trim()) {
      setErrorMsg('Oturum oluşturulamadı. Lütfen tekrar deneyin.');
      setStep('form');
      return;
    }
    if (profile.uid) markWelcomeSeen(profile.uid);
    if (onEnterAlpha) onEnterAlpha(profile);
    resetModal();
    onClose();
  };

  useEffect(() => {
    if (!isOpen) {
      resetModal();
    }
  }, [isOpen]);

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !name || !password) {
      setErrorMsg('Lütfen tüm zorunlu alanları doldurunuz.');
      return;
    }
    setErrorMsg('');
    setLoadingMode('register');
    setStep('loading');

    try {
      const newProfile = await registerWithApi(name, email, password, 'real', referralCode);
      clearPendingReferralCode();
      setTicketNumber(newProfile.ticketNumber);
      setGeneratedProfile(newProfile);
      setStep('ticket');
    } catch (err: any) {
      console.error("Register Error:", err);
      setErrorMsg(err?.message || translateAuthError(err?.code));
      setStep('form');
    }
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      setErrorMsg('Lütfen e-posta ve şifrenizi giriniz.');
      return;
    }
    if ((needsTotp || needsTotpSetup) && totpCode.trim().length !== 6) {
      setErrorMsg('Google Authenticator 6 haneli kodunu girin.');
      return;
    }
    if (needsLoginVerification && loginVerificationCode.trim().length !== 6) {
      setErrorMsg('E-postana gönderilen 6 haneli doğrulama kodunu gir.');
      return;
    }
    if (needsLoginVerification && verificationCodeExpired) {
      setErrorMsg('Kod süresi doldu.');
      return;
    }
    setErrorMsg('');
    setLoadingMode('login');
    setLoginSubmitting(true);

    try {
      const profile = await loginWithApi(
        email,
        password,
        needsTotp || needsTotpSetup ? totpCode : undefined,
        loginVerificationCode.trim() ? loginVerificationCode : undefined
      );
      applySessionPersistence(profile, rememberMe);
      setNeedsTotp(false);
      setNeedsTotpSetup(false);
      setTotpQrUri(null);
      setTotpCode('');
      setNeedsLoginVerification(false);
      setLoginVerificationCode('');
      enterAlpha(profile);
    } catch (err: any) {
      if (err instanceof AuthTotpRequiredError) {
        setNeedsTotp(!!(err.needsTotp || err.needsTotpSetup));
        setNeedsTotpSetup(!!err.needsTotpSetup);
        setTotpQrUri(err.totpQrUri ?? null);
        setErrorMsg(err.message || '2FA kodu gerekli.');
        return;
      }
      if (err instanceof AuthLoginVerificationRequiredError) {
        setNeedsLoginVerification(true);
        if (!verificationExpiresAt) beginLoginVerificationTimers();
        setErrorMsg(
          err.message === 'E-posta doğrulaması gerekli.'
            ? 'E-postana gönderilen 6 haneli kodu gir.'
            : err.message || 'E-postana gönderilen 6 haneli kodu gir.'
        );
        return;
      }
      if (
        err instanceof AuthHttpError && err.status === 429
      ) {
        const isEmailCooldown = (err.message || '').includes('Doğrulama kodu zaten gönderildi');
        if (isEmailCooldown) {
          setNeedsLoginVerification(true);
          if (!verificationExpiresAt) beginLoginVerificationTimers();
          setEmailResendAvailableAt(Date.now() + LOGIN_VERIFY_EMAIL_COOLDOWN_MS);
        }
        setErrorMsg(err.message || 'Bu hesap için başka bir giriş işlemi devam ediyor.');
        return;
      }
      const msg = err?.message || '';
      if (
        msg === 'E-posta doğrulaması gerekli.' ||
        msg.includes('6 haneli') ||
        msg.toLowerCase().includes('doğrulama')
      ) {
        setNeedsLoginVerification(true);
        if (!verificationExpiresAt) beginLoginVerificationTimers();
        setErrorMsg(msg === 'E-posta doğrulaması gerekli.' ? 'E-postana gönderilen 6 haneli kodu gir.' : msg);
        return;
      }
      console.error("Login Error:", err);
      setErrorMsg(err?.message || translateAuthError(err?.code));
    } finally {
      setLoginSubmitting(false);
    }
  };

  const handleGoogleSignIn = async () => {
    if (needsPasswordLink) {
      if (!pendingOAuthLinkState || password.trim().length < 8) {
        setErrorMsg('Google hesabını bağlamak için mevcut şifreni gir.');
        return;
      }
      setErrorMsg('');
      setGoogleSubmitting(true);
      try {
        const profile = await completeGoogleOAuthLink(pendingOAuthLinkState, password);
        clearPendingReferralCode();
        setNeedsPasswordLink(false);
        setPendingOAuthLinkState('');
        applySessionPersistence(profile, rememberMe);
        enterAlpha(profile);
      } catch (err: unknown) {
        if (err instanceof AuthTotpRequiredError) {
          setNeedsTotp(!!(err.needsTotp || err.needsTotpSetup));
          setNeedsTotpSetup(!!err.needsTotpSetup);
          setTotpQrUri(err.totpQrUri ?? null);
          setErrorMsg(err.message || '2FA kodu gerekli.');
          return;
        }
        setErrorMsg(err instanceof Error ? err.message : 'Google bağlantısı başarısız.');
      } finally {
        setGoogleSubmitting(false);
      }
      return;
    }
    if ((needsTotp || needsTotpSetup) && totpCode.trim().length !== 6) {
      setErrorMsg('Google Authenticator 6 haneli kodunu girin.');
      return;
    }
    setErrorMsg('');
    setGoogleSubmitting(true);
    try {
      if (pendingOAuthState && (needsTotp || needsTotpSetup)) {
        const profile = await completeGoogleOAuthTotp(pendingOAuthState, totpCode);
        clearPendingReferralCode();
        setPendingOAuthState('');
        applySessionPersistence(profile, rememberMe);
        setNeedsTotp(false);
        setNeedsTotpSetup(false);
        setTotpQrUri(null);
        setTotpCode('');
        enterAlpha(profile);
        return;
      }

      // GSI önce (client_secret gerekmez). Redirect yalnızca açıkça seçiliyse;
      // sunucudaki secret geçersizse redirect "client secret invalid" verir.
      if (googleOAuthMode === 'redirect') {
        startGoogleRedirectLogin(activeTab === 'register' ? referralCode : undefined);
        return;
      }

      const profile = await loginWithGoogleGsi({
        referralCode: activeTab === 'register' ? referralCode : undefined,
        totpCode: needsTotp || needsTotpSetup ? totpCode : undefined,
      });
      clearPendingReferralCode();
      applySessionPersistence(profile, rememberMe);
      setNeedsTotp(false);
      setNeedsTotpSetup(false);
      setTotpQrUri(null);
      setTotpCode('');
      enterAlpha(profile);
    } catch (err: unknown) {
      if (err instanceof AuthPasswordLinkRequiredError) {
        setNeedsPasswordLink(true);
        setPendingOAuthLinkState(err.oauthLinkState);
        if (err.email) setEmail(err.email);
        setActiveTab('login');
        setErrorMsg(err.message || 'Google hesabını bağlamak için şifreni gir.');
        return;
      }
      if (err instanceof AuthTotpRequiredError) {
        setNeedsTotp(!!(err.needsTotp || err.needsTotpSetup));
        setNeedsTotpSetup(!!err.needsTotpSetup);
        setTotpQrUri(err.totpQrUri ?? null);
        setErrorMsg(err.message || '2FA kodu gerekli.');
        return;
      }
      setPendingGoogleIdToken('');
      setErrorMsg(err instanceof Error ? err.message : 'Google ile giriş başarısız.');
    } finally {
      setGoogleSubmitting(false);
    }
  };

  const handleResendLoginCode = async () => {
    if (!email || !password) {
      setErrorMsg('Kodu tekrar göndermek için e-posta ve şifreni gir.');
      return;
    }
    if (emailResendCooldownActive) return;

    setErrorMsg('');
    setResendLoading(true);
    try {
      const res = await fetch(AUTH_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'login',
          email: email.trim().toLowerCase(),
          password: verificationCodeExpired ? `${password}__zinesh_resend__` : password,
          ...(loginVerificationCode.trim() && !verificationCodeExpired
            ? { loginVerificationCode: loginVerificationCode.trim() }
            : {}),
        }),
      });
      const data = await res.json().catch(() => ({}));
      const message = (data.message as string) || '';

      if (res.status === 429 && message.includes('Doğrulama kodu zaten gönderildi')) {
        setNeedsLoginVerification(true);
        if (!verificationExpiresAt) beginLoginVerificationTimers();
        setEmailResendAvailableAt(Date.now() + LOGIN_VERIFY_EMAIL_COOLDOWN_MS);
        setErrorMsg(message);
        return;
      }

      if (res.status === 429) {
        setErrorMsg(message || 'Bu hesap için başka bir giriş işlemi devam ediyor.');
        return;
      }

      if (message) {
        setNeedsLoginVerification(true);
        if (!verificationExpiresAt) beginLoginVerificationTimers();
        else setEmailResendAvailableAt(Date.now() + LOGIN_VERIFY_EMAIL_COOLDOWN_MS);
        setErrorMsg(
          message === 'E-posta doğrulaması gerekli.'
            ? 'Doğrulama kodu e-postana gönderildi. Gelen kutunu kontrol et.'
            : message
        );
      }
    } catch {
      setErrorMsg('Kod gönderilemedi. Bağlantını kontrol edip tekrar dene.');
    } finally {
      setResendLoading(false);
    }
  };

  const handleCopyTicket = () => {
    if (ticketNumber) {
      navigator.clipboard.writeText(displayMemberTicket(ticketNumber));
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  if (!isOpen) return null;

  return (
    <AnimatePresence>
      <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 overflow-x-hidden">
        {/* Backdrop filter */}
        <motion.div 
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          onClick={() => {
            resetModal();
            onClose();
          }}
          className="absolute inset-0 bg-black/85 backdrop-blur-md"
        />

        {/* Modal Outer Container */}
        <motion.div
          ref={modalBodyRef}
          initial={{ opacity: 0, scale: 0.95, y: 15 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.95, y: 15 }}
          className="relative w-full max-w-xl max-h-[92dvh] sm:max-h-[90vh] overflow-y-auto overflow-x-hidden overscroll-contain rounded-t-3xl sm:rounded-3xl border border-white/10 bg-zinc-950 p-4 sm:p-6 md:p-8 shadow-2xl z-10 safe-pad-b pt-[max(1rem,env(safe-area-inset-top,0px))] sm:pt-6 min-w-0"
        >
          {/* Top Close Button */}
          <button 
            type="button"
            onClick={() => {
              resetModal();
              onClose();
            }}
            className="absolute top-3 right-3 sm:top-4 sm:right-4 h-11 w-11 flex items-center justify-center rounded-full border border-white/5 text-zinc-400 hover:text-white hover:bg-white/5 transition cursor-pointer touch-target"
          >
            <X className="h-4 w-4" />
          </button>

          {/* STEP 1: Auth & Register Flow */}
          {step === 'form' && (
            <div className="space-y-6">
              <div className="text-center md:text-left">
                <div className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 mb-4">
                  <Fingerprint className="h-5 w-5 animate-pulse" />
                </div>
                <h3 className="font-display text-2xl font-bold text-white tracking-tight">
                  {activeTab === 'register' ? 'Zinesh\'e Katıl' : 'Tekrar Hoş Geldin'}
                </h3>
                <p className="font-sans text-sm text-zinc-400 mt-1">
                  {referralInvite && referralCode
                    ? 'Davet kodunla kayıt ol. Kod forma eklendi.'
                    : activeTab === 'register' 
                    ? 'Ücretsiz kayıt ol. Konsola sadece üyeler girebilir.'
                    : 'E-posta ve şifrenle giriş yap, işlerine kaldığın yerden devam et.'}
                </p>
              </div>

              {referralInvite && referralCode && activeTab === 'register' && (
                <div className="rounded-xl border border-purple-500/25 bg-purple-500/10 px-4 py-3">
                  <p className="text-xs text-purple-100 font-medium">
                    Davet kodu: <span className="font-mono text-purple-200">{referralCode}</span>
                  </p>
                  <p className="text-[11px] text-purple-200/80 mt-1 leading-relaxed">
                    Kayıt olup e-postanı doğruladığında davet eden kişiye referans yazılır.
                  </p>
                </div>
              )}

              {/* Login / Register Tab Selectors */}
              {!showForgotPassword && (
              <div className="flex bg-zinc-900/80 p-1 rounded-xl border border-white/5">
                <button
                  type="button"
                  onClick={() => {
                    setActiveTab('register');
                    setErrorMsg('');
                  }}
                  className={`flex-1 min-h-[44px] py-2.5 rounded-lg font-sans text-xs font-semibold tracking-wide transition-all cursor-pointer ${
                    activeTab === 'register' 
                      ? 'bg-zinc-800 text-white shadow-md' 
                      : 'text-zinc-400 hover:text-zinc-300'
                  }`}
                >
                  🛡️ Üye Ol
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setActiveTab('login');
                    setErrorMsg('');
                  }}
                  className={`flex-1 min-h-[44px] py-2.5 rounded-lg font-sans text-xs font-semibold tracking-wide transition-all cursor-pointer ${
                    activeTab === 'login' 
                      ? 'bg-zinc-800 text-white shadow-md' 
                      : 'text-zinc-400 hover:text-zinc-300'
                  }`}
                >
                  🔐 Giriş Yap
                </button>
              </div>
              )}

              {!showForgotPassword && OAUTH_PROVIDERS.google.enabled && (
                <div className="space-y-4">
                  <button
                    type="button"
                    onClick={handleGoogleSignIn}
                    disabled={googleSubmitting || loginSubmitting}
                    className="w-full py-3.5 rounded-xl border border-white/10 bg-white text-zinc-900 font-semibold text-sm hover:bg-zinc-100 transition cursor-pointer flex items-center justify-center gap-2.5 disabled:opacity-70 disabled:cursor-not-allowed"
                  >
                    {googleSubmitting ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        {needsPasswordLink ? 'Bağlanıyor...' : 'Google ile bağlanılıyor...'}
                      </>
                    ) : (
                      <>
                        <GoogleIcon />
                        {needsPasswordLink ? 'Google hesabını bağla' : 'Google ile devam et'}
                      </>
                    )}
                  </button>
                  <div className="relative">
                    <div className="absolute inset-0 flex items-center">
                      <div className="w-full border-t border-white/10" />
                    </div>
                    <div className="relative flex justify-center text-[10px]">
                      <span className="bg-zinc-950 px-2 text-zinc-500">veya e-posta ile</span>
                    </div>
                  </div>
                </div>
              )}

              {showForgotPassword ? (
                <ForgotPasswordPanel
                  initialEmail={email}
                  onBackToLogin={() => {
                    setShowForgotPassword(false);
                    setActiveTab('login');
                    setErrorMsg('');
                  }}
                />
              ) : (
              <>
              {errorMsg && (
                <div className="flex items-start gap-2.5 p-3 rounded-xl border border-red-500/20 bg-red-500/10 text-red-200 text-xs font-sans">
                  <ShieldAlert className="h-4 w-4 text-red-400 shrink-0 mt-0.5" />
                  <span>{errorMsg}</span>
                </div>
              )}

              {/* REGISTER TAB FORM */}
              {activeTab === 'register' ? (
                <form onSubmit={handleRegister} className="space-y-5">
                  <div className="space-y-4">
                    {/* Full Name */}
                    <div>
                      <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
                        <User className="h-3 w-3" /> Katılımcı / Kurum Adı
                      </label>
                      <input
                        type="text"
                        required
                        placeholder="Örn. Sarah Chen veya Genesis Labs"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="w-full bg-zinc-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/30 transition-colors"
                      />
                    </div>

                    {/* Email */}
                    <div>
                      <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
                        <Mail className="h-3 w-3" /> E-posta Adresi
                      </label>
                      <input
                        type="email"
                        required
                        placeholder="sarah@genesislabs.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="w-full bg-zinc-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/30 transition-colors"
                      />
                    </div>

                    {/* Password */}
                    <div>
                      <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
                        <Lock className="h-3 w-3" /> Güvenli Şifre
                      </label>
                      <PasswordField
                        id="register-password"
                        value={password}
                        onChange={setPassword}
                        autoComplete="new-password"
                        visible={showRegisterPassword}
                        onToggleVisible={() => setShowRegisterPassword((v) => !v)}
                      />
                    </div>
                  </div>

                  <div>
                    <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5">
                      Davet Kodu {referralInvite ? '' : '(isteğe bağlı)'}
                    </label>
                    <input
                      type="text"
                      placeholder="8 haneli davet kodu"
                      value={referralCode}
                      readOnly={referralInvite && !!referralCode}
                      onChange={(e) => setReferralCode(normalizeReferralCode(e.target.value))}
                      className={`w-full bg-zinc-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/30 transition-colors ${
                        referralInvite && referralCode ? 'font-mono tracking-wider text-purple-200' : ''
                      }`}
                    />
                    {referralCode && (
                      <p className="mt-1.5 text-[10px] text-purple-300/90">
                        Davet kodu kayıt formuna eklendi. Kayıt ve e-posta doğrulaması sonrası davet eden kişiye sayılır.
                      </p>
                    )}
                  </div>

                  <p className="font-mono text-[9px] text-zinc-400 text-center leading-relaxed">
                    Kayıt sonrası e-posta doğrulama ve güvenli ödeme özelliklerine erişirsin.
                  </p>

                  <button
                    ref={submitBtnRef}
                    type="submit"
                    className="w-full py-4 rounded-xl bg-white text-black font-semibold text-sm hover:bg-zinc-200 transition cursor-pointer flex items-center justify-center gap-2"
                  >
                    Kayıt Ol
                  </button>
                </form>
              ) : (
                /* LOGIN TAB FORM */
                <form onSubmit={handleLogin} className="space-y-5 min-w-0">
                  <div className="space-y-4 min-w-0">
                    {/* Email */}
                    <div>
                      <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
                        <Mail className="h-3 w-3" /> Kayıtlı E-posta Adresi
                      </label>
                      <input
                        type="email"
                        required
                        placeholder="sarah@genesislabs.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="w-full bg-zinc-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/30 transition-colors"
                      />
                    </div>

                    {/* Password */}
                    <div>
                      <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
                        <Lock className="h-3 w-3" /> Güvenli Şifre
                      </label>
                      <PasswordField
                        id="login-password"
                        value={password}
                        onChange={setPassword}
                        autoComplete="current-password"
                        visible={showLoginPassword}
                        onToggleVisible={() => setShowLoginPassword((v) => !v)}
                      />
                      <div className="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                        <label className="flex items-center gap-2 text-xs text-zinc-400 cursor-pointer select-none">
                          <input
                            type="checkbox"
                            checked={rememberMe}
                            onChange={(e) => setRememberMe(e.target.checked)}
                            className="h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-purple-500 focus:ring-purple-500/40"
                          />
                          Beni hatırla
                        </label>
                        <button
                          type="button"
                          onClick={() => {
                            setShowForgotPassword(true);
                            setErrorMsg('');
                          }}
                          className="text-[11px] text-purple-400 hover:text-purple-300 transition cursor-pointer shrink-0"
                        >
                          Şifremi Unuttum
                        </button>
                      </div>
                    </div>

                    {needsLoginVerification && (
                      <div className="rounded-xl border border-purple-500/25 bg-purple-950/10 p-4 space-y-3">
                        <label className="font-mono text-[10px] text-purple-200/90 uppercase tracking-wider block flex items-center gap-1">
                          <ShieldAlert className="h-3 w-3" />
                          E-posta doğrulama kodu (6 hane)
                        </label>
                        <p className="text-[11px] text-zinc-400 leading-relaxed">
                          E-posta doğrulaması gerekli. Kayıtlı adresine gönderilen 6 haneli kodu gir (15 dakika geçerli).
                        </p>
                        <p className="font-mono text-[11px] text-purple-200/90">
                          Kod geçerlilik süresi:{' '}
                          <span className="tabular-nums">{formatCountdown(verificationSecondsLeft)}</span>
                        </p>
                        {verificationCodeExpired ? (
                          <p className="text-[11px] text-red-300/90 font-medium">Kod süresi doldu.</p>
                        ) : null}
                        <input
                          type="text"
                          inputMode="numeric"
                          pattern="[0-9]{6}"
                          maxLength={6}
                          required={!verificationCodeExpired}
                          disabled={verificationCodeExpired}
                          placeholder="123456"
                          autoComplete="one-time-code"
                          value={loginVerificationCode}
                          onChange={(e) => setLoginVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                          className="w-full bg-zinc-900 border border-purple-500/20 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-purple-500/40 transition-colors font-mono tracking-widest text-center disabled:opacity-50 disabled:cursor-not-allowed"
                        />
                        {!emailResendCooldownActive && (
                          <button
                            type="button"
                            disabled={resendLoading}
                            onClick={handleResendLoginCode}
                            className="w-full py-2.5 rounded-xl border border-purple-500/30 text-purple-200 text-xs font-semibold hover:bg-purple-950/30 transition cursor-pointer disabled:opacity-50"
                          >
                            {resendLoading ? 'Gönderiliyor...' : 'Kodu Tekrar Gönder'}
                          </button>
                        )}
                      </div>
                    )}

                    {(needsTotp || needsTotpSetup) && (
                      <div className="rounded-xl border border-amber-500/25 bg-amber-950/10 p-4 space-y-3">
                        <label className="font-mono text-[10px] text-amber-200/90 uppercase tracking-wider block flex items-center gap-1">
                          <ShieldCheck className="h-3 w-3" />
                          {needsTotpSetup ? 'Google Authenticator kurulumu' : 'Google Authenticator (6 hane)'}
                        </label>
                        {needsTotpSetup && (
                          <p className="text-[11px] text-zinc-400 leading-relaxed">
                            Google Authenticator&apos;da <strong className="text-zinc-300">Kurulum anahtarı gir</strong> seçeneğini kullanın veya QR kodunu tarayın. Ardından uygulamadaki 6 haneli kodu aşağıya yazın.
                          </p>
                        )}
                        {totpQrUri && needsTotpSetup && (
                          <div className="flex flex-col items-center gap-3">
                            {totpSetupSecret && (
                              <div className="w-full rounded-lg border border-amber-500/30 bg-zinc-950/80 p-3">
                                <p className="text-[10px] font-mono text-amber-300 uppercase mb-2">Kurulum anahtarı (manuel)</p>
                                <div className="flex gap-2 items-start">
                                  <code className="flex-1 text-sm text-white font-mono tracking-[0.2em] break-all leading-relaxed">
                                    {totpSetupSecret}
                                  </code>
                                  <button
                                    type="button"
                                    onClick={handleCopyTotpSecret}
                                    className="shrink-0 px-2 py-2 rounded-lg border border-zinc-700 text-zinc-400 hover:text-zinc-200 transition cursor-pointer"
                                    title="Anahtarı kopyala"
                                  >
                                    {uriCopied ? <Check className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
                                  </button>
                                </div>
                                <p className="text-[10px] text-zinc-500 mt-2">Hesap adı: Zinesh · Zaman tabanlı</p>
                              </div>
                            )}
                            <img
                              src={`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(totpQrUri)}`}
                              alt="TOTP QR kodu"
                              width={180}
                              height={180}
                              className="rounded-lg border border-zinc-800 bg-white p-2"
                              loading="lazy"
                            />
                            <div className="w-full">
                              <p className="text-[10px] font-mono text-zinc-400 uppercase mb-1">otpauth URI</p>
                              <div className="flex gap-2">
                                <code className="flex-1 text-[10px] text-amber-100/90 break-all font-mono bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-2 max-h-20 overflow-y-auto">
                                  {totpQrUri}
                                </code>
                                <button
                                  type="button"
                                  onClick={handleCopyTotpUri}
                                  className="shrink-0 px-2 py-2 rounded-lg border border-zinc-700 text-zinc-400 hover:text-zinc-200 transition cursor-pointer"
                                  title="URI kopyala"
                                >
                                  {uriCopied ? <Check className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>
                          </div>
                        )}
                        <input
                          type="text"
                          inputMode="numeric"
                          pattern="[0-9]{6}"
                          maxLength={6}
                          required
                          placeholder="123456"
                          value={totpCode}
                          onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                          className="w-full bg-zinc-900 border border-amber-500/30 rounded-xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-amber-500/50 transition-colors tracking-widest font-mono"
                        />
                        {showFounderTotpReset && (
                          <div className="pt-2 border-t border-amber-500/15 space-y-2">
                            {totpResetStep === 'idle' ? (
                              <button
                                type="button"
                                disabled={totpResetLoading}
                                onClick={handleTotpResetSend}
                                className="text-[11px] text-amber-300/90 hover:text-amber-200 underline underline-offset-2 cursor-pointer disabled:opacity-50"
                              >
                                Authenticator Kurulumunu Sıfırla
                              </button>
                            ) : (
                              <div className="space-y-2">
                                <p className="text-[11px] text-zinc-400">E-postana gelen 6 haneli kodu gir:</p>
                                <input
                                  type="text"
                                  inputMode="numeric"
                                  maxLength={6}
                                  placeholder="E-posta kodu"
                                  value={totpResetCode}
                                  onChange={(e) => setTotpResetCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                                  className="w-full bg-zinc-900 border border-zinc-700 rounded-xl px-4 py-2.5 text-sm text-zinc-100 font-mono tracking-widest"
                                />
                                <button
                                  type="button"
                                  disabled={totpResetLoading}
                                  onClick={handleTotpResetConfirm}
                                  className="w-full py-2.5 rounded-xl border border-amber-500/30 text-amber-200 text-xs font-semibold hover:bg-amber-950/30 transition cursor-pointer disabled:opacity-50"
                                >
                                  {totpResetLoading ? 'Doğrulanıyor...' : 'Sıfırlamayı Onayla'}
                                </button>
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    )}
                  </div>

                  <p className="font-mono text-[9px] text-zinc-400 text-center leading-relaxed">
                    Şifren güvenli şekilde saklanır.
                  </p>

                  <button
                    type="submit"
                    disabled={loginSubmitting}
                    className="w-full py-4 rounded-xl bg-purple-600 text-white font-semibold text-sm hover:bg-purple-500 transition cursor-pointer flex items-center justify-center gap-2 border border-purple-500/20 hover:shadow-lg hover:shadow-purple-500/10 disabled:opacity-70 disabled:cursor-not-allowed"
                  >
                    {loginSubmitting ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        Giriş Yapılıyor...
                      </>
                    ) : (
                      'Giriş Yap'
                    )}
                  </button>
                </form>
              )}
              </>
              )}
            </div>
          )}

          {/* STEP 2: Custom Cryptographic Loading Process */}
          {step === 'loading' && (
            <div className="flex flex-col items-center justify-center py-16 space-y-6 text-center font-mono">
              <div className="relative flex h-16 w-16 items-center justify-center">
                <span className="absolute animate-ping h-full w-full rounded-full bg-purple-500/20 opacity-75" />
                <div className="h-10 w-10 border-2 border-t-purple-500 border-r-transparent border-l-transparent border-b-purple-500 rounded-full animate-spin" />
              </div>
              
              <div className="space-y-2">
                <p className="text-zinc-200 font-bold text-sm tracking-wider">
                  {loadingMode === 'register' ? 'Kimliğin oluşturuluyor...' : 'Giriş yapılıyor...'}
                </p>
                <p className="text-xs text-zinc-400">
                  {loadingMode === 'register'
                    ? 'Zinesh üyeliğin hazırlanıyor'
                    : `${email} hesabı kontrol ediliyor`}
                </p>
              </div>
            </div>
          )}

          {/* STEP 3: Cryptographic Access Ticket Display for New Registrations! */}
          {step === 'ticket' && (
            <div className="space-y-6">
              <div className="text-center">
                <div className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 mb-2">
                  <ShieldCheck className="h-5 w-5" />
                </div>
                <h4 className="font-display font-bold text-white text-xl">Zinesh&apos;e Hoş Geldin</h4>
                <p className="font-sans text-xs text-zinc-400 leading-relaxed max-w-sm mx-auto">
                  Hesabın hazır. Üye ID&apos;nle karşı tarafı eşleştirip yazılı sözleşmeyle işe başlayabilirsin.
                  {!generatedProfile?.emailVerified && (
                    <>
                      {' '}
                      <span className="text-amber-400 font-medium block mt-2">
                        <strong className="text-amber-300">{email}</strong> adresine doğrulama e-postası gönderdik.
                        Gelen kutunu kontrol et — doğrulama sonrası konsola geçebilirsin.
                      </span>
                    </>
                  )}
                </p>
              </div>

              {/* Digital Access Ticket Design */}
              <div className="relative border border-white/10 rounded-2xl bg-[#09090e] overflow-hidden shadow-2xl p-6">
                
                {/* Ticket edge notch styling (left & right) */}
                <div className="absolute top-1/2 -left-3 h-6 w-6 rounded-full bg-zinc-950 border border-white/10 -translate-y-1/2" />
                <div className="absolute top-1/2 -right-3 h-6 w-6 rounded-full bg-zinc-950 border border-white/10 -translate-y-1/2" />
                
                {/* Ticket Head */}
                <div className="flex items-start justify-between border-b border-white/5 pb-4 mb-4 font-mono">
                  <div>
                    <span className="text-[9px] text-zinc-400 tracking-wider block">ZINESH ÜYELİK BİLETİ</span>
                    <span className="font-display text-sm font-bold text-white tracking-widest">{name}</span>
                  </div>
                  <span className="text-[9px] text-emerald-400 bg-emerald-500/10 px-2.5 py-0.5 rounded border border-emerald-500/10 uppercase tracking-widest font-bold">
                    ÜYE
                  </span>
                </div>

                {/* Ticket Info Area */}
                <div className="grid grid-cols-2 gap-4 mb-6 text-left">
                  <div>
                    <span className="font-mono text-[9px] text-zinc-400 uppercase block">KATILIMCI / AD SOYAD:</span>
                    <span className="font-sans text-xs font-bold text-white block truncate">{name}</span>
                  </div>
                  <div>
                    <span className="font-mono text-[9px] text-zinc-400 uppercase block">E-POSTA:</span>
                    <span className="font-mono text-[10px] block truncate text-zinc-200">
                      {generatedProfile?.email}
                    </span>
                  </div>
                  <div>
                    <span className="font-mono text-[9px] text-zinc-400 uppercase block">KAYIT SERİ BİLET:</span>
                    <span className="font-mono text-xs font-semibold text-purple-400 block">{displayMemberTicket(ticketNumber)}</span>
                  </div>
                  <div>
                    <span className="font-mono text-[9px] text-zinc-400 uppercase block">GÜVEN PUANI:</span>
                    <span className="font-mono text-xs font-semibold text-emerald-400 block">50 / 100 (başlangıç)</span>
                  </div>
                </div>

                {/* Simulated Barcode / QR element inside ticket */}
                <div className="flex items-center justify-between pt-4 border-t border-dashed border-white/10">
                  <div className="flex-1 pr-6 flex gap-1 h-8 items-end opacity-75">
                    <div className="bg-white w-1.5 h-full" />
                    <div className="bg-white w-[1px] h-[85%]" />
                    <div className="bg-white w-1 h-full" />
                    <div className="bg-white w-[2px] h-[40%]" />
                    <div className="bg-white w-1.5 h-full" />
                    <div className="bg-white w-1 h-[70%]" />
                    <div className="bg-white w-[1px] h-full" />
                    <div className="bg-white w-2 h-full" />
                    <div className="bg-white w-[3px] h-[55%]" />
                    <div className="bg-white w-1 h-full" />
                    <div className="bg-white w-1.5 h-[80%]" />
                    <div className="bg-white w-[1px] h-full" />
                    <div className="bg-white w-1 h-full" />
                    <div className="bg-white w-2 h-[45%]" />
                    <div className="bg-white w-1.5 h-full" />
                    <div className="bg-white w-[1px] h-[90%]" />
                    <div className="bg-white w-[1px] h-full" />
                  </div>

                  <div className="flex h-10 w-10 shrink-0 select-none items-center justify-center bg-white p-1 rounded">
                    <svg viewBox="0 0 100 100" className="h-full w-full stroke-black fill-black">
                      <rect x="0" y="0" width="30" height="30" />
                      <rect x="70" y="0" width="30" height="30" />
                      <rect x="0" y="70" width="30" height="30" />
                      <rect x="12" y="12" width="6" height="6" fill="white" />
                      <rect x="82" y="12" width="6" height="6" fill="white" />
                      <rect x="12" y="82" width="6" height="6" fill="white" />
                      <rect x="40" y="10" width="10" height="10" />
                      <rect x="55" y="25" width="8" height="15" />
                      <rect x="35" y="45" width="16" height="8" />
                      <rect x="45" y="70" width="15" height="10" />
                      <rect x="70" y="45" width="20" height="20" />
                      <text x="50" y="50" fontSize="5" fill="black">Z</text>
                    </svg>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex gap-3">
                <button
                  onClick={handleCopyTicket}
                  className="flex-1 py-3 px-4 rounded-xl font-sans text-xs font-semibold bg-zinc-900 border border-white/10 text-zinc-300 hover:text-white transition flex items-center justify-center gap-2 cursor-pointer"
                >
                  {copied ? (
                    <>
                      <Check className="h-4 w-4 text-emerald-400" /> Kopyalandı
                    </>
                  ) : (
                    <>
                      <Copy className="h-4 w-4" /> Kopyala (Seri No)
                    </>
                  )}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    if (generatedProfile) {
                      enterAlpha(generatedProfile);
                    } else {
                      resetModal();
                      onClose();
                    }
                  }}
                  className="flex-1 py-3 px-4 rounded-xl font-sans text-xs font-semibold bg-white text-black hover:bg-zinc-200 transition cursor-pointer flex items-center justify-center gap-1.5"
                >
                  Konsola Gir <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </div>
          )}

        </motion.div>
      </div>
    </AnimatePresence>
  );
}
