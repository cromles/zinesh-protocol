# Trust Ontology

> **Bu doküman bir implementasyon planı değildir.**
>
> Bu doküman, Zinesh'in "Güven" kavramının mühendislik ontologisini tanımlar — Trust Intelligence ekosisteminin temel referans belgesidir.
>
> Production implementasyonları ayrı sprintlerde gerçekleştirilecektir.

**Versiyon:** 0.1 (ontoloji)  
**Tarih:** 2026-08-07  
**Sprint:** S2.1 — Trust Ontology (tasarım only)  
**Üst belge:** `docs/TRUST_INTELLIGENCE_ARCHITECTURE.md`

---

## 1. Amaç

### Trust (Güven) nedir?

Zinesh'te **Trust**, bir escrow işleminin veya bir actor'ün geçmiş platform davranışlarından türetilen, **kanıta dayalı**, **açıklanabilir** ve **bağlama yerleştirilmiş** gözlemler bütünüdür. Güven bir **bilgi türüdür** — tarafların "ne oldu, ne sıklıkla oldu, hangi koşullarda oldu" sorularına yanıt verir; "bu kişiye güvenilir mi?" hükmünü **tek başına** vermez.

Mühendislik tanımı:

> **Trust = f(events, metrics, context) → explainable observations**
>
> Sonuç karar değil; karar için girdi.

### Trust ne değildir?

| Kavram | Trust'tan farkı |
|--------|-----------------|
| Para | Trust transfer edilemez, escrow'da kilitlenemez, settlement ile ödenmez |
| Reputation | Reputation sosyal, kalıcı ve genellikle normatif bir etikettir; Trust gözlemdir |
| Puanlama | Tek sayı "güven puanı" black-box karar önerisidir; Trust çok boyutlu ve açıklanabilir olmalıdır |
| Kimlik doğrulama | KYC "bu kişi kim" der; Trust "bu işlemde ne yaptı" der |
| Hukuki yargı | Mahkeme/hakem sonucu bağlayıcıdır; Trust bilgilendirir |

### Zinesh neden Trust üretmeye çalışıyor?

1. **Asimetrik bilgi** — Freelance ve B2B işlerde taraflar birbirini tanımaz; escrow para riskini azaltır, davranış bağlamını değil.
2. **Karar kalitesi** — Kullanıcı sözleşme onaylamadan, dispute açmadan veya tamamlamadan önce geçmişe dayalı bağlam görmeli.
3. **Ölçek** — İnsan incelemesi her işe ölçeklenmez; deterministik gözlem ölçeklenir.
4. **AI güvenliği** — Copilot yalnızca Trust ontologisine uygun sanitize girdilerle konuşabilir; ham sezgi veya etiket üretemez.

### Trust neden para değildir?

Para Zinesh'te `users.json` / wallet mutasyonu ile temsil edilir; invariant-governed, geri alınamaz taahhütler içerir. Trust:

- Bir varlık olarak saklanmaz (ontolojik olarak türetilmiş görünüm).
- Transfer edilemez ("güvenimi sana devrediyorum" işlemi yok).
- Engine hatası Trust katmanında para kaybına yol açmamalı (Read Only ilkesi).

**Trust bilgidir; para taahhüttür.**

### Trust neden reputation değildir?

Reputation genellikle:

- Topluluk tarafından atanan kalıcı etiket ("5 yıldız", "güvenilir satıcı").
- Tek boyutlu sıralama (leaderboard).
- Sosyal kanıt ve görünürlük odaklı.

Zinesh Trust:

- Olay kaydına bağlı, replay edilebilir.
- Normatif değil (iyi/kötü hükmü yok).
- Oda ve actor bağlamında ayrı ayrı; "genel itibar" bir agregasyon gözlemi olabilir ama etiket değildir.

### Trust neden puanlama sistemi değildir?

Puanlama sistemi tipik olarak:

- Kullanıcıyı tek skora indirger.
- Skorun nasıl hesaplandığı gizlenir veya değişir.
- Skor düşünce otomatik ceza tetikler.

Zinesh ontologisinde cooperation/conflict/reliability gibi sayılar **sinyal bileşenleri** olabilir; bunlar **nihai güven kararı** değildir. Skor varsa bile `basis` (hangi event, hangi sayım) zorunludur. **Puan = özet; Trust = açıklanabilir gözlem seti.**

---

## 2. Trust'in Temel Özellikleri

Bir güven modeli (Zinesh Trust Ontology) aşağıdaki özelliklere sahip olmalıdır.

### Gözlemlenebilir (Observable)

