import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';
import type { TlHavaleInfo } from './walletApi';

const API = apiUrl('/api/payment.php');

export interface HavaleStatusInfo {
  enabled: boolean;
  iban: string;
  accountHolder: string;
  bankName: string;
  minDepositTry: number;
}

export interface PaymentStatus {
  tlMode: boolean;
  paymentEnabled: boolean;
  havaleEnabled?: boolean;
  havale?: HavaleStatusInfo;
  minDepositTry: number;
  maxDepositTry: number;
  sandbox: boolean;
}

export interface DepositCheckoutResult {
  ok: boolean;
  message?: string;
  configured?: boolean;
  paymentPageUrl?: string;
  conversationId?: string;
}

export async function fetchPaymentStatus(): Promise<PaymentStatus> {
  const res = await fetch(`${API}?action=status`);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || 'Ödeme durumu alınamadı');
  return data as PaymentStatus;
}

export async function fetchHavaleInfo(): Promise<TlHavaleInfo | null> {
  const sessionToken = getSessionToken();
  if (!sessionToken) return null;
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'havale_info', sessionToken }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.havale) return null;
  return data.havale as TlHavaleInfo;
}

export async function createTlDeposit(amountTry: number): Promise<DepositCheckoutResult> {
  const sessionToken = getSessionToken();
  if (!sessionToken) throw new Error('Oturum gerekli');
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'create_deposit', sessionToken, amount: amountTry }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    return { ok: false, message: data.message || 'Ödeme başlatılamadı', configured: data.configured };
  }
  return data as DepositCheckoutResult;
}
