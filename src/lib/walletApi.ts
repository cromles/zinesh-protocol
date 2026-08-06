import type { UserProfile } from './userProfile';
import { getSession, getSessionToken, saveSession } from './auth';
import { bumpSessionActivity } from './sessionIdle';
import { FALLBACK_DEPOSIT_ADDRESSES, mergeDepositAddresses } from './depositAddresses';
import { apiUrl } from './apiBase';

const API = apiUrl('/api/wallet.php');

export type DepositNetwork = 'tron' | 'arbitrum' | 'ethereum' | 'solana';

export interface ConnectedWallet {
  id: string;
  name: string;
  network: string;
  address: string;
  system?: boolean;
  treasury?: boolean;
}

export interface WalletTransaction {
  id: string;
  type: 'deposit' | 'withdrawal' | 'swap' | 'escrow_send' | 'escrow_receive' | 'escrow_lock' | 'escrow_reconcile_debit';
  amount: string;
  asset: string;
  txHash: string;
  date: string;
  createdAt?: string;
  status: 'completed' | 'processing' | 'failed';
  network?: string;
  label?: string;
}

export interface WalletLiquidity {
  treasuryUsdt: number;
  totalUserUsdt: number;
  availableUsdt: number;
  liquidityGap: number;
  liquidityRatio: number;
}

export interface WalletState {
  usdtBalance: number;
  fiziBalance: number;
  campaignFiziBalance?: number;
  sellableFiziBalance?: number;
  escrowBalance: number;
  fiziEscrowBalance?: number;
  availableFizi?: number;
  availableUsdt: number;
  maxSellGrossUsdt?: number;
  liquidity?: WalletLiquidity;
  connectedWallets: ConnectedWallet[];
  depositAddresses: Record<string, { label: string; address: string; asset: string }>;
  transactions: WalletTransaction[];
  fiziUsdtRate: number;
  fiziCurrentPrice?: number;
  fiziPriceFloor?: number;
  treasuryUsdtTotal?: number;
  circulatingFizi?: number;
  swapFeeRate: number;
  minDepositUsdt: number;
  minWithdrawUsdt: number;
  treasuryUsdtAvailable?: Partial<Record<DepositNetwork, number | null>>;
  autoWithdraw?: Partial<Record<DepositNetwork, boolean>>;
  depositsEnabled?: boolean;
  siteFiziLedgerEnabled?: boolean;
  platformFiziBalance?: number;
  platformFiziEnabled?: boolean;
  tlHavale?: TlHavaleInfo;
}

export interface TlHavaleInfo {
  enabled: boolean;
  iban: string;
  accountHolder: string;
  bankName: string;
  minDepositTry: number;
  reference: string;
  instructions?: string;
}

function token(): string {
  const t = getSessionToken();
  if (!t) throw new Error('Oturum bulunamadı. Lütfen tekrar giriş yapın.');
  return t;
}

async function postWallet(body: Record<string, unknown>): Promise<{
  ok: boolean;
  message?: string;
  wallet?: WalletState;
  user?: UserProfile;
  withdrawal?: { id: string; txHash: string; status: string };
}> {
  const session = getSession();
  const payload = {
    ...body,
    sessionToken: (body.sessionToken as string | undefined) ?? token(),
    uid: (body.uid as string | undefined) ?? session?.uid ?? '',
    email: (body.email as string | undefined) ?? session?.email ?? '',
  };
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = await res.json().catch(() => ({} as Record<string, unknown>));
  if (!res.ok) {
    const msg =
      (typeof data.message === 'string' && data.message) ||
      (res.status === 502
        ? 'Sunucu geçici olarak yanıt veremedi. Sayfayı yenileyip tekrar dene.'
        : '') ||
      'İşlem başarısız.';
    throw new Error(msg);
  }
  bumpSessionActivity();
  if (data.user) {
    const prev = getSession();
    const token = prev?.sessionToken ?? getSessionToken();
    saveSession({
      ...prev,
      ...(data.user as UserProfile),
      sessionToken: token,
      ...(data.isFounder !== undefined ? { isFounder: Boolean(data.isFounder) } : {}),
    });
  } else if (data.isFounder !== undefined) {
    const prev = getSession();
    if (prev) {
      saveSession({ ...prev, isFounder: Boolean(data.isFounder) });
    }
  }
  return data as typeof data & { isFounder?: boolean };
}

