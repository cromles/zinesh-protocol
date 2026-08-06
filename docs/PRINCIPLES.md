# Zinesh Protokolü — Anayasal İlkeler

Bu belge, Zinesh Protokolü'nün değişmez felsefi ve tasarım ilkelerini tanımlar. Tüm gelecek teknik kararlar bu çerçeveye uygun olmalıdır.

---

## 1. Protokolün Amacı

Zinesh, gerçek dünyadaki hizmet ve ticaret uyuşmazlıklarını çözmek için tasarlanmış bir **TL emanet ve güven protokolüdür**.

Zinesh bir kripto yatırım projesi, token satışı veya getiri vaadi platformu değildir. Spekülasyon ve fiyat teşviki protokolün parçası değildir.

---

## 2. TL Emanet İlkesi

İş ödemeleri **Türk Lirası** ile yapılır. Para doğrudan karşı tarafa gitmez; emanet kasasında kilitlenir.

Protokol şunları **kabul eder**:

- Havale/EFT veya kart ile TL yatırma (yapılandırıldığında)
- Tarafların anlaşmasıyla emanet serbest bırakma
- İptal ve iade kuralları

Protokol şunu **reddeder**:

- Yatırım veya getiri vaadi
- Gizli komisyon tuzağı
- Tek taraflı, kanıtsız para çekimi

---

## 3. Kazanılmış Güven İlkesi

**Güven satın alınamaz; yalnızca kazanılır.**

Aşağıdaki faaliyetler **anlamlı katkı** sayılır:

- Sorunsuz tamamlanan emanetler
- Gerçek uyuşmazlıklarda hakem olarak görev almak (hedef tasarım)
- Doğru hakem kararları
- Güven puanı (TrustScore) kazanmak
- Sistem içinde sürekli ve dürüst faaliyet

Aşağıdaki faaliyetler **katkı sayılmaz**:

- Sahte hesap açmak
- Yalnızca beklemek
- Yapay iş döngüleri

---

## 4. Hakemlik İlkesi (hedef tasarım)

Hakem statüsü **satın alınamaz**, yalnızca **kazanılır**.

Hakem olmak protokol içi performans ve zaman gerektirir. Güven puanı hakemliğin ön koşuludur; güvenin tek kaynağı değildir.

---

## 5. Merkeziyetsizlik ve Şeffaflık

v1.1 üretim ortamı merkezi sunucu mimarisidir. Kullanıcılar kendi emanet geçmişlerini ve bakiyelerini görebilir.

Kararlar (hedef tasarımda) insan hakemleri tarafından verilir. Yapay zeka modelleri nihai karar veremez.

---

## 6. Değişmez Operasyonel Sabitler

| Sabit | Değer |
|-------|-------|
| Para birimi | TL (₺) |
| Emanet komisyonu | %5 (tamamlanan işlerde) |
| TrustScore başlangıç | 50 |
| TrustScore aralığı | 0–100 |

---

## 7. TrustScore İlkesi

TrustScore, kullanıcıların protokol içi dürüstlük ve performansının ölçüsüdür.

- Başlangıç puanı: 50
- Minimum: 0 — Maksimum: 100
- TrustScore parayla satın alınamaz

TrustScore yalnızca protokol içi olaylarla güncellenir.

---

## 8. Emanet İlkesi

- İş bedeli işveren tarafından emanet kasasına kilitlenir
- İş tamamlanınca taraflar onaylar; ödeme serbest bırakılır
- Uyuşmazlık açmak hedef tasarımda mümkündür; maliyet haksız tarafa yansır
- Anlaşmazlıkta hakemler kanıtlara bakar

---

## 9. Özet

```
Para kasada bekler.
İş bitince onaylarsın.
Güven zamanla inşa edilir.
Güven satın alınamaz.
```

---

Bu belge mevcut TL emanet implementasyonunu ve Zinesh Protokolü'nün uzun vadeli yönünü tanımlar.
