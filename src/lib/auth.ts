import type { UserProfile } from './userProfile';
import { resolveIsFounder } from './founder';
import { GoogleAuthProvider, signInWithPopup } from 'firebase/auth';
import { auth } from './firebase';
import { getGoogleAccessTokenViaGsi, ZINESH_GOOGLE_CLIENT_ID } from './googleGsi';
import { apiUrl } from './apiBase';

const SESSION_KEY = 'zinesh_user';
const WELCOME_PREFIX = 'zinesh_welcome_seen_';
const API = apiUrl('/api/auth.php');
const SESSION_COOKIE = 'zinesh_session';
const SESSION_MAX_AGE_SEC = 60 * 60 * 24 * 30; // 30 gün — mobil Safari için uzun tut

function readSessionCookie(): string | null {
  if (typeof document === 'undefined') return null;
  try {
    const match = document.cookie.match(new RegExp(`(?:^|; )${SESSION_COOKIE}=([^;]*)`));
    if (!match?.[1]) return null;
    return decodeURIComponent(match[1]).trim() || null;
  } catch {
    return null;
  }
}

function writeSessionCookie(token: string): void {
  if (typeof document === 'undefined' || !token) return;
  const secure = location.protocol === 'https:' ? '; Secure' : '';
  const host = location.hostname;
  // www + apex paylaşımı (mobil Safari yönlendirmelerinde token kaybolmasın)
  const domain =
    host === 'zinesh.com' || host.endsWith('.zinesh.com') ? '; Domain=.zinesh.com' : '';
  document.cookie = `${SESSION_COOKIE}=${encodeURIComponent(token)}; Path=/; Max-Age=${SESSION_MAX_AGE_SEC}; SameSite=Lax${secure}${domain}`;
}

function clearSessionCookie(): void {
  if (typeof document === 'undefined') return;
  const secure = location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `${SESSION_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax${secure}`;
  document.cookie = `${SESSION_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax${secure}; Domain=.zinesh.com`;
}

function readStoredSession(): UserProfile | null {
  try {
    const raw = localStorage.getItem(SESSION_KEY) || sessionStorage.getItem(SESSION_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as UserProfile;
      if (parsed?.sessionToken) return parsed;
      // Token yoksa çerezden kurtar
      const cookieToken = readSessionCookie();
      if (cookieToken) {
        return { ...parsed, sessionToken: cookieToken };
      }
      return parsed;
    }
  } catch {
    /* ignore */
  }
  const cookieToken = readSessionCookie();
  if (cookieToken) {
    return { sessionToken: cookieToken } as UserProfile;
  }
  return null;
}

function writeStoredSession(user: UserProfile): void {
  const token = user.sessionToken?.trim();
  if (!token) {
    return; // token'sız yazma — mobil storage yarışında oturumu silme
  }
  const json = JSON.stringify({ ...user, sessionToken: token });
  try {
    localStorage.setItem(SESSION_KEY, json);
  } catch {
    /* ignore */
  }
  try {
    sessionStorage.setItem(SESSION_KEY, json);
  } catch {
    /* ignore */
  }
  writeSessionCookie(token);
}

export function getSessionToken(): string | undefined {
  return readStoredSession()?.sessionToken;
}

export function hasWelcomeSeen(uid: string): boolean {
  try {
    return localStorage.getItem(`${WELCOME_PREFIX}${uid}`) === '1';
  } catch {
    return false;
  }
}

export function markWelcomeSeen(uid: string): void {
  try {
    localStorage.setItem(`${WELCOME_PREFIX}${uid}`, '1');
  } catch {
    /* storage kapalı */
  }
}

export class AuthHttpError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'AuthHttpError';
    this.status = status;
  }
}

/** Oturumun geçersiz sayılıp temizlenmesi gereken hatalar (yalnızca sunucu 401 veya açık oturum süresi doldu mesajı). */
export function isSessionInvalidatingError(err: unknown): boolean {
  if (err instanceof AuthHttpError && err.status === 401) return true;
  const msg = err instanceof Error ? err.message : '';
  return msg.includes('Oturum süresi dolmuş') || msg.includes('Oturum bulunamadı');
}

