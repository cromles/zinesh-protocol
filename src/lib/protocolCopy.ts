import { TL_MODE } from './productMode';

/** Canonical emanet notu */
export const PROTOCOL_UTILITY_DISCLAIMER = TL_MODE
  ? `Zinesh üçüncü taraf güvenli ödeme ve emanet (Escrow) sistemidir.
Alıcı ödemeyi kasaya yatırır; satıcı teslim eder; onay sonrası ödeme aktarılır.
İtirazda tarafsız hakem heyeti karar verir. Yatırım veya kripto ürünü değildir.`
  : `İş bedeli emanet kasasında kalır; hakem kararı geçerlidir.`;

export const PROTOCOL_UTILITY_ONE_LINER = TL_MODE
  ? 'Zinesh: güvenli kasa, hakemlik ve gizli ödeme altyapısı — alıcı ve satıcı için.'
  : 'Emanet kasası ve hakemlik.';

export const PROTOCOL_COMMISSION_SPLIT_NOTE = TL_MODE
  ? 'Her tamamlanan emanetten %5 protokol komisyonu kesilir; sistem kasasında birikir.'
  : 'Her işten %5 protokol komisyonu kesilir.';
