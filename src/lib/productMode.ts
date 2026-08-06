/**
 * Ürün modu — TL emanet kasası, sözleşme öncelikli güven protokolü.
 * Faz 2: iyzico/PayTR ile gerçek TL yatırma.
 */
export const WEB3_ENABLED = false;
export const TL_MODE = true;
/** Sadece emanetçi — iş ilanı / pazar yeri yok */
export const ESCROW_ONLY = true;
export const CURRENCY_CODE = 'TRY' as const;
export const CURRENCY_SYMBOL = '₺';
export const CURRENCY_NAME = 'TL';