export class AuthTotpRequiredError extends Error {
  needsTotp?: boolean;
  needsTotpSetup?: boolean;
  totpQrUri?: string | null;

  constructor(
    message: string,
    opts?: { needsTotp?: boolean; needsTotpSetup?: boolean; totpQrUri?: string | null }
  ) {
    super(message);
    this.name = 'AuthTotpRequiredError';
    this.needsTotp = opts?.needsTotp;
    this.needsTotpSetup = opts?.needsTotpSetup;
    this.totpQrUri = opts?.totpQrUri;
  }
}

export class AuthLoginVerificationRequiredError extends Error {
  mailSent?: boolean;

  constructor(message: string, opts?: { mailSent?: boolean }) {
    super(message);
    this.name = 'AuthLoginVerificationRequiredError';
    this.mailSent = opts?.mailSent;
  }
}

export class AuthPasswordLinkRequiredError extends Error {
  oauthLinkState: string;
  email: string;

  constructor(message: string, oauthLinkState: string, email: string) {
    super(message);
    this.name = 'AuthPasswordLinkRequiredError';
    this.oauthLinkState = oauthLinkState;
    this.email = email;
  }
}

async function postAuth(body: Record<string, string>): Promise<UserProfile> {
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı. İnternet bağlantınızı kontrol edin.');
  }
  const rawText = await res.text().catch(() => '');
  let data: Record<string, unknown> = {};
  try {
    data = rawText ? (JSON.parse(rawText) as Record<string, unknown>) : {};
  } catch {
    if (res.status === 403 || /cloudflare|cf-ray|attention required/i.test(rawText)) {
      throw new Error('Güvenlik duvarı isteği engelledi. Sayfayı yenileyip tekrar deneyin.');
    }
    if (/<!doctype html|<html/i.test(rawText) || res.url.includes('://zinesh.com/')) {
      throw new Error(
        'Giriş adresi yanlış yönlendi. https://www.zinesh.com açıp Ctrl+F5 ile yenileyin.'
      );
    }
    if (res.status >= 500) {
      throw new Error('Sunucu geçici olarak yanıt vermiyor. Birkaç saniye sonra tekrar deneyin.');
    }
    throw new Error('Sunucudan beklenmeyen yanıt alındı. Sayfayı yenileyip tekrar deneyin.');
  }
  if (!res.ok || !data.user) {
    if (res.status === 429) {
      throw new AuthHttpError(
        (data.message as string) || 'Bu hesap için başka bir giriş işlemi devam ediyor.',
        429
      );
    }
    if (data.needsPasswordLink) {
      throw new AuthPasswordLinkRequiredError(
        (data.message as string) || 'Google hesabını bağlamak için şifrenizi girin.',
        (data.oauthLinkState as string) || '',
        (data.email as string) || ''
      );
    }
    if (data.needsTotp || data.needsTotpSetup) {
      throw new AuthTotpRequiredError((data.message as string) || '2FA kodu gerekli.', {
        needsTotp: !!data.needsTotp,
        needsTotpSetup: !!data.needsTotpSetup,
        totpQrUri: (data.totpQrUri as string | null | undefined) ?? null,
      });
    }
    if (data.needsLoginVerification || data.login_verification_required) {
      throw new AuthLoginVerificationRequiredError(
        (data.message as string) || 'E-postana gönderilen doğrulama kodunu gir.',
        { mailSent: !!data.mailSent }
      );
    }
    const serverMsg = typeof data.message === 'string' ? data.message.trim() : '';
    if (serverMsg) {
      throw new Error(serverMsg);
    }
    if (res.status === 401) {
      throw new Error('E-posta veya şifre hatalı.');
    }
    if (res.status === 429) {
      throw new AuthHttpError('Çok fazla deneme. Bir dakika bekleyip tekrar dene.', 429);
    }
    throw new Error(
      `Giriş tamamlanamadı (HTTP ${res.status}). https://www.zinesh.com adresinden Ctrl+F5 ile yenileyip tekrar dene.`
    );
  }
  return data.user as UserProfile;
}

