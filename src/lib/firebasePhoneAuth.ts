import type { ConfirmationResult, RecaptchaVerifier } from 'firebase/auth';
import { getFirebaseAuth } from './firebase';

export function formatPhoneE164TR(input: string): string {
  let digits = input.replace(/\D/g, '');
  if (digits.startsWith('90') && digits.length === 12) {
    digits = digits.slice(2);
  }
  if (digits.startsWith('0') && digits.length === 11) {
    digits = digits.slice(1);
  }
  if (digits.length !== 10) {
    throw new Error('Geçerli 10 haneli cep telefonu numarası gir (5XX XXX XX XX).');
  }
  return `+90${digits}`;
}

function firebasePhoneErrorMessage(code: string, fallback: string): string {
  const map: Record<string, string> = {
    'auth/invalid-phone-number': 'Telefon numarası geçersiz.',
    'auth/missing-phone-number': 'Telefon numarası gerekli.',
    'auth/too-many-requests': 'Çok fazla SMS isteği. Bir süre bekleyip tekrar dene.',
    'auth/quota-exceeded': 'SMS kotası doldu. Lütfen daha sonra tekrar dene.',
    'auth/captcha-check-failed': 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.',
    'auth/invalid-verification-code': 'SMS kodu hatalı.',
    'auth/code-expired': 'SMS kodunun süresi doldu. Yeni kod iste.',
    'auth/session-expired': 'Doğrulama oturumu sona erdi. Yeni kod iste.',
    'auth/operation-not-allowed':
      'SMS gönderilemedi. Firebase Console → Authentication → Ayarlar → SMS bölge politikası bölümünde Türkiye (+90) izinli olmalı. Telefon sağlayıcısı da etkin olmalı.',
    'auth/billing-not-enabled':
      'Gerçek SMS için Firebase projesini Blaze (faturalandırma) planına yükseltmen gerekir. Test numarası ile deneyebilirsin.',
  };
  return map[code] ?? fallback;
}

function extractFirebaseError(err: unknown): string {
  if (err && typeof err === 'object' && 'code' in err) {
    const code = String((err as { code?: string }).code ?? '');
    const message = String((err as { message?: string }).message ?? 'SMS doğrulaması başarısız.');
    return firebasePhoneErrorMessage(code, message);
  }
  return err instanceof Error ? err.message : 'SMS doğrulaması başarısız.';
}

export type PhoneOtpSession = {
  confirmation: ConfirmationResult;
  e164: string;
  cleanup: () => void;
};

export async function startFirebasePhoneOtp(
  phoneInput: string,
  recaptchaContainerId: string,
): Promise<PhoneOtpSession> {
  const e164 = formatPhoneE164TR(phoneInput);
  const auth = await getFirebaseAuth();
  const { RecaptchaVerifier, signInWithPhoneNumber } = await import('firebase/auth');

  const container = document.getElementById(recaptchaContainerId);
  if (!container) {
    throw new Error('reCAPTCHA alanı bulunamadı. Sayfayı yenileyip tekrar dene.');
  }

  const verifier = new RecaptchaVerifier(auth, recaptchaContainerId, {
    size: 'invisible',
  });

  try {
    const confirmation = await signInWithPhoneNumber(auth, e164, verifier);
    return {
      confirmation,
      e164,
      cleanup: () => {
        try {
          verifier.clear();
        } catch {
          /* ignore */
        }
      },
    };
  } catch (err) {
    try {
      verifier.clear();
    } catch {
      /* ignore */
    }
    throw new Error(extractFirebaseError(err));
  }
}

export async function confirmFirebasePhoneOtp(
  session: PhoneOtpSession,
  code: string,
): Promise<string> {
  const digits = code.replace(/\D/g, '');
  if (digits.length !== 6) {
    throw new Error('6 haneli SMS kodunu gir.');
  }

  const auth = await getFirebaseAuth();
  const { signOut } = await import('firebase/auth');

  try {
    const credential = await session.confirmation.confirm(digits);
    const idToken = await credential.user.getIdToken(true);
    if (!idToken) {
      throw new Error('Firebase doğrulama token\'ı alınamadı.');
    }
    return idToken;
  } catch (err) {
    throw new Error(extractFirebaseError(err));
  } finally {
    session.cleanup();
    try {
      await signOut(auth);
    } catch {
      /* ignore — yalnızca OTP doğrulama oturumu */
    }
  }
}

export function disposeRecaptcha(verifier: RecaptchaVerifier | null) {
  if (!verifier) return;
  try {
    verifier.clear();
  } catch {
    /* ignore */
  }
}