Trust çıktıları platformda gerçekleşen **kayıtlı olaylardan** türemelidir. Gözlemlenemeyen (niyet, duygu, platform dışı davranış) Trust kapsamına girmez.

### Açıklanabilir (Explainable)

Her Trust çıktısı "neden?" sorusuna yanıt verebilmelidir: hangi event type'lar, hangi sayılar, hangi zaman penceresi. Kullanıcı ve denetçi aynı kanıta bakabilmelidir.

### Deterministik (Deterministic)

Aynı event seti + aynı bağlam + aynı ontology versiyonu → aynı metric ve signal. LLM katmanı bu kuralın dışındadır ve metric'i değiştirmez.

### Ölçülebilir (Measurable)

Trust soyut kalmamalı; sayılabilir büyüklükler (süre, adet, oran, frekans) üretmelidir. "Güvenilir görünüyor" ölçüm değildir; "3 tamamlanan settlement / 12 ay" ölçümdür.

### Tekrar üretilebilir (Reproducible)

Event log replay edildiğinde Trust metrikleri yeniden hesaplanabilir. Geçmiş Trust çıktısı tartışıldığında aynı girdiyle doğrulanabilir.

### Zamana bağlı değişebilir (Temporal)

Trust anlık fotoğraftır. Yeni olaylar eski gözlemleri günceller; "sonsuz geçmişte sabit itibar" varsayımı yoktur. Zaman penceresi (son 90 gün, bu oda) açıkça belirtilmelidir.

### Bağlama duyarlı olabilir (Context-sensitive)

Aynı ham davranış farklı iş türlerinde farklı anlam taşır (Bkz. §7). Trust ontology bağlamı birinci sınıf kavram olarak tanımlar; bağlamsız global hüküm üretmez.

---

## 3. Trust Signal

### Trust Signal nedir?

**Trust Signal**, bir veya daha fazla **deterministik metric** ve **domain event**'ten türetilen, doğal dilde ifade edilebilen **davranış gözlemidir**. Sinyal şu formdadır:

> **[Gözlem]** + **[Kanıt referansı]** + **[Bağlam notu (opsiyonel)]**

Örnek: *"Bu odada 4 counter offer kaydı var (event: `counter_offer_created`, count: 4)."*

### Trust Signal ne değildir?

| Değildir | Açıklama |
|----------|----------|
| Etiket | "Sorunlu kullanıcı" |
| Karar | "İşleme devam etme" |
| Tahmin | "Bundan sonra dispute açar" |
| Ceza | Skor düşürme → erişim kısıtı |
| Hukuki sonuç | "Sözleşme ihlali kanıtlandı" |

### Bir davranış ne zaman güven sinyalidir?

Davranış **kayıtlı bir domain event** veya event'lerden türetilmiş **ölçülebilir metric** olduğunda ve gözlem cümlesi **normatif yük** taşımadığında.

### Ne zaman değildir?

- Platform dışı bilgi (WhatsApp konuşması, yüz yüze anlaşma).
- Eksik veri (event yokken "sessiz = kötü niyet").
- Tek olaydan aşırı genelleme ("bir dispute = her zaman kötü actor").

### Örnekler ve olası yanlış yorumlar

#### Hızlı anlaşma

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "Tekliften kabul'e 45 dakika." | "Güvenilir — aceleyle iyi anlaştılar." → Belki taraflar önceden anlaştı; belki şartlar eksik kontrol edildi. |
| | "Şüpheli — çok hızlı." → Belki basit iş; belki tekrar eden iş ortağı. |

**Doğru kullanım:** Süre gözlemi + sözleşme eksiklik kontrolü (ayrı sinyal); hüküm yok.

#### Çok revizyon

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "5 sözleşme versiyonu." | "Profesyonel detaycılık." veya "Sürekli şart değiştiriyor." → Bağlam gerekir (§7). |

#### Dispute

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "Dispute açıldı." | "Kötü taraf dispute açandır." → Haklı taraf da dispute açar. |
| | "Dispute = dolandırıcılık." → Anlaşmazlık ≠ suç. |

#### Teslim süresi

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "Kilitlenmeden tamamlamaya 30 gün." | "Geç teslim." → Sözleşmede teslim tarihi tanımlı mı? Uzatma mutabık mı? |

#### Sözleşme değişikliği

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "3 kez `changes_requested`." | "Güvensiz müzakere." → Scope creep profesyonel projelerde normal olabilir. |

#### Sessizlik

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "14 gün mesaj/event yok." | "Kayıtsız = kötü niyet." → Tatil, bekleme, dış kanal iletişim platformda görünmez. |