export function saveSession(user: UserProfile): void {
  const prev = getSession();
  const token = (user.sessionToken ?? prev?.sessionToken ?? readSessionCookie() ?? '').trim();
  if (!token) {
    // Mevcut oturumu token'sız güncellemeyle ezme (mobil Safari storage yarışı)
    return;
  }
  writeStoredSession({
    ...prev,
    ...user,
    sessionToken: token,
  });
}

export function getSession(): UserProfile | null {
  return readStoredSession();
}

export function clearSession(): void {
  const user = getSession();
  if (user?.sessionToken) {
    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'logout', sessionToken: user.sessionToken }),
    }).catch(() => {});
  }
  try {
    localStorage.removeItem(SESSION_KEY);
    sessionStorage.removeItem(SESSION_KEY);
    localStorage.removeItem('zinesh_remember_me');
    localStorage.removeItem('zinesh_session_expires_at');
  } catch {
    /* ignore */
  }
  clearSessionCookie();
}

/** Bozuk oturum / konsol çökmesi sonrası temiz başlangıç */
export function clearSessionAndReload(targetUrl = 'https://www.zinesh.com/'): void {
  clearSession();
  window.location.replace(targetUrl);
}

export function registerWithApi(
  name: string,
  email: string,
  password: string,
  role: UserProfile['role'],
  referralCode?: string
): Promise<UserProfile> {
  const body: Record<string, string> = { action: 'register', name, email, password, role };
  if (referralCode?.trim()) body.referralCode = referralCode.trim().toUpperCase();
  return postAuth(body).then((user) => {
    saveSession(user);
    return user;
  });
}

export function loginWithApi(
  email: string,
  password: string,
  totpCode?: string,
  loginVerificationCode?: string
): Promise<UserProfile> {
  const body: Record<string, string> = { action: 'login', email, password };
  if (totpCode?.trim()) body.totpCode = totpCode.trim();
  if (loginVerificationCode?.trim()) body.loginVerificationCode = loginVerificationCode.trim();
  return postAuth(body).then((user) => {
    const profile = { ...user, isFounder: resolveIsFounder(user) };
    saveSession(profile);
    return profile;
  });
}

const GOOGLE_AUTH_BRIDGE = 'https://decisive-patrol-dszp9.firebaseapp.com/google-auth.html';

async function getGoogleIdTokenDirect(): Promise<string> {
  const provider = new GoogleAuthProvider();
  provider.setCustomParameters({ prompt: 'select_account' });
  const cred = await signInWithPopup(auth, provider);
  return cred.user.getIdToken();
}

function getGoogleIdTokenViaBridge(): Promise<string> {
  return new Promise((resolve, reject) => {
    const openerOrigin = window.location.origin;
    const bridgeUrl = `${GOOGLE_AUTH_BRIDGE}?origin=${encodeURIComponent(openerOrigin)}`;
    const bridgeOrigin = new URL(GOOGLE_AUTH_BRIDGE).origin;

    let settled = false;
    const finish = (fn: () => void) => {
      if (settled) return;
      settled = true;
      window.removeEventListener('message', onMessage);
      window.clearTimeout(timer);
      fn();
    };

    const onMessage = (event: MessageEvent) => {
      if (event.origin !== bridgeOrigin) return;
      const data = event.data as { type?: string; idToken?: string; message?: string };
      if (data?.type === 'zinesh_google_auth' && data.idToken) {
        finish(() => resolve(data.idToken));
      }
      if (data?.type === 'zinesh_google_auth_error') {
        finish(() => reject(new Error(data.message || 'Google girişi başarısız.')));
      }
    };

    const timer = window.setTimeout(() => {
      finish(() => reject(new Error('Google girişi zaman aşımına uğradı.')));
    }, 120_000);

    window.addEventListener('message', onMessage);
    const popup = window.open(bridgeUrl, 'zinesh_google_auth', 'width=520,height=640');
    if (!popup) {
      finish(() => reject(new Error('Popup engellendi. Tarayıcıda açılır pencerelere izin ver.')));
    }
  });
}

