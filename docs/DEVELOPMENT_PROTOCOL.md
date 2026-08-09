# Development Protocol v1.0

> **Bu doküman implementasyon planı değildir.**
>
> Bu doküman, Zinesh geliştirme sürecinin resmi **Engineering Governance** modelini tanımlar.
>
> Architecture Freeze v1.0 sonrasında tüm sprintler, review süreçleri, merge kararları ve deploy işlemleri bu workflow'a uymak zorundadır.
>
> Architecture değişmeden bu workflow ihlal edilemez.

**Versiyon:** 1.0  
**Tarih:** 2026-08-07  
**Sprint:** S0 — Development Protocol v1.0 (Engineering Governance)  
**Üst referans:** `docs/ARCHITECTURE_FREEZE_v1.md` (Architecture Constitution)  
**Governance türü:** Engineering Process Constitution

---

## İlişkili Yönetişim Belgeleri

| Belge | Rol |
|-------|-----|
| `ARCHITECTURE_FREEZE_v1.md` | Sistemin **ne olduğunu** tanımlar (mimari anayasa) |
| `DEVELOPMENT_PROTOCOL.md` | Sistemin **nasıl geliştirileceğini** tanımlar (süreç anayasa) |
| `TRUST_DOMAIN_MODEL.md` | Resmi Ubiquitous Language |
| Frozen catalog/taxonomy belgeleri | Domain içerik referansları |

**Kural:** Bu belge onaylandıktan sonra sprintlerde workflow veya governance kuralları tartışılmaz. Değişiklik yalnızca **Architecture Change Proposal (ACP)** ile yapılabilir.

---

## 1. Amaç

### Development Protocol nedir?

**Development Protocol**, Zinesh'te Architecture Freeze v1.0 sonrasında yapılan tüm mühendislik çalışmalarının **zorunlu süreç çerçevesidir**. Sprint planlama, kod yazma, review, arbitration, merge ve deploy kararlarının hangi sırayla, hangi kriterlerle ve hangi yetki sınırları içinde alınacağını tanımlar.

Bu belge bir **iş akışı anayasasıdır**: neyin ne zaman yapılacağını, neyin yasak olduğunu ve bir sprintin ne zaman "bitti" sayılacağını bağlayıcı biçimde belirler.

### Ne değildir?

| Development Protocol **değildir** | Açıklama |
|-----------------------------------|----------|
| Mimari tanım | Katmanlar, zincir ve invariant'lar `ARCHITECTURE_FREEZE_v1.md`'dedir |
| Implementasyon planı | Teknik tasarım ve dosya listesi sprint Implementation Plan'ında yazılır |
| Product roadmap | İş öncelikleri ayrı ürün sürecindedir |
| Kod standardı rehberi | Stil/format kuralları ayrı belgelerde tanımlanabilir |
| Otomatik CI konfigürasyonu | CI/CD araçları bu protokolü uygular; protokolün yerini almaz |

### Neden gereklidir?

1. **Mimari süreklilik** — Freeze sonrası her sprint aynı zinciri (Event → Metric → Signal → …) korur.
2. **Stable Core güvenliği** — Para ve oda state'i intelligence veya feature sprint'leriyle istemeden değişmez.
3. **Karar tekrarlanabilirliği** — Merge/deploy kararları kişisel yorumdan çıkar, kanıta dayanır.
4. **Review disiplini** — Self Review, Independent Review ve Arbitration rolleri karışmaz.
5. **Scope kontrolü** — Compliance Fix ve Final Review sırasında feature creep engellenir.

### Architecture Freeze ile ilişkisi

| Boyut | Architecture Freeze | Development Protocol |
|-------|---------------------|----------------------|
| Konu | **Ne** inşa edilir (mimari, terminoloji, invariant) | **Nasıl** inşa edilir (süreç, review, merge) |
| Değişiklik | ACP + Architecture Review | ACP (workflow değişikliği için) |
| İhlal sonucu | Mimari bozulma | Süreç ihlali; merge/deploy engeli |

**Kural:** Freeze mimariyi kilitler; Protocol o mimariye uygun geliştirmeyi zorunlu kılar. Protocol, Freeze'i gevşetemez.

### Stable Core'u nasıl korur?