**Kural:** Sessizlik **eksik veri**dir; güven sinyali üretmek için yeterli kanıt değildir (yalnızca "bu dönemde platformda kayıtlı aktivite yok" gözlemi).

#### İlk işlem

| Gözlem | Olası yanlış yorum |
|--------|-------------------|
| "Actor için ilk tamamlanan settlement." | "Riskli — geçmiş yok." → Her kullanıcı bir kez ilk işlemdir; bu **veri yetersizliği gözlemi**, suçlama değildir. |

---

## 4. Varsayımlar

Bugünkü Trust Metrics v0.1 (`trust_intelligence_lib.php`) aşağıdaki **heuristic varsayımlara** dayanır. Bunlar ontologi değil, **geçici mühendislik yaklaşımıdır**; S2+ sinyal kataloğunda açıkça sorgulanmalıdır.

### Varsayım 1: "Kabul edilmiş sözleşme → daha yüksek cooperation"

**Mantık:** `accepted_contract_version` varsa cooperation +20.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Taraflar şartları onaylamış | Zorla veya eksik okuyarak onay |
| İş ilerliyor | Onay sonrası hemen dispute |

**Sonuç:** Kısmi gösterge; tek başına güven kanıtı değil.

### Varsayım 2: "Settlement tamamlandı → cooperation ve reliability artar"

**Mantık:** `settlement_completed` → cooperation +15, reliability +35.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| İş parasal olarak kapandı | Kalite sorunu sonradan ortaya çıkar |
| | Dispute sonrası zorunlu settlement |

**Sonuç:** **Tamamlama ≠ memnuniyet**; parasal kapanış gözlemi ayrı tutulmalı.

### Varsayım 3: "Red / dispute → cooperation düşer, conflict artar"

**Mantık:** `terms_rejected` → cooperation −12 (cap 35); `dispute_opened` → cooperation −18, conflict +22.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Gerçek anlaşmazlık var | Haklı red (kötü teklif) |
| | Dispute açan mağdur taraf |

**Sonuç:** **"Çok dispute = düşük güven" her zaman doğru değildir.** Dispute platformun çözüm mekanizmasıdır; sık dispute hem kötü davranış hem yoğun iş hacmi hem hak arama olabilir.

### Varsayım 4: "Counter offer ve changes_requested → conflict artar"

**Mantık:** Her biri conflict skoruna pozitif katkı.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Sert müzakere | Normal B2B süreci |
| | İlk kez çalışan tarafların öğrenme eğrisi |

### Varsayım 5: "Settlement failed → reliability düşer"

**Mantık:** `settlement_failed` → reliability −30.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Teknik/operasyonel sorun actor'de | Engine bug, banka kesintisi, TTL recovery |
| | Employer guard veya bakiye yetersizliği |

**Sonuç:** Fail nedeni bağlam gerektirir; event tek başına actor suçlaması değildir.

### Varsayım 6: "Açık dispute çözülmediyse reliability düşer"

**Mantık:** `disputes > 0 && disputeResolved === 0` → reliability −20.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Uzun süren çözülmemiş uyuşmazlık | Hakem süreci henüz tamamlanmadı |

### Varsayım 7: "Negotiation rounds = counter + changes + rejected + max(0, proposed−1)"

**Mantık:** Müzakere yoğunluğu tek sayıya indirgenir.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Karşılaştırılabilir ölçü | Karmaşık projede yüksek round normal |

### Varsayım 8 (Actor Trust): "Çoklu oda agregasyonu actor davranışını temsil eder"

**Mantık:** Tüm odalardan ortalama completion time, dispute oranı.

| Doğru olabilir | Yanlış olabilir |
|----------------|-----------------|
| Uzun vadeli pattern | Az örneklem (n=1, n=2) |
| | Farklı iş türleri karışık |

**Ontoloji notu:** v0.1 metrikleri **gözlem üretir**; varsayımlar gelecekte **Signal Catalog**'da açık `assumption_id` ile etiketlenmelidir.

---

## 5. False Positive

**False positive:** Trust sistemi **risk veya olumsuzluk** ima eden bir gözlem üretir; gerçekte davranış meşru, haklı veya bağlamsal olarak nötrdür.

### Örnekler

| Durum | False positive |
|-------|----------------|
| Yazılım projesinde 6 revizyon | "Yüksek conflict" — oysa normal scope refinement |
| Mağdur taraf dispute açar | "Düşük cooperation" — koruma arayışı |
| İlk işlemde yüksek tutar | "Anormal" — yeni ama meşru iş |
| Hızlı kabul | "Şüpheli hız" — taraflar önceden anlaşmış |
| Settlement failed (engine) | "Düşük reliability" — actor hatası değil |