export async function getGoogleIdToken(): Promise<string> {
  const host = window.location.hostname;
  const isProduction = host === 'zinesh.com' || host === 'www.zinesh.com';
  try {
    return await getGoogleIdTokenDirect();
  } catch (err: unknown) {
    const code = (err as { code?: string })?.code;
    if (code === 'auth/popup-closed-by-user' || code === 'auth/cancelled-popup-request') {
      throw new Error('Google girişi iptal edildi.');
    }
    if (code === 'auth/operation-not-allowed') {
      throw new Error('Google girişi Firebase\'de kapalı. Authentication ayarlarından açılmalı.');
    }
    if (code === 'auth/unauthorized-domain' && isProduction) {
      try {
        return await getGoogleIdTokenViaBridge();
      } catch (bridgeErr) {
        if (bridgeErr instanceof Error) throw bridgeErr;
        throw new Error('Google girişi kurulmadı. Geliştirici: npm run google:auth-setup');
      }
    }
    if (err instanceof Error) throw err;
    throw new Error('Google ile bağlanılamadı. Tekrar dene.');
  }
}

export function oauthWithGoogleIdToken(
  idToken: string,
  opts?: { referralCode?: string; totpCode?: string }
): Promise<UserProfile> {
  const body: Record<string, string> = { action: 'oauth_google', idToken };
  if (opts?.referralCode?.trim()) body.referralCode = opts.referralCode.trim().toUpperCase();
  if (opts?.totpCode?.trim()) body.totpCode = opts.totpCode.trim();
  return postAuth(body).then((user) => {
    const profile = { ...user, isFounder: resolveIsFounder(user) };
    saveSession(profile);
    return profile;
  });
}

export function oauthWithGoogleAccessToken(
  accessToken: string,
  opts?: { referralCode?: string; totpCode?: string }
): Promise<UserProfile> {
  const body: Record<string, string> = { action: 'oauth_google_access', accessToken };
  if (opts?.referralCode?.trim()) body.referralCode = opts.referralCode.trim().toUpperCase();
  if (opts?.totpCode?.trim()) body.totpCode = opts.totpCode.trim();
  return postAuth(body).then((user) => {
    const profile = { ...user, isFounder: resolveIsFounder(user) };
    saveSession(profile);
    return profile;
  });
}

export async function loginWithGoogleGsi(opts?: {
  referralCode?: string;
  totpCode?: string;
}): Promise<UserProfile> {
  const accessToken = await getGoogleAccessTokenViaGsi();
  return oauthWithGoogleAccessToken(accessToken, opts);
}

export async function loginWithGoogle(opts?: {
  referralCode?: string;
  totpCode?: string;
}): Promise<UserProfile> {
  const idToken = await getGoogleIdToken();
  return oauthWithGoogleIdToken(idToken, opts);
}

export type GoogleOAuthPublicConfig = {
  enabled: boolean;
  mode: 'redirect' | 'popup';
  clientId: string | null;
};

let googleOAuthConfigCache: GoogleOAuthPublicConfig | null = null;

export function isZineshProductionHost(): boolean {
  const host = window.location.hostname;
  return host === 'zinesh.com' || host === 'www.zinesh.com';
}

