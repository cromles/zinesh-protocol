export type LandingFaqItem = {
  id: string;
  question: string;
  answer: string;
  category: 'genel' | 'guvenlik' | 'ucretler' | 'anlasmazlik';
};

export type LandingUseCase = {
  id: string;
  title: string;
  category: string;
  description: string;
  buyerBenefit: string;
  sellerBenefit: string;
  avgAmount: string;
};

export const LANDING_USE_CASES: LandingUseCase[] = [
  {
    id: '1',
    title: 'Domain ve dijital varlık devri',
    category: 'Domain & dijital varlık',
    description:
      'Taraflar fiyat ve teslim koşullarında dışarıda anlaşır; Zinesh emanet sürecinde ödeme kilitlenir.',
    buyerBenefit: 'Devir tamamlanana kadar tutar emanet hesabında kalır.',
    sellerBenefit: 'Karşı tarafın bütçesinin hazır olduğunu panelde görür.',
    avgAmount: 'Örnek senaryo',
  },
  {
    id: '2',
    title: 'Freelance yazılım teslimi',
    category: 'Yazılım & hizmet',
    description: 'İş kapsamı ve tutar önceden netleşir; teslim ve onay Zinesh odasında kayıt altına alınır.',
    buyerBenefit: 'Teslimatı kontrol etmeden ödeme serbest bırakılmaz.',
    sellerBenefit: 'Anlaşılan tutar kilitlendikten sonra işe başlanabilir.',
    avgAmount: 'Örnek senaryo',
  },
  {
    id: '3',
    title: 'Yüksek tutarlı ikinci el alım',
    category: 'Fiziksel ürün',
    description: 'Taraflar kendi kanallarında anlaşır; Zinesh bağlantı ve emanet sürecini yönetir.',
    buyerBenefit: 'Onay verilene kadar tutar satıcıya aktarılmaz.',
    sellerBenefit: 'Ödemenin emanet sürecine girdiğini görür.',
    avgAmount: 'Örnek senaryo',
  },
];

export const LANDING_FAQ: LandingFaqItem[] = [
  {
    id: 'faq-1',
    category: 'genel',
    question: 'Zinesh bir pazaryeri mi?',
    answer:
      'Hayır. Zinesh kullanıcı bulmaz, eşleştirme yapmaz ve borsa değildir. Taraflar dışarıda anlaşır; Zinesh mevcut anlaşmayı güvenli emanet süreciyle korur.',
  },
  {
    id: 'faq-2',
    category: 'genel',
    question: 'Nasıl başlarım?',
    answer:
      'Kayıt olun, karşı tarafın 5 haneli üye numarasını konsolda girerek bağlanın, sözleşme metnini oluşturun ve karşılıklı onayla emanet sürecini başlatın.',
  },
  {
    id: 'faq-3',
    category: 'guvenlik',
    question: 'Ödeme ne zaman serbest bırakılır?',
    answer:
      'Tarafların sözleşmede belirttiği koşullar ve konsoldaki onay adımları tamamlanınca tutar serbest bırakılır. Zinesh cüzdan ürünü değil; emanet süreci ürünüdür.',
  },
  {
    id: 'faq-4',
    category: 'anlasmazlik',
    question: 'Anlaşmazlık olursa ne olur?',
    answer:
      'Konsoldan anlaşmazlık bildirimi açılır. Süreç, kayıtlı sözleşme metni ve tarafların sunduğu kanıtlara göre ilerler; fonlar kilitli kalır.',
  },
  {
    id: 'faq-5',
    category: 'ucretler',
    question: 'Komisyon nasıl hesaplanır?',
    answer:
      'Landing sayfasındaki hesaplayıcı, Zinesh protokol sabitlerindeki emanet komisyon oranını gösterir. Kesin tutar işlem anında konsolda doğrulanır.',
  },
];

export const LANDING_TESTIMONIALS = [
  {
    id: 't1',
    name: 'Örnek kullanıcı',
    role: 'Domain alımı',
    comment:
      'Taraflar WhatsApp üzerinde anlaştı; Zinesh’te üye numarasıyla bağlanıp emanet sürecini tamamladık. (Örnek senaryo)',
    rating: 5,
  },
  {
    id: 't2',
    name: 'Örnek kullanıcı',
    role: 'Freelance proje',
    comment:
      'Sözleşme metni ve onay adımları kayıt altındaydı; ödeme teslim onayından sonra aktarıldı. (Örnek senaryo)',
    rating: 5,
  },
];
