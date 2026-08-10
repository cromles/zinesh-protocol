/**
 * Zinesh ürün dili — yazılı sözleşme + TL emanet kasası.
 * Alıcı / satıcı; pazar yeri yok.
 */

import { CURRENCY_SYMBOL, TL_MODE } from './productMode';

export const ZINESH_ONE_LINER =
  'İki tarafın yazılı anlaşmasını ve ödemesini koruyan güvenli emanet kasası.';

export const ESCROW_ONLY_TAGLINE =
  'Pazar yeri değiliz — anlaşmanızın yazılı kaydı ve güvenli kasasıyız.';

/** Emanet odasında taraflar — arayüz (API: employer / worker değişmez). */
export const ESCROW_PARTY = {
  employer: 'İşveren',
  worker: 'İş Alan',
  employerBtn: 'Ben işverenim',
  workerBtn: 'Ben iş alanım',
} as const;

export const BUYER_FEAR =
  'Parayı gönderirsem ürün veya hizmet gelmez mi? Eksik veya hatalı olursa ne olur?';
export const SELLER_FEAR =
  'Ürünü veya hizmeti teslim edersem paramı alamaz mıyım?';

export const PLAIN = {
  siteWallet: 'Zinesh kasası',
  siteWalletHint: 'Ödeme buraya yatırılır; iş bitene kadar ne alıcı ne satıcı tek başına çekemez.',
  jobWallet: 'Zinesh kasası',
  jobWalletHint: 'Alıcı ödemeyi kasaya yatırır; onay sonrası satıcıya aktarılır.',
  jobMoney: `anlaşılan tutar (${CURRENCY_SYMBOL})`,
  jobMoneyShort: CURRENCY_SYMBOL,
  lockMoney: 'kasaya yatır',
  safeBox: 'güvenli kasa',
  judges: 'hakem heyeti',
  trustScore: 'güven puanı',
  connectWallet: 'Hesabını bağla',
  connectWalletLoading: 'Bağlanıyor…',
  disputeFile: 'itiraz dosyası',
  verifyingDeposit: 'Ödeme kontrol ediliyor…',
} as const;

export const TWO_WALLETS_EXPLAIN = TL_MODE
  ? 'Zinesh araya girer: önce yazılı sözleşme, sonra ödeme kasada kilitlenir. Taraflar birbirine IBAN vermek zorunda kalmaz.'
  : 'Ödeme emanet kasasında yürür.';

export const SIMPLE_START_STEPS = [
  {
    emoji: '1️⃣',
    title: 'Eşleş',
    text: 'Karşı tarafın üye numarasını girin. İşveren veya iş alan olarak bağlanın.',
  },
  {
    emoji: '2️⃣',
    title: 'Konuş',
    text: 'Ne isteniyor, ne teslim edilecek, ne kadar süre — netleştirin. Beğenmezseniz düzeltin veya başlamayın.',
  },
  {
    emoji: '3️⃣',
    title: 'Sözleşme yaz',
    text: 'Anlaşılan şartlar yazılı teklife dönüşür. Karşı taraf onaylamadan para kilitlenmez.',
  },
  {
    emoji: '4️⃣',
    title: 'Kasaya yatır',
    text: 'Onay sonrası tutar Zinesh kasasında bekler. Satıcı işe güvenle başlar.',
  },
  {
    emoji: '5️⃣',
    title: 'Teslim ve onay',
    text: 'Teslim sonrası alıcı kontrol eder. Onay → ödeme; itiraz → yazılı sözleşmeye göre inceleme.',
  },
] as const;

export const DISPUTE_FLOW_STEPS = [
  'Alıcı veya satıcı itiraz açar ve kanıt yükler.',
  'Kasadaki para kilitli kalır.',
  'Tarafsız hakem heyeti yazılı sözleşmeyi ve kanıtları inceler.',
  'Karar: tam iade, tam ödeme veya adil oranlı bölüşüm.',
] as const;

export const USE_CASES = [
  'Freelance & yazılım',
  'Dijital varlık',
  'İkinci el & kargo',
  'Danışmanlık',
] as const;

export const PLAIN_ESCROW_NOTE =
  'Ödeme, yazılı sözleşmedeki şartlar karşılanana kadar güvenli kasada kalır. İtirazda hakem heyeti sözleşmeye bakar.';

export const PLAIN_ESCROW_FOOTER =
  'Zinesh, iki tarafın yazılı anlaşmasını ve ödemesini koruyan emanet kasasıdır. Taraflar birbirine banka bilgisi vermez.';

export const PLAIN_ESCROW_HERO =
  'Üye ID ile eşleşin, talepleri konuşun, şartları yazılı yazın. Anlaşınca para kasada kilitlenir; iş ancak bu şartlara göre tamamlanır.';

export const COLLATERAL_ROLE_NOTE =
  'Satıcı, paranın kasada kilitlendiğini ve sözleşmenin onaylandığını görerek işe başlar.';

export const COLLATERAL_DISPUTE_NOTE =
  'İtiraz açıldığında kasa kilitlenir. Hakem heyeti yazılı sözleşmeyi ve kanıtları esas alır.';

export const COLLATERAL_EMPLOYER_NOTE =
  'Alıcı tutarı kasaya yatırır. Sözleşme şartları karşılanana kadar kimse tek başına çekemez.';

export const ESCROW_BOTH_SIDES_NOTE =
  'Alıcının korkusu: "Para gider, iş gelmez." Satıcının korkusu: "İş gider, para gelmez." Yazılı sözleşme + kasa ikisini de azaltır.';

export const DISPUTE_OUTCOMES_NOTE =
  'Hakem sonuçları: (1) Satıcı haksız — %100 iade. (2) Alıcı haksız — %100 satıcıya. (3) Kısmi kusur — adil oranla bölüşüm.';

export const DISPUTE_OUTCOMES_SHORT =
  'Satıcı haksızsa iade; alıcı haksızsa ödeme; kısmi kusurda oranlı bölüşüm.';

/** Sözleşme metni minimum uzunluk (karakter). */
export const CONTRACT_MIN_CHARS = 40;