export async function fetchGoogleOAuthConfig(): Promise<GoogleOAuthPublicConfig> {
  if (googleOAuthConfigCache) return googleOAuthConfigCache;
  // Canlıda GSI (access token) kullan — client_secret gerekmez.
  // Redirect OAuth, sunucudaki secret geçersiz/eski olduğunda "client secret invalid" verir.
  if (
    isZineshProductionHost() ||
    (typeof window !== 'undefined' && window.location.hostname === 'app.zinesh.com')
  ) {
    googleOAuthConfigCache = {
      enabled: true,
      mode: 'popup',
      clientId: ZINESH_GOOGLE_CLIENT_ID,
    };
    return googleOAuthConfigCache;
  }
  try {
    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'oauth_config' }),
    });
    const data = await res.json().catch(() => ({}));
    const google = (data.google ?? {}) as Partial<GoogleOAuthPublicConfig>;
    googleOAuthConfigCache = {
      enabled: google.enabled === true,
      mode: google.mode === 'redirect' ? 'redirect' : 'popup',
      clientId: typeof google.clientId === 'string' ? google.clientId : null,
    };
    return googleOAuthConfigCache;
  } catch {
    return { enabled: true, mode: 'popup', clientId: ZINESH_GOOGLE_CLIENT_ID };
  }
}

export function startGoogleRedirectLogin(referralCode?: string): void {
  const params = new URLSearchParams({ action: 'start' });
  const ref = referralCode?.trim();
  if (ref) params.set('referralCode', ref.toUpperCase());
  window.location.href = apiUrl(`/api/google_auth.php?${params.toString()}`);
}

export async function exchangeGoogleOAuthCode(code?: string): Promise<UserProfile> {
  const body: Record<string, string> = { action: 'oauth_exchange' };
  if (code?.trim()) body.code = code.trim();
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    throw new Error((data.message as string) || 'Google oturumu tamamlanamadı.');
  }
  const profile = { ...(data.user as UserProfile), isFounder: resolveIsFounder(data.user as UserProfile) };
  saveSession(profile);
  return profile;
}

export async function completeGoogleOAuthLink(
  oauthLinkState: string,
  password: string
): Promise<UserProfile> {
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'oauth_confirm_link', oauthLinkState, password }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    if (data.needsTotp || data.needsTotpSetup) {
      throw new AuthTotpRequiredError((data.message as string) || '2FA kodu gerekli.', {
        needsTotp: !!data.needsTotp,
        needsTotpSetup: !!data.needsTotpSetup,
        totpQrUri: data.totpQrUri ?? null,
      });
    }
    throw new Error((data.message as string) || 'Google bağlantısı başarısız.');
  }
  const profile = { ...(data.user as UserProfile), isFounder: resolveIsFounder(data.user as UserProfile) };
  saveSession(profile);
  return profile;
}

export async function completeGoogleOAuthTotp(oauthState: string, totpCode: string): Promise<UserProfile> {
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'oauth_google_totp', oauthState, totpCode }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    if (data.needsTotp || data.needsTotpSetup) {
      throw new AuthTotpRequiredError((data.message as string) || '2FA kodu gerekli.', {
        needsTotp: !!data.needsTotp,
        needsTotpSetup: !!data.needsTotpSetup,
        totpQrUri: data.totpQrUri ?? null,
      });
    }
    throw new Error((data.message as string) || 'Google ile giriş başarısız.');
  }
  const profile = { ...(data.user as UserProfile), isFounder: resolveIsFounder(data.user as UserProfile) };
  saveSession(profile);
  return profile;
}

function authPayload(extra: Record<string, string> = {}): Record<string, string> {
  const session = getSession();
  const pick = (key: 'sessionToken' | 'email' | 'uid') => {
    const fromExtra = (extra[key] ?? '').trim();
    if (fromExtra) return fromExtra;
    const fromSession = session?.[key];
    return typeof fromSession === 'string' ? fromSession.trim() : '';
  };
  return {
    sessionToken: pick('sessionToken'),
    email: pick('email'),
    uid: pick('uid'),
  };
}

let refreshInFlight: Promise<UserProfile> | null = null;

