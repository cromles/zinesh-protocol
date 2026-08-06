/** Google Identity Services — client_secret gerektirmez (zinesh-auth OAuth client). */

export const ZINESH_GOOGLE_CLIENT_ID =
  '970378833039-dv1mpkbp7448amub6ueng5j8nop31cd2.apps.googleusercontent.com';

type GsiTokenResponse = {
  access_token?: string;
  error?: string;
  error_description?: string;
};

type GsiTokenClient = {
  requestAccessToken: (overrideConfig?: { prompt?: string }) => void;
};

declare global {
  interface Window {
    google?: {
      accounts?: {
        oauth2?: {
          initTokenClient: (config: {
            client_id: string;
            scope: string;
            callback: (response: GsiTokenResponse) => void;
            error_callback?: (err: unknown) => void;
          }) => GsiTokenClient;
        };
      };
    };
  }
}

let gsiScriptPromise: Promise<void> | null = null;

export function loadGoogleGsiScript(): Promise<void> {
  if (window.google?.accounts?.oauth2) return Promise.resolve();
  if (gsiScriptPromise) return gsiScriptPromise;
  gsiScriptPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>('script[data-zinesh-gsi="1"]');
    if (existing) {
      existing.addEventListener('load', () => resolve(), { once: true });
      existing.addEventListener('error', () => reject(new Error('Google script yüklenemedi.')), {
        once: true,
      });
      return;
    }
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    script.dataset.zineshGsi = '1';
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Google script yüklenemedi.'));
    document.head.appendChild(script);
  });
  return gsiScriptPromise;
}

export async function getGoogleAccessTokenViaGsi(): Promise<string> {
  await loadGoogleGsiScript();
  const oauth2 = window.google?.accounts?.oauth2;
  if (!oauth2) {
    throw new Error('Google giriş bileşeni yüklenemedi.');
  }

  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (fn: () => void) => {
      if (settled) return;
      settled = true;
      fn();
    };

    const client = oauth2.initTokenClient({
      client_id: ZINESH_GOOGLE_CLIENT_ID,
      scope: 'openid email profile',
      callback: (response) => {
        if (response.access_token) {
          finish(() => resolve(response.access_token));
          return;
        }
        const detail = response.error_description || response.error || '';
        finish(() =>
          reject(new Error(detail ? `Google girişi başarısız: ${detail}` : 'Google girişi iptal edildi.'))
        );
      },
      error_callback: (err) => {
        const msg = err instanceof Error ? err.message : 'Google girişi iptal edildi.';
        finish(() => reject(new Error(msg)));
      },
    });

    client.requestAccessToken({ prompt: 'select_account' });
  });
}
