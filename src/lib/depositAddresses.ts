/** Resmi Zinesh kasa adresleri — API yanıt vermezse yedek. */
export const FALLBACK_DEPOSIT_ADDRESSES: Record<
  string,
  { label: string; address: string; asset: string; hint?: string }
> = {
  tron: {
    label: 'TRON (TRC20)',
    address: 'TNNP6ehRJ9EYXddZkbsneJfMBQx7rdytRr',
    asset: 'USDT',
    hint: 'Binance TR',
  },
  arbitrum: {
    label: 'Arbitrum',
    address: '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
    asset: 'USDT',
    hint: 'Arbitrum ağında USDT gönder',
  },
  ethereum: {
    label: 'Ethereum',
    address: '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
    asset: 'USDT',
    hint: 'Ethereum ağında USDT gönder',
  },
  solana: {
    label: 'Solana',
    address: 'FZVwrPoJ4qbwAmxWwscgmZgsEYcojD69j4L19V3QJCtQ',
    asset: 'USDT',
    hint: 'SPL',
  },
};

export function mergeDepositAddresses(
  fromApi: Record<string, { label: string; address: string; asset: string }> | undefined
): Record<string, { label: string; address: string; asset: string }> {
  const merged = { ...FALLBACK_DEPOSIT_ADDRESSES };
  if (fromApi) {
    for (const [key, val] of Object.entries(fromApi)) {
      if (val?.address?.trim()) {
        merged[key] = { ...merged[key], ...val, address: val.address.trim() };
      }
    }
  }
  return merged;
}