export async function refreshSession(opts?: { timeoutMs?: number }): Promise<UserProfile> {
  if (refreshInFlight) {
    return refreshInFlight;
  }

  refreshInFlight = (async () => {
    const session = getSession();
    const sessionToken = session?.sessionToken;
    if (!sessionToken && !session?.email) {
      throw new Error('Oturum bulunamadı.');
    }
    const timeoutMs = opts?.timeoutMs ?? 25000;
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    let res: Response;
    try {
      res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'session', ...authPayload() }),
        signal: controller.signal,
      });
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        throw new Error('Oturum yenileme zaman aşımına uğradı.');
      }
      throw new Error('Sunucuya bağlanılamadı.');
    } finally {
      window.clearTimeout(timer);
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.user) {
      throw new AuthHttpError(
        (typeof data.message === 'string' && data.message) || 'Oturum yenilenemedi.',
        res.status
      );
    }
    const user = data.user as UserProfile;
    const token = user.sessionToken ?? sessionToken;
    const isFounder = Boolean((data as { isFounder?: boolean }).isFounder ?? user.isFounder);
    const profile = {
      ...session,
      ...user,
      sessionToken: token,
      isFounder,
    };
    saveSession(profile);
    return { ...profile, sessionToken: token };
  })().finally(() => {
    refreshInFlight = null;
  });

  return refreshInFlight;
}

export async function verifyEmailCode(
  code: string,
  opts?: { email?: string; uid?: string; sessionToken?: string }
): Promise<{
  message: string;
  user: UserProfile;
  grant?: { claimed?: boolean; amount?: number };
}> {
  const normalized = code.replace(/\D/g, '');
  const padded = normalized.length > 0 && normalized.length < 6
    ? normalized.padStart(6, '0')
    : normalized;
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'verify_email_code',
        code: padded,
        ...authPayload({
          email: opts?.email,
          uid: opts?.uid,
          sessionToken: opts?.sessionToken,
        } as Record<string, string>),
      }),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    throw new Error(data.message || 'Kod doğrulanamadı.');
  }
  const user = data.user as UserProfile;
  saveSession(user);
  return {
    message: data.message || 'E-posta doğrulandı.',
    user,
    grant: data.grant,
  };
}

export async function verifyEmailToken(token: string): Promise<{
  message: string;
  user: UserProfile;
  grant?: { claimed?: boolean; amount?: number };
}> {
  const session = getSession();
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'verify_email',
        token: token.trim(),
        ...authPayload(),
      }),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    throw new Error(data.message || 'E-posta doğrulanamadı.');
  }
  const user = data.user as UserProfile;
  saveSession(user);
  return {
    message: data.message || 'E-posta doğrulandı.',
    user,
    grant: data.grant,
  };
}

export async function fetchVerificationLink(opts?: {
  email?: string;
  uid?: string;
  sessionToken?: string;
}): Promise<{ message: string; verifyLink: string; mailSent?: boolean; user?: UserProfile }> {
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'verification_link',
        ...authPayload({
          email: opts?.email,
          uid: opts?.uid,
          sessionToken: opts?.sessionToken,
        } as Record<string, string>),
      }),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || 'Doğrulama bağlantısı alınamadı.');
  }
  if (data.user) {
    saveSession(data.user as UserProfile);
  }
  return {
    message: data.message || 'Doğrulama bağlantın hazır.',
    verifyLink: data.verifyLink || '',
    mailSent: data.mailSent,
    user: data.user,
  };
}

export async function resendVerificationEmail(opts?: {
  email?: string;
  uid?: string;
  sessionToken?: string;
}): Promise<{ message: string; mailSent?: boolean; retryAfter?: number; user?: UserProfile }> {
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'send_verification_code',
        ...authPayload({
          email: opts?.email,
          uid: opts?.uid,
          sessionToken: opts?.sessionToken,
        } as Record<string, string>),
      }),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.message || 'Kod gönderilemedi.') as Error & { retryAfter?: number };
    if (typeof data.retryAfter === 'number') {
      err.retryAfter = data.retryAfter;
    }
    throw err;
  }
  if (data.user) {
    saveSession(data.user as UserProfile);
  }
  return {
    message: data.message || 'Doğrulama kodu gönderildi.',
    mailSent: data.mailSent,
    retryAfter: typeof data.retryAfter === 'number' ? data.retryAfter : 60,
    user: data.user,
  };
}