### Neden oluşurlar?

1. **Bağlam eksikliği** — İş türü, tutar bandı, sektör ontology'de yok.
2. **Heuristic ağırlıklar** — Tek boyutlu skor birleşimi (cooperation/conflict).
3. **Eksik negatif kanıt** — Platform dışı mutabakat görünmez.
4. **Küçük örneklem** — Az işten aşırı genelleme.
5. **Olay ≠ niyet** — Event sayımı niyet sanılır.

### Nasıl azaltılır? (Ontoloji düzeyinde)

- Sinyalleri **normatif olmayan** cümlelerle sınırla.
- Her sinyale **confidence** (veri yeterliliği) ekle.
- **Bağlam boyutları** zorunlu kıl (iş türü, ilk işlem bayrağı).
- Skor yerine **ayrık gözlem listesi** tercih et.
- "Benzer işlem kohortu" karşılaştırması (gelecek katman) — mutlak eşik değil.

---

## 6. False Negative

**False negative:** Gerçek risk veya önemli uyarı **görünmez**; sistem sessiz veya fazla iyimser kalır.

### Örnekler

| Durum | False negative |
|-------|----------------|
| Platform dışı ödeme vaadi | Event yok → Trust sessiz |
| Koordineli kötüye kullanım (düşük event) | Metrik normal görünür |
| Sözleşmede kritik madde eksik | Davranış metrikleri iyi; içerik analizi yok (Copilot alanı) |
| Çoklu hesap (sybil) | Tek actor agregasyonu yanıltıcı |
| Dispute çözüldü ama taraflardan biri sistematik mağdur | Agregasyon "çözüldü" der, yapısal sorun gizlenir |

### Neden oluşurlar?

1. **Gözlemlenebilirlik sınırı** — Yalnızca platform içi event.
2. **Tanım alanı darlığı** — Trust = davranış; içerik/kalite ayrı.
3. **Zaman gecikmesi** — Uzun vadeli pattern henüz oluşmamış.
4. **Bağlam aşırı düzeltme** — Her şeyi "normal" sayma eğilimi.

### Nasıl azaltılır? (Ontoloji düzeyinde)

- **Eksik veri** açık sinyal: "Yetersiz geçmiş (n<3 işlem)."
- Sözleşme **yapısal eksiklik** gözlemi (Copilot, içerik — davranış değil).
- Enterprise düzeyinde kohort analizi (S5).
- Federated opt-in sinyaller (S7) — platform körlüğünü kabul et ve sınırla.

---

## 7. Context

Aynı ham davranış farklı bağlamlarda **tamamen farklı anlam** taşır. Trust ontology'de bağlam birinci sınıf vatandaştır.

### Örnek: Yüksek revizyon

```
Yüksek revizyon
      ↓
Yazılım projesi (scope belirsiz)
      → Gözlem: "Versiyon sayısı sektör ortalaması içinde."
      → Yanlış yorum yapılmaz

Yüksek revizyon
      ↓
İkinci el telefon satışı (basit ürün, sabit şart)
      → Gözlem: "5 versiyon; bu iş türü için üst yüzdelik dilim."
      → Dikkat çağrısı (hüküm değil)
```

### Bağlam boyutları (ontoloji sözlüğü)

| Boyut | Soru |
|-------|------|
| **İş türü** | Ne satılıyor? Dijital / fiziksel / hizmet? |
| **Tutar bandı** | Mikro / standart / kurumsal? |
| **İlişki** | İlk işlem mi, tekrar eden ortak mı? |
| **Süre** | Anlık teslim mi, haftalık proje mi? |
| **Taraflar** | Bireysel / KOBİ / kurumsal hesap? |
| **Oda durumu** | Müzakere / locked / disputed / completed? |

### Bağlamsız hüküm yasağı

Trust ontology **global tek skor** üretmez. Çıktı her zaman:

> "Bu actor, **bu bağlamda**, **bu metrik** açısından **şu gözleme** sahiptir."

---

## 8. Trust Intelligence İlkeleri

Trust Intelligence (ve tüm türev katmanlar) aşağıdaki sınırlara uyar. Bunlar ontologi kurallarıdır; ihlali Trust değil, **Trust dışı sistem** üretir.