export async function fetchDepositAddresses(): Promise<
  Record<string, { label: string; address: string; asset: string }>
> {
  try {
    const res = await fetch(apiUrl('/api/auth.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'deposit_addresses' }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.depositAddresses) {
      return mergeDepositAddresses(data.depositAddresses);
    }
  } catch {
    /* ağ hatası — yedek adresler */
  }
  return mergeDepositAddresses(FALLBACK_DEPOSIT_ADDRESSES);
}

export interface CampaignProgress {
  foundingMember: boolean;
  foundingMemberNumber?: number | null;
  referralCode?: string | null;
  fiziEarnedFromCampaign: number;
  fiziMaxFromCampaign: number;
  platformFiziBalance?: number;
  platformFiziPending?: number;
  platformFiziReleased?: boolean;
  claimed?: Record<string, boolean> & {
    deposit_10_usdt?: boolean;
    first_fizi_buy?: boolean;
  };
  stats?: Record<string, number | boolean> & {
    verifiedDepositUsdt?: number;
    firstFiziPurchase?: boolean;
    minDepositUsdt?: number;
  };
}

export async function fetchWalletState(): Promise<
  WalletState & { campaign?: CampaignProgress; isFounder?: boolean }
> {
  const data = await postWallet({ action: 'state', sessionToken: token() });
  if (!data.wallet) throw new Error('Cüzdan verisi alınamadı.');
  const campaign = (data as { campaign?: CampaignProgress }).campaign;
  const extra = data as { isFounder?: boolean };
  return {
    ...data.wallet,
    depositAddresses: mergeDepositAddresses(data.wallet.depositAddresses),
    campaign,
    isFounder: extra.isFounder,
  };
}

export async function verifyDeposit(network: DepositNetwork, txHash: string): Promise<WalletState> {
  const data = await postWallet({
    action: 'verify_deposit',
    sessionToken: token(),
    network,
    txHash: txHash.trim(),
  });
  if (!data.wallet) throw new Error('Yatırma onaylanamadı.');
  return data.wallet;
}

export async function reportHavaleDeposit(
  amountTry: number,
  note = ''
): Promise<{ wallet?: WalletState; message: string; autoApproved?: boolean }> {
  const data = await postWallet({
    action: 'report_havale_deposit',
    sessionToken: token(),
    amountTry,
    note: note.trim(),
  });
  return {
    wallet: data.wallet,
    message: data.message || 'Havale bildirimin alındı.',
    autoApproved: Boolean((data as { autoApproved?: boolean }).autoApproved),
  };
}

export interface WithdrawResult {
  wallet: WalletState;
  message: string;
  withdrawal?: { id: string; txHash: string; status: string };
}

export async function requestWithdraw(
  network: string,
  address: string,
  amount: number
): Promise<WithdrawResult> {
  const data = await postWallet({
    action: 'withdraw_request',
    sessionToken: token(),
    network,
    address,
    amount,
  });
  if (!data.wallet) throw new Error('Çekim talebi oluşturulamadı.');
  return {
    wallet: data.wallet,
    message: data.message || 'Çekim talebin alındı.',
    withdrawal: data.withdrawal as WithdrawResult['withdrawal'],
  };
}

export async function reportJuryCampaign(correct = true): Promise<WalletState> {
  const data = await postWallet({
    action: 'campaign_jury',
    sessionToken: token(),
    correct,
  });
  if (!data.wallet) throw new Error('Jüri kampanyası kaydedilemedi.');
  return data.wallet;
}

export async function addConnectedWallet(
  name: string,
  network: string,
  address: string
): Promise<WalletState> {
  const data = await postWallet({
    action: 'add_wallet',
    sessionToken: token(),
    name,
    network,
    address,
  });
  if (!data.wallet) throw new Error('Cüzdan kaydedilemedi.');
  return data.wallet;
}

export async function lockEscrow(amount: number, reason = 'escrow'): Promise<WalletState> {
  const data = await postWallet({
    action: 'escrow_lock',
    sessionToken: token(),
    amount,
    reason,
  });
  if (!data.wallet) throw new Error('Escrow kilidi başarısız.');
  return data.wallet;
}

export interface CreateEscrowJobInput {
  amount: number;
  title: string;
  description: string;
  /** Boş bırakılırsa WhatsApp eşleşme kodu üretilir; para karşı taraf katılınca kilitlenir. */
  supplierEmail?: string;
  supplierName?: string;
  deliveryDate?: string;
  category?: string;
  matchOnly?: boolean;
}

export async function createEscrowJob(input: CreateEscrowJobInput): Promise<{
  wallet: WalletState;
  job: import('./trustProfileApi').EscrowJob;
  matchCode?: string;
  message?: string;
}> {
  const matchOnly = Boolean(input.matchOnly || !input.supplierEmail?.trim());
  const data = await postWallet({
    action: 'escrow_create',
    sessionToken: token(),
    amount: input.amount,
    title: input.title,
    description: input.description,
    supplierEmail: input.supplierEmail?.trim().toLowerCase() ?? '',
    supplierName: input.supplierName ?? '',
    deliveryDate: input.deliveryDate ?? '',
    category: input.category ?? 'Emanet',
    matchOnly,
  });
  if (!data.wallet || !data.job) throw new Error('Emanet oluşturulamadı.');
  return {
    wallet: data.wallet,
    job: data.job as import('./trustProfileApi').EscrowJob,
    matchCode: typeof data.matchCode === 'string' ? data.matchCode : undefined,
    message: typeof data.message === 'string' ? data.message : undefined,
  };
}

export async function joinEscrowByCode(code: string): Promise<{
  wallet: WalletState;
  job: import('./trustProfileApi').EscrowJob;
  message?: string;
}> {
  const data = await postWallet({
    action: 'escrow_join',
    sessionToken: token(),
    code: code.trim().toUpperCase(),
  });
  if (!data.wallet || !data.job) throw new Error(data.message || 'Eşleşme başarısız.');
  return {
    wallet: data.wallet,
    job: data.job as import('./trustProfileApi').EscrowJob,
    message: typeof data.message === 'string' ? data.message : undefined,
  };
}

export async function fetchEscrowJobs(): Promise<{
  jobs: import('./trustProfileApi').EscrowJob[];
  stats: import('./trustProfileApi').EscrowJobStats;
  trustScore: number;
}> {
  const data = await postWallet({
    action: 'escrow_jobs_list',
    sessionToken: token(),
  });
  if (!data.jobs) throw new Error('İş listesi alınamadı.');
  return {
    jobs: data.jobs as import('./trustProfileApi').EscrowJob[],
    stats: (data.stats as import('./trustProfileApi').EscrowJobStats) ?? {
      active: 0,
      completed: 0,
      unsuccessful: 0,
    },
    trustScore: Number(data.trustScore ?? 50),
  };
}

export async function settleEscrow(
  value: number,
  supplierEmail: string,
  jobId?: string
): Promise<WalletState> {
  if (!jobId) throw new Error('İş kimliği gerekli.');
  return confirmEscrowJobComplete(jobId);
}

export async function confirmEscrowJobComplete(jobId: string): Promise<WalletState> {
  const data = await postWallet({
    action: 'escrow_job_confirm_complete',
    sessionToken: token(),
    jobId,
  });
  if (!data.wallet) throw new Error((data as { message?: string }).message || 'Onay alınamadı.');
  return data.wallet;
}

export async function cancelEscrowJob(
  jobId: string,
  _status: 'cancelled' | 'failed' = 'cancelled'
): Promise<WalletState> {
  const data = await postWallet({
    action: 'escrow_job_request_cancel',
    sessionToken: token(),
    jobId,
  });
  if (!data.wallet) throw new Error((data as { message?: string }).message || 'İş iptal edilemedi.');
  return data.wallet;
}

export function applyWalletToState(
  wallet: WalletState,
  setters: {
    setUsdtBalance: (n: number) => void;
    setWalletTxLogs: (logs: WalletTransaction[]) => void;
    setConnectedWallets: (w: ConnectedWallet[]) => void;
    setAvailableUsdt?: (n: number) => void;
  }
): void {
  setters.setUsdtBalance(wallet.usdtBalance);
  setters.setWalletTxLogs(wallet.transactions);
  setters.setConnectedWallets(wallet.connectedWallets);
  if (setters.setAvailableUsdt) {
    const free =
      typeof wallet.availableUsdt === 'number'
        ? wallet.availableUsdt
        : Math.max(0, wallet.usdtBalance - (wallet.escrowBalance ?? 0));
    setters.setAvailableUsdt(free);
  }
}