export async function submitKyc(fields: {
  fullName: string;
  nationalId: string;
  birthDate: string;
  phone: string;
}): Promise<{ message: string; user: UserProfile; grant?: { claimed?: boolean; amount?: number } }> {
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'submit_kyc',
        fullName: fields.fullName.trim(),
        nationalId: fields.nationalId.replace(/\D/g, ''),
        birthDate: fields.birthDate.trim(),
        phone: fields.phone.replace(/\D/g, ''),
        ...authPayload(),
      }),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.user) {
    throw new Error(data.message || 'KYC gönderilemedi.');
  }
  const user = data.user as UserProfile;
  saveSession(user);
  return {
    message: data.message || 'KYC tamamlandı.',
    user,
    grant: data.grant,
  };
}

async function postAuthAction(body: Record<string, string>): Promise<Record<string, unknown>> {
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı. İnternet bağlantınızı kontrol edin.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    const err = new Error((data.message as string) || 'İşlem başarısız.') as Error & { retryAfter?: number };
    if (typeof data.retryAfter === 'number') err.retryAfter = data.retryAfter;
    throw err;
  }
  return data as Record<string, unknown>;
}

export async function sendForgotPasswordCode(email: string): Promise<{
  message: string;
  retryAfter?: number;
}> {
  const data = await postAuthAction({ action: 'forgot_password_send', email: email.trim().toLowerCase() });
  return {
    message: (data.message as string) || 'Kod gönderildi.',
    retryAfter: typeof data.retryAfter === 'number' ? data.retryAfter : undefined,
  };
}

export async function verifyForgotPasswordCode(email: string, code: string): Promise<{ message: string }> {
  const normalized = code.replace(/\D/g, '').padStart(6, '0').slice(-6);
  const data = await postAuthAction({
    action: 'forgot_password_verify',
    email: email.trim().toLowerCase(),
    code: normalized,
  });
  return { message: (data.message as string) || 'Kod doğrulandı.' };
}

export async function resetPasswordWithCode(
  email: string,
  code: string,
  newPassword: string,
  confirmPassword: string
): Promise<{ message: string; requiresLogin: boolean }> {
  const normalized = code.replace(/\D/g, '').padStart(6, '0').slice(-6);
  const data = await postAuthAction({
    action: 'forgot_password_reset',
    email: email.trim().toLowerCase(),
    code: normalized,
    newPassword,
    confirmPassword,
  });
  return {
    message: (data.message as string) || 'Şifre güncellendi.',
    requiresLogin: Boolean(data.requiresLogin),
  };
}

export async function changePassword(
  currentPassword: string,
  newPassword: string,
  confirmPassword: string
): Promise<{ message: string; requiresLogin: boolean }> {
  const data = await postAuthAction({
    action: 'change_password',
    currentPassword,
    newPassword,
    confirmPassword,
    ...authPayload(),
  });
  if (data.requiresLogin) {
    clearSession();
  }
  return {
    message: (data.message as string) || 'Şifre güncellendi.',
    requiresLogin: Boolean(data.requiresLogin),
  };
}

export async function sendTotpResetCode(
  email: string,
  password: string
): Promise<{ message: string; retryAfter?: number }> {
  const data = await postAuthAction({
    action: 'totp_reset_send_code',
    email: email.trim().toLowerCase(),
    password,
  });
  return {
    message: (data.message as string) || 'Kod gönderildi.',
    retryAfter: typeof data.retryAfter === 'number' ? data.retryAfter : undefined,
  };
}

export async function confirmTotpReset(
  email: string,
  password: string,
  code: string
): Promise<{ message: string; totpQrUri?: string | null }> {
  const normalized = code.replace(/\D/g, '').padStart(6, '0').slice(-6);
  const data = await postAuthAction({
    action: 'totp_reset_confirm',
    email: email.trim().toLowerCase(),
    password,
    code: normalized,
  });
  return {
    message: (data.message as string) || 'Authenticator sıfırlandı.',
    totpQrUri: (data.totpQrUri as string | null | undefined) ?? null,
  };
}