| Yapmaz | Yapar |
|--------|-------|
| Kişilik analizi | Davranış frekansı |
| Niyet okuma | Kayıtlı olay dizisi |
| Geleceği bildiğini iddia etme | Geçmiş istatistik |
| Dolandırıcı etiketi | "Dispute oranı %X (n=Y işlem)" |
| İyi/kötü insan kararı | Çok boyutlu nötr gözlemler |

**Tek cümle:** Yalnızca geçmiş olaylardan istatistiksel gözlem.

---

## 9. Explainability

### Event geriye izlenebilirlik

Her Trust Signal şu zinciri taşımalıdır:

```
Signal ID
  → metric snapshot (değerler)
    → event type listesi (count, first_at, last_at)
      → domain event kayıtları (timeline)
```

Kullanıcı veya denetçi "bu gözlem nereden geldi?" diye sorduğunda cevap **event timeline'a** kadar inebilmelidir.

### AI açıklanabilirliği

AI Copilot (gelecek katman):

- Ham event'i atlayarak sonuç üretmez (**Evidence Before Intelligence**).
- "Neden böyle düşündüm?" sorusuna yanıt: metric + signal + risk observation referansı.
- **Black-box AI** Trust kararı için kullanılmaz — LLM yorumlayıcıdır, hakem değil.

### Açıklanabilirlik anti-pattern'leri

| Anti-pattern | Neden yasak |
|--------------|-------------|
| "Model %87 güven dedi" | Kanıt yok |
| Gizli ağırlık tablosu | Denetlenemez |
| Kullanıcıya skor gösterip basis gizleme | Karar baskısı |
| LLM halüsinasyonu event gibi sunma | Sahte Trust |

---

## 10. İnsan Kontrolü

### Karar sahipliği

| Aktör | Rol |
|-------|-----|
| **Kullanıcı** | Sözleşme onayı, ödeme, dispute, iptal — **son karar** |
| **Escrow Engine** | Kurallara bağlı mutasyon (invariant-governed) |
| **Trust** | Bilgi üretir; karar üretmez |
| **AI** | Yardımcı; açıklar ve önerir; **asla** mutasyon tetiklemez |

### Trust karar vermez

Trust çıktısı UI'da:

- Bilgi kartı
- Grafik / sayım
- "Dikkat: …" düzeyinde nötr uyarı

olabilir; **engel, otomatik red, otomatik release** olamaz.

### AI yalnızca yardımcıdır

Copilot önerisi "şunu kontrol edin" formatındadır. "Kabul etmeyin" emri değil, gerekçeli bilgilendirmedir.

---

## 11. Roadmap

Bu ontoloji, Trust Intelligence ekosisteminin **kavramsal kökü**dür. Aşağıdaki katmanlar ontolojiye sırayla dayanır:

```
Trust Ontology          ← Bu belge (S2.1)
        ↓
Trust Signal Catalog    ← Sinyal tanımları, varsayım ID, false pos/neg matrisi
        ↓
Trust Intelligence    ← Oda/actor sinyal üretimi (ARCHITECTURE S2)
        ↓
Risk Engine           ← İstatistiksel risk gözlemleri (S3)
        ↓
AI Copilot            ← Doğal dil açıklama (S4)
        ↓
Enterprise Intelligence ← Portföy / organizasyon gözlemi (S5)
```

### Katman — ontoloji ilişkisi

| Katman | Ontolojiden aldığı |
|--------|-------------------|
| **Signal Catalog** | Signal tanımı, bağlam boyutları, yasaklı yorumlar |
| **Trust Intelligence** | Evidence Before Intelligence sırası, determinism |
| **Risk Engine** | False pos/neg farkındalığı, confidence |
| **AI Copilot** | §7–10 sınırlar, explainability zorunluluğu |
| **Enterprise Intelligence** | Agregasyon sınırları, privacy, kohort bağlamı |

### Ontoloji evrimi

Ontoloji **versiyonlanır** (`trust_ontology_version`). Signal Catalog ve metrik hesapları ontology versiyonuna referans verir; geçmiş gözlemler "hangi ontology ile üretildi" bilgisiyle okunabilir (gelecek sprint kavramı — implementasyon burada tanımlanmaz).

---

## Stable Core İlişkisi

Trust Ontology ve tüm türev katmanlar:

- Escrow Engine'e **yazmaz**.
- Wallet, settlement, recovery, dispute **değiştirmez**.
- Mevcut event emit sorumluluğu engine'de kalır; ontology yalnızca **okunan olayların anlamını** tanımlar.

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Legal / Compliance | | | |

---

*Bu belge Zinesh Trust Intelligence ekosisteminin temel referans ontologisidir. API, şema ve kod bu belgenin kapsamı dışındadır.*