1. Her sprint başında **Stable Core etkisi** açıkça değerlendirilir.
2. Intelligence sprint'leri Stable Core'a **yazamaz** (C1, C2).
3. Stable Core değişikliği yalnızca **ayrı Engine sprint** olarak planlanır; intelligence gerekçesiyle yapılamaz.
4. Regression paketinde Stable Core invariant testleri (ör. INV-A4, S1.1) zorunludur.
5. Deploy, Stable Core PASS olmadan reddedilir.

---

## 2. Engineering İlkeleri

### Stable Core First

Escrow Engine (`escrow_room_lib.php`, `wallet_lib.php`) ve para/oda mutasyon yolları önceliklidir. Intelligence, UI veya operasyonel kolaylık Stable Core invariant'larını gerekçe göstererek değiştiremez.

### Architecture Before Code

Kod yazılmadan önce Architecture Compliance tamamlanır. Mimari uyumsuzluk tespit edildikten sonra yazılan kod merge edilemez.

### Evidence Before Change

Değişiklik iddiası kanıtlanır: test çıktısı, diff, invariant checklist, regression sonucu. Kanıtsız "çalışıyor" ifadesi merge gerekçesi değildir.

### Read Only by Default

Trust Intelligence katmanları varsayılan olarak salt okunurdur. Yazma ihtiyacı Engine bounded context'inde kalır; intelligence'dan escrow/wallet mutasyonu yasaktır.

### Regression First

Mevcut davranışın korunması yeni özellikten önce gelir. Regression FAIL iken feature tamamlanmış sayılmaz.

### Explainability

Signal, Risk Observation ve AI çıktıları `basis` / evidence chain taşır. Explainability'siz "fired" çıktı üretilemez (Freeze C6, C7 — Risk katmanı; Signal için catalog kuralları).

### Small Scope

Sprint charter'ı dar tutulur. Tek sprintte mimari + feature + refactor + Stable Core değişikliği birleştirilmez.

### Deterministic Development

Aynı girdi + aynı versiyon → aynı çıktı. Non-deterministic davranış mimari ihlal veya bug olarak ele alınır.

### Human Review

Otomatik araçlar destekler; nihai merge ve deploy kararı insan review zinciriyle verilir. Review atlanamaz.

### Architecture Compliance

Her sprint, Freeze invariant'larına (C1–C20), frozen chain'e ve resmi terminolojiye karşı kontrol edilir. Compliance PASS olmadan Implementation'a geçilmez.

---

## 3. Resmi Sprint Workflow

Aşağıdaki sıra **değiştirilemez**:

```
Architecture Compliance
        ↓
Implementation Plan
        ↓
Implementation
        ↓
Self Review
        ↓
Independent Review
        ↓
Architecture Arbitration (yalnızca gerekirse)
        ↓
Compliance Fix
        ↓
Final Review
        ↓
Merge
        ↓
Deploy
```

### 3.1 Architecture Compliance

