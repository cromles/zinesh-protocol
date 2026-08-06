import { CURRENCY_SYMBOL, TL_MODE } from './productMode';

export function formatMoney(amount: number, opts?: { decimals?: number }): string {
  const decimals = opts?.decimals ?? (Number.isInteger(amount) ? 0 : 2);
  const formatted = amount.toLocaleString('tr-TR', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  if (TL_MODE) return `${formatted} ${CURRENCY_SYMBOL}`;
  return `$${formatted}`;
}

/** Site cüzdanı bakiyesi — TL modunda usdtBalance alanı TL olarak gösterilir. */
export function siteWalletBalance(wallet: { usdtBalance?: number; availableUsdt?: number }): number {
  return wallet.availableUsdt ?? wallet.usdtBalance ?? 0;
}