| Alan | Tanım |
|------|-------|
| **Amaç** | Sprint başlamadan mimari uygunluğu doğrulamak; kod yazımına izin vermek veya reddetmek |
| **Girdi** | Sprint charter, hedef kapsam, ilgili frozen belgeler |
| **Çıktı** | Architecture Compliance Checklist (PASS / FAIL / CONDITIONAL) |
| **Başarı kriteri** | Frozen chain, Stable Core, terminology, scope ve invariant'lar için bilinen ihlal yok |
| **Sonraki adım şartı** | PASS veya CONDITIONAL (koşullar Implementation Plan'a yazılmış) |

### 3.2 Implementation Plan

| Alan | Tanım |
|------|-------|
| **Amaç** | Sprintin teknik sınırlarını, dosya etkisini ve regression stratejisini yazılı hale getirmek |
| **Girdi** | Compliance çıktısı, sprint charter |
| **Çıktı** | Onaylı Implementation Plan belgesi |
| **Başarı kriteri** | Kapsam, dosyalar, Stable Core etkisi, regression ve risk bölümleri eksiksiz |
| **Sonraki adım şartı** | Plan onaylandı; scope dışı işler backlog'a alındı |

### 3.3 Implementation

| Alan | Tanım |
|------|-------|
| **Amaç** | Planlanan değişikliği üretmek |
| **Girdi** | Onaylı Implementation Plan |
| **Çıktı** | Kod, test, dokümantasyon (plan kapsamında) |
| **Başarı kriteri** | Planlanan kapsam tamamlandı; plan dışı dosya/değişiklik yok |
| **Sonraki adım şartı** | Implementation bitti; Self Review'a hazır |

### 3.4 Self Review

| Alan | Tanım |
|------|-------|
| **Amaç** | Uygulayıcının kendi işini plan ve mimariye karşı kontrol etmesi |
| **Girdi** | Diff, test çıktıları, checklist |
| **Çıktı** | Self Review raporu |
| **Başarı kriteri** | Bilinen eksiklikler listelendi; scope creep yok |
| **Sonraki adım şartı** | Self Review tamamlandı; Independent Review talep edildi |

### 3.5 Independent Review

| Alan | Tanım |
|------|-------|
| **Amaç** | Bağımsız gözle sprint kapsamı, mimari uyum ve regression riskini değerlendirmek |
| **Girdi** | Self Review raporu, diff, test sonuçları, sprint charter |
| **Çıktı** | Independent Review raporu + sınıflandırılmış finding'ler |
| **Başarı kriteri** | In-scope finding'ler işlendi veya defer kararı verildi; arbitration ihtiyacı net |
| **Sonraki adım şartı** | Arbitration gerekmiyorsa Compliance Fix/Final Review'a; gerekiyorsa Arbitration'a |

### 3.6 Architecture Arbitration

| Alan | Tanım |
|------|-------|
| **Amaç** | Mimari ihtilafı tek seferlik, bağlayıcı kararla çözmek |
| **Girdi** | Independent Review'daki Architecture Violation / Compliance Issue ihtilafı |
| **Çıktı** | Hakem kararı (REAL VIOLATION / QUALITY IMPROVEMENT / REJECTED / DEFER) |
| **Başarı kriteri** | Karar yazılı, gerekçeli, uygulanabilir |
| **Sonraki adım şartı** | REAL VIOLATION → Compliance Fix; diğerleri → Final Review veya backlog |

### 3.7 Compliance Fix

| Alan | Tanım |
|------|-------|
| **Amaç** | Hakem kararını **yalnızca** uygulamak |
| **Girdi** | Bağlayıcı arbitration kararı |
| **Çıktı** | Minimal patch + güncellenmiş test |
| **Başarı kriteri** | İhlal giderildi; yeni feature/mimari/scope yok |
| **Sonraki adım şartı** | Final Review'a |

### 3.8 Final Review

| Alan | Tanım |
|------|-------|
| **Amaç** | Hakem kararının doğru uygulandığını doğrulamak; merge kararı vermek |
| **Girdi** | Compliance Fix diff, regression sonuçları, arbitration kararı |
| **Çıktı** | Merge kararı: READY FOR MERGE / READY WITH MINOR FIXES / NEEDS REWORK |
| **Başarı kriteri** | Yeni mimari tartışması yok; yalnızca uygulama doğrulaması |
| **Sonraki adım şartı** | READY FOR MERGE veya MINOR FIXES tamamlandı → Merge |

### 3.9 Merge

| Alan | Tanım |
|------|-------|
| **Amaç** | Onaylı değişikliği ana dala almak |
| **Girdi** | Final Review READY kararı, PASS regression |
| **Çıktı** | Merge commit, CI yeşil |
| **Başarı kriteri** | Merge policy karşılandı |
| **Sonraki adım şartı** | Deploy kararı |

### 3.10 Deploy

| Alan | Tanım |
|------|-------|
| **Amaç** | Production'a kontrollü çıkış |
| **Girdi** | Merge PASS, deploy checklist |
| **Çıktı** | Deploy kaydı, smoke/regression doğrulaması |
| **Başarı kriteri** | Deploy policy PASS |
| **Sonraki adım şartı** | Sprint kapanışı |

---

## 4. Architecture Compliance

Her sprint başında aşağıdaki checklist tamamlanır. Bir madde FAIL ise Implementation başlamaz (CONDITIONAL yalnızca yazılı koşulla).

### 4.1 Frozen Chain

- [ ] Değişiklik frozen üretim zincirine uyuyor: Event → Metric → Signal → Context → Risk Observation → AI Explanation → Human Decision
- [ ] Katman atlama yok (ör. Event → Signal, Event → Risk Observation)
- [ ] Ters akış yok (ör. Risk Observation → Signal üretimi)

### 4.2 Stable Core

- [ ] `escrow_room_lib.php`, `wallet_lib.php` ve invariant yolları etkilenmiyor **veya** ayrı Engine sprint charter'ı var
- [ ] Intelligence katmanı escrow/wallet'e yazmıyor
- [ ] Intelligence kapalıyken escrow çalışmaya devam eder (C20)

### 4.3 Terminology

- [ ] Yeni terim yok; yasak terimler kullanılmıyor (Freeze §2)
- [ ] Resmi karşılıklar kullanıldı (Risk Engine, Evidence Completeness, vb.)

### 4.4 Domain Model

- [ ] `TRUST_DOMAIN_MODEL.md` invariant'ları (I1–I14) ihlal edilmiyor
- [ ] Ubiquitous Language tutarlı

### 4.5 Scope

- [ ] Sprint charter tek cümlelik amaçla sınırlı
- [ ] Scope dışı işler backlog'a alındı
- [ ] Compliance Fix / Final Review'da genişletme yok

### 4.6 Read Only

- [ ] Trust stack mutasyon üretmiyor
- [ ] Hata durumunda boş/güvenli paket dönüşü tanımlı

### 4.7 Architecture Invariants (C1–C20)

- [ ] İlgili sprint kapsamındaki invariant'lar tek tek işaretlendi
- [ ] Bilinen ihlal varsa arbitration planlandı

**Compliance sonucu:** `PASS` | `CONDITIONAL` | `FAIL`

---

## 5. Implementation Plan

Her sprint için Implementation Plan **zorunlu** bölümler:

### 5.1 Kapsam

- Sprint ID ve adı
- Amaç (tek paragraf)
- **Yapılacaklar** ve **yapılmayacaklar** (açık liste)
- Sprint charter referansı

### 5.2 Yeni dosyalar

- Eklenecek dosya yolları ve rolleri
- Stable Core'a dokunup dokunmadığı

### 5.3 Değişecek dosyalar

- Değiştirilecek dosyalar ve değişiklik türü (bugfix, compliance, refactor)
- Değiştirilmeyecek dosyalar (özellikle Stable Core)

### 5.4 Stable Core etkisi

- `NONE` | `READ_ONLY_CONSUMER` | `ENGINE_CHANGE` (sonuncusu yalnızca Engine sprint)
- Etki varsa invariant listesi ve test planı

### 5.5 Regression planı

- Çalıştırılacak e2e/invariant testler
- Beklenen PASS kriterleri
- Determinism/replayability kontrolleri

### 5.6 Risk değerlendirmesi

- Davranış değişikliği riski
- False positive/negative riski (intelligence sprint'leri)
- Geri alma (rollback) notu

---

## 6. Self Review

### Amacı

Uygulayıcının kendi işini sprint planı ve mimariye karşı ilk kontrolüdür. Independent Review'a temiz veya bilinçli finding listesiyle gitmeyi sağlar.

### Kapsamı

- Implementation Plan ile diff uyumu
- Architecture Compliance checklist self-doğrulaması
- Test çalıştırma ve sonuç kaydı
- Scope creep taraması

### Doğrulaması gerekenler

- Planlanan dosyalar değişti mi?
- Frozen chain ihlali var mı?
- Explainability alanları eksik mi?
- Regression çalıştırıldı mı?

### Doğrulaması gerekenler — dışı

- Başkasının sprint'i
- Product öncelik tartışması
- Mimari ihtilafında nihai karar (Arbitration işi)
- Out-of-scope gelecek işlerin detay tasarımı

---

## 7. Independent Review

### Neden gereklidir?

Self Review doğal olarak kör nokta taşır. Bağımsız review; mimari ihlal, scope sapması ve regression riskini sprint merge öncesi yakalar.

### Self Review'dan farkı

| Boyut | Self Review | Independent Review |
|-------|-------------|-------------------|
| Yapan | Uygulayıcı | Uygulayıcı dışı reviewer |
| Odak | Plan uyumu, eksik test | Mimari, kapsam, risk sınıflandırması |
| Yetki | Düzeltme önerisi | Finding sınıflandırması, arbitration önerisi |
| Bağlayıcılık | Hazırlık | Merge öncesi gate |

### Hangi kriterlerle yapılır?

1. Sprint charter ile diff uyumu
2. Architecture Compliance checklist
3. Frozen chain ve invariant kanıtı (kod referansı)
4. Regression riskleri
5. Finding sınıflandırması (Bölüm 18)
6. In Scope vs Out of Scope ayrımı (Bölüm 17)

---

## 8. Architecture Arbitration

### Ne zaman açılır?

- Independent Review **Architecture Violation** veya bağlayıcı **Compliance Issue** bildirdiğinde
- İki reviewer mimari yorumda ihtilaf ettiğinde ve sprint merge'i bloke olduğunda
- Freeze invariant'ının ihlal edilip edilmediği tartışmalı olduğunda

### Ne zaman açılmaz?

- Quality Improvement veya Technical Debt (defer veya ayrı sprint)
- Future Enhancement
- Out of Scope Finding
- Product öncelik tartışması
- Stil/format konuları

### Kim karar verir?

**Architecture Hakemi** (Architecture Review yetkisi olan rol). Hakem, Freeze ve Domain Model'i esas alır; sprint charter'ı değiştirmez.

### Aynı konu ikinci kez arbitration'a girebilir mi?

**Hayır.**

Hakem kararı **kesindir**. Compliance Fix yalnızca hakem kararını uygular. Yeni tartışma başlatamaz. Aynı finding için ikinci arbitration talebi reddedilir; uygulama hatası varsa Compliance Fix veya Final Review ile sınırlı kalınır.

### Hakem karar türleri

| Karar | Anlam | Sonraki adım |
|-------|-------|--------------|
| **REAL VIOLATION** | Freeze invariant ihlali | Zorunlu Compliance Fix |
| **QUALITY IMPROVEMENT** | Mimari ihlal değil; kalite artışı | Defer veya ayrı sprint |
| **REJECTED** | Finding geçersiz | Final Review |
| **DEFER** | Kabul edilmiş teknik borç | Backlog; sprint kapanabilir |

---

## 9. Compliance Fix

### Amacı

Architecture Arbitration'da **REAL VIOLATION** olarak kayıtlı ihlali, minimum değişiklikle gidermek.

### Kapsamı

- Yalnızca hakem kararında yazılı maddeler
- İlgili test güncellemesi
- Regression doğrulaması

### Yasaklar

| Yasak | Açıklama |
|-------|----------|
| Yeni feature | Compliance Fix feature taşıyamaz |
| Yeni mimari | Yeni katman, yeni zincir, yeni terminoloji yok |
| Scope genişletme | Charter dışı dosya/signal/endpoint yok |
| Yeni tartışma | Hakem kararı yeniden açılmaz |

**Kural:** Compliance Fix yalnızca hakem kararını uygular.

---

## 10. Final Review

### Amacı

Compliance Fix (veya arbitration gerektirmeyen sprint) sonrasında **uygulamanın doğru yapıldığını** doğrulamak ve merge kararı vermek.

### Kapsamı

- Hakem kararı maddelerinin kodda karşılığı
- Architecture Compliance checklist (post-fix)
- Regression PASS kanıtı
- Scope creep taraması

### Sınırlar

Final Review:

- **Yeni mimari üretmez**
- **Yeni feature istemez**
- Yalnızca hakem kararının (varsa) doğru uygulandığını doğrular

**READY FOR MERGE** kararı burada verilir.

---

## 11. Merge Policy

### READY FOR MERGE

**Ne zaman:** Final Review PASS; tüm in-scope Architecture Violation giderildi; regression PASS; Stable Core PASS; scope creep yok.

**Anlam:** Ana dala merge edilebilir. Deploy kararı ayrıca deploy checklist'ine tabidir.

### READY WITH MINOR FIXES

**Ne zaman:** Tek bloklayıcı mimari ihlal yok; küçük, sınırlı düzeltmeler kaldı (ör. kalan C5 signal'ları, eksik test assert, dokümantasyon).

**Anlam:** Minor fix'ler tamamlanınca merge. Fix'ler Compliance Fix kapsamında veya Final Review action listesinde yazılı olmalı.

### NEEDS REWORK

**Ne zaman:** REAL VIOLATION uygulanmadı; regression FAIL; Stable Core riski; scope kontrolü kaybedildi; determinism bozuldu.

**Anlam:** Merge yasak. Yeniden Implementation veya Compliance Fix döngüsü gerekir.

---

## 12. Deploy Policy

### Deploy öncesi zorunlu şartlar

Tümü PASS olmalıdır:

| # | Şart |
|---|------|
| 1 | **Regression PASS** — sprint regression planı + Stable Core invariant testleri |
| 2 | **Final Review PASS** — merge kararı READY FOR MERGE |
| 3 | **Stable Core PASS** — escrow/wallet invariant testleri yeşil |
| 4 | **Architecture Compliance PASS** — post-merge/post-fix checklist |
| 5 | **Merge PASS** — CI yeşil, conflict yok |

### Deploy reddedilme kriterleri

Deploy **reddedilir** eğer:

- Regression veya Stable Core testlerinden biri FAIL
- Final Review tamamlanmamış veya NEEDS REWORK
- Bilinen REAL VIOLATION production'da
- Rollback planı yok (Engine sprint'leri için zorunlu)
- Intelligence deploy'u Engine deploy'unu zorunlu kılıyorsa ve Engine checklist tamamlanmamışsa (katmanlar ayrı deploy edilebilir olmalı — C20)

---

## 13. Scope Discipline

Aşağıdaki davranışlar **yasaktır**.

### Scope Creep

Sprint charter dışı işin review veya fix sırasında eklenmesi. Tespit edilirse revert veya backlog.

### Yeni mimari üretmek

Freeze sonrası sprint içinde yeni katman, yeni zincir veya yeni runtime modeli tasarlamak. ACP gerekir.

### Frozen Terminology değiştirmek

Yasak terimler veya anlam kayması (ör. Confidence = Probability). ACP gerekir.

### Stable Core'a gereksiz dokunmak

Intelligence veya UI sprint'inin escrow/wallet dosyalarını "kolaylık" için değiştirmesi.

### Review sırasında feature eklemek

Independent veya Final Review bulgusunu gerekçe göstererek yeni özellik eklemek.

### Compliance Fix sırasında yeni geliştirme yapmak

İhlal düzeltmesini bahane ederek catalog, signal veya API genişletmek.

---

## 14. Sprint Exit Criteria

Bir sprint **kapanmadan** aşağıdakilerin tamamı sağlanmalıdır:

- [ ] Architecture Compliance PASS (başlangıç ve kapanış)
- [ ] Implementation Plan onaylandı ve uygulandı
- [ ] Self Review tamamlandı
- [ ] Independent Review tamamlandı
- [ ] Architecture Arbitration tamamlandı (**gerekiyorsa**)
- [ ] Compliance Fix tamamlandı (**gerekiyorsa**)
- [ ] Final Review PASS ve merge kararı verildi
- [ ] Merge tamamlandı
- [ ] Regression PASS (merge öncesi veya merge sonrası deploy öncesi — sprint planında belirtilir)
- [ ] Deploy kararı verildi ve kayıt altına alındı (**production sprint ise**)
- [ ] Out-of-scope finding'ler backlog'a yazıldı
- [ ] Sprint özeti (ne değişti, ne değişmedi) arşivlendi

**Kural:** Eksik madde varsa sprint **açık** kalır; "tamamlandı" ilan edilemez.

---

## 15. Governance Kuralları

1. **Frozen Architecture değiştirilemez** — Normal sprint ile mimari, invariant veya resmi zincir değişmez. Değişiklik yalnızca ACP + Architecture Review ile.

2. **Development Protocol tüm sprintlerden üstündür** — Sprint charter veya aciliyet Protocol adımlarını atlatamaz.

3. **Architecture Review süreci atlanamaz** — Compliance → Review → (Arbitration) → Final Review zinciri zorunludur.

4. **Stable Core korunmalıdır** — Engine değişikliği ayrı sprint ve ayrı regression paketi ile.

5. **Engineering Process, kod kadar önemlidir** — Süreç ihlali, bug kadar ciddi merge engelidir.

6. **Hakem kararı kesindir** — Aynı konu ikinci arbitration'a girmez.

7. **Finding'ler sınıflandırılır** — Her bulgu Bölüm 18 taksonomisine girer.

8. **Out-of-scope başarısızlık sayılmaz** — Gelecek iş backlog'a alınır.

---

## 16. Living Workflow

Workflow **geliştirilebilir** — ancak aşağıdaki sınırlar geçerlidir.

### Architecture Freeze değişmeden değiştirilemez

- Sprint workflow **sırası** (Bölüm 3)
- Review modeli (Self → Independent → Arbitration → Final)
- Governance ilkeleri (Bölüm 2, 15)
- Merge ve deploy gate kriterlerinin **gevşetilmesi**

### Değiştirilebilir (ACP ile)

- Checklist maddelerinin detaylandırılması
- Review şablonları ve kayıt formatları
- CI entegrasyon detayları
- Sprint exit criteria'ya **yeni doğrulama** eklenmesi (gevşetme değil, sıkılaştırma)

**Kural:** Workflow değişikliği = ACP + Architecture Review onayı.

---

## 17. Review Scope

### Independent Review'un amacı

1. Sprint **kapsamını** doğrulamak
2. **Architecture Compliance**'ı doğrulamak
3. **Regression risklerini** belirlemek
4. Sprint kapsamındaki **eksiklikleri** ortaya çıkarmak

### Finding sınıflandırması — kapsam boyutu

| Tür | Sprint başarısını etkiler mi? |
|-----|-------------------------------|
| **In Scope Finding** | Evet — sprint kapanmadan işlenmeli, defer veya fix |
| **Out of Scope Finding** | **Hayır** — backlog'a alınır |

**Kural:** Out of Scope Finding, mevcut sprinti başarısız yapmaz.

### Sprint Charter sınırı

Review kapsamı **Sprint Charter ile sınırlıdır**. Reviewer, charter dışı iyileştirmeleri finding olarak kaydedebilir; ancak bunlar `Future Enhancement` veya `Out of Scope` olarak işaretlenir ve merge'i bloke etmez (Architecture Violation değilse).

---

## 18. Finding Governance

### Finding türleri

| Tür | Tanım |
|-----|-------|
| **Architecture Violation** | Freeze invariant veya frozen chain ihlali |
| **Compliance Issue** | Protocol veya checklist ihlali; mimari sınırda |
| **Regression** | Mevcut davranışın bozulması veya test FAIL |
| **Quality Improvement** | Mimari ihlal değil; kod/kanıt kalitesi |
| **Technical Debt** | Bilinçli ertelenmiş düzeltme |
| **Future Enhancement** | Charter dışı değer; sonraki sprint |

### Finding durumları

Her finding **yalnızca bir** durum alır:

| Durum | Anlam |
|-------|-------|
| **Fixed** | Sprint içinde giderildi |
| **Accepted Defer** | Bilinçli ertelendi; backlog kaydı var |
| **Rejected** | Geçersiz veya yanlış pozitif |
| **Future Sprint** | Ayrı sprint charter'ı açılacak |

### Arbitration ve finding yaşam döngüsü

1. Aynı finding **ikinci kez Architecture Arbitration'a taşınamaz**.
2. Hakem kararı sonrası **Compliance Fix** yalnızca kararı uygular.
3. **Final Review** yalnızca uygulamanın doğru yapıldığını doğrular.
4. Final Review'da ortaya çıkan **farklı** finding'ler **yeni sprint** olarak açılır; mevcut sprinti yeniden arbitration'a sokmaz (NEEDS REWORK hariç — uygulama hatası).

### Örnek akış

```
Independent Review: SIG-TIME-001 emit'te Event okuyor
  → Architecture Violation (In Scope)
  → Arbitration: REAL VIOLATION
  → Compliance Fix: metric gate'e taşı
  → Final Review: READY FOR MERGE

Independent Review: Actor signal'da boş event_sources
  → Quality Improvement (In Scope veya Out of Scope)
  → Arbitration açılmaz
  → Future Sprint veya Accepted Defer
```

---

## Governance Review

Mevcut Zinesh geliştirme sürecinin (Architecture Freeze sonrası pratik) değerlendirmesi.

### Güçlü yönler

1. **Architecture Freeze v1.0** — Mimari, terminoloji ve invariant'lar yazılı ve bağlayıcı.
2. **Katmanlı review kültürü** — Independent Review ve Architecture Arbitration (C5/C6) uygulandı; ihlal ile kalite ayrımı yapıldı.
3. **Stable Core bilinci** — Intelligence sprint'lerinde escrow/wallet izolasyonu hedeflendi.
4. **Determinism ve explainability** — Signal runtime'da evidence chain ve replayability testleri tanımlandı.
5. **Scope disiplini örnekleri** — Compliance Fix sprint'leri (S3.1B.1) yalnızca ihlal düzeltmesi için ayrıldı.
6. **Frozen belge seti** — Domain Model, Catalog, Risk Model referansları net.

### Zayıf yönler

1. **Regression kanıtı** — E2e paketleri tanımlı; her sprint sonunda sunucuda PASS kanıtı standart değil.
2. **Kısmi C5 kapanışı** — Arbitration sonrası bazı signal'lar hâlâ emit'te Event okuyor; "MINOR FIXES" ile merge edilebilir durumda.
3. **Metric katmanı sınırı** — Runtime içi metric türetimi (`emit_metrics`) ile `trust_intelligence_lib` sorumluluğu net yazılmamıştı (bu Protocol ile netleşiyor).
4. **Review kayıt formatı** — Finding sınıflandırması uygulandı ancak şablon henüz standart değil.
5. **Deploy gate otomasyonu** — Deploy öncesi checklist manuel; CI ile Protocol bağlantısı zayıf.

### Eksik governance kuralları (bu belge ile giderilen)

1. Resmi sprint workflow sırası
2. Arbitration'ın tek seferlik ve kesin olması
3. Compliance Fix yasakları
4. In Scope / Out of Scope finding ayrımı
5. Merge ve deploy policy tanımları
6. Sprint exit criteria

### Riskli süreçler

1. **Review sırasında scope creep** — "Bulurken düzeltelim" eğilimi Compliance Fix sınırlarını aşabilir.
2. **MINOR FIXES ile merge** — Küçük mimari borç birikimi; backlog disiplini gerekir.
3. **PHP/regression ortam bağımlılığı** — Test PASS kanıtı üretilemezse deploy gate işlevsiz kalır.
4. **İkinci arbitration talebi** — Tartışmanın yeniden açılması sprint'i kilitleyebilir (Protocol bunu yasaklar).

---

## Governance Review Kararı

# **READY WITH MINOR GOVERNANCE IMPROVEMENTS**

**Gerekçe:** Mimari freeze, review zinciri ve arbitration disiplini güçlü temel oluşturuyor. Eksik olan resmi süreç yazımı bu belgeyle kapatılıyor. Operasyonel olgunluk için minor iyileştirmeler gerekir: standart review şablonu, regression PASS kanıt zorunluluğunun CI'ya bağlanması, kalan mimari borçların backlog disiplini. Tam refactor gerekmez; Protocol v1.0 yürürlüğe girebilir.

---

## Onay

| Rol | Ad | Tarih | İmza |
|-----|-----|-------|------|
| Engineering | | | |
| Architecture | | | |
| Product | | | |

---

## Referans Dökümanlar

| Belge | Rol |
|-------|-----|
| `ARCHITECTURE_FREEZE_v1.md` | Mimari anayasa |
| `TRUST_DOMAIN_MODEL.md` | Ubiquitous Language |
| `TRUST_INTELLIGENCE_ARCHITECTURE.md` | Katman vizyonu |
| `TRUST_ONTOLOGY.md` | Güven ontologisi |
| `TRUST_SIGNAL_CATALOG.md` | SIG-* tanımları |
| `CONTEXT_TAXONOMY.md` | Context boyutları |
| `RISK_OBSERVATION_MODEL.md` | OBS-* tanımları |

---

*Bu belge Zinesh Engineering Governance'in resmi süreç anayasasıdır. Architecture Freeze v1.0 ile birlikte tüm sprintlerin üstündedir.*
