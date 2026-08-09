<?php
declare(strict_types=1);

/**
 * Context Taxonomy v0.1 — resmi CTX-* tanımları (salt metadata).
 * Kaynak: docs/CONTEXT_TAXONOMY.md
 * Değerlendirme: context_resolver_lib.php
 */
const ZINESH_CONTEXT_TAXONOMY_VERSION = '0.1';

/** @return list<string> */
function zinesh_context_catalog_ids(): array
{
    return [
        'CTX-AGY',
        'CTX-B2B',
        'CTX-CNS',
        'CTX-CON',
        'CTX-DIG',
        'CTX-DSG',
        'CTX-LNG',
        'CTX-ONE',
        'CTX-PHY',
        'CTX-SEC',
        'CTX-SW',
        'CTX-VID',
    ];
}

/** @return array<string,array<string,mixed>> */
function zinesh_context_catalog_definitions(): array
{
    return [
        'CTX-SW' => [
            'title' => 'Freelance Yazılım',
            'dimensions' => [
                'job_type' => 'software',
                'delivery_model' => 'digital_iterative',
                'complexity' => 'medium-high',
            ],
        ],
        'CTX-DSG' => [
            'title' => 'Grafik Tasarım',
            'dimensions' => [
                'offering' => 'logo/branding',
                'delivery_model' => 'digital',
                'complexity' => 'medium',
            ],
        ],
        'CTX-VID' => [
            'title' => 'Video Prodüksiyon',
            'dimensions' => [
                'offering' => 'video',
                'delivery_model' => 'milestone',
                'time_profile' => 'medium',
            ],
        ],
        'CTX-CNS' => [
            'title' => 'Danışmanlık',
            'dimensions' => [
                'job_type' => 'consulting',
                'deliverable' => 'service_hours',
                'contract_type' => 'hourly_estimated',
            ],
        ],
        'CTX-PHY' => [
            'title' => 'Fiziksel Ürün',
            'dimensions' => [
                'delivery_model' => 'physical_shipping',
                'deliverable' => 'physical_product',
            ],
        ],
        'CTX-SEC' => [
            'title' => 'İkinci El Ticaret',
            'dimensions' => [
                'offering' => 'used_device',
                'complexity' => 'low',
                'party_relationship' => 'marketplace_stranger',
            ],
        ],
        'CTX-B2B' => [
            'title' => 'Kurumsal B2B',
            'dimensions' => [
                'complexity' => 'enterprise',
                'amount_band' => 'high',
                'contract_type' => 'milestone',
            ],
        ],
        'CTX-ONE' => [
            'title' => 'Tek Seferlik Hizmet',
            'dimensions' => [
                'frequency' => 'one_off',
                'time_profile' => 'short_term',
            ],
        ],
        'CTX-LNG' => [
            'title' => 'Uzun Süreli Proje',
            'dimensions' => [
                'time_profile' => 'long_term',
                'contract_type' => 'milestone',
            ],
        ],
        'CTX-DIG' => [
            'title' => 'Dijital Teslimat',
            'dimensions' => [
                'delivery_model' => 'digital_instant',
                'deliverable' => 'digital_asset',
            ],
        ],
        'CTX-CON' => [
            'title' => 'İnşaat / Fiziksel Proje',
            'dimensions' => [
                'sector' => 'construction',
                'delivery_model' => 'on_site',
                'time_profile' => 'long_term',
            ],
        ],
        'CTX-AGY' => [
            'title' => 'Ajans / Portföy',
            'dimensions' => [
                'frequency' => 'agency_portfolio',
                'party_relationship' => 'repeat_pair',
            ],
        ],
    ];
}

/**
 * Signal → Context yorum çerçeveleri (CONTEXT_TAXONOMY.md §5).
 *
 * @return array<string,array<string,string>>
 */
function zinesh_context_catalog_signal_interpretations(): array
{
    return [
        'SIG-NEG-001' => [
            'CTX-SW' => 'Yüksek revizyon sıklığı beklenen davranış olabilir; scope refinement normal.',
            'CTX-LNG' => 'Versiyon artışı milestone yapısıyla uyumlu olabilir.',
            'CTX-SEC' => 'Çok revizyon dikkat gerektiren gözlem olabilir; basit ürün satışında nadir.',
            'CTX-ONE' => 'Yüksek revizyon, basit iş beklentisiyle çelişebilir — kullanıcı sözleşmeyi kontrol etmeli.',
        ],
        'SIG-NEG-002' => [
            'CTX-B2B' => 'Çok turlu müzakere yaygın olabilir.',
            'CTX-SEC' => 'Fiyat pazarlığı normal; aşırı tur belirsizlik göstergesi olabilir.',
            'CTX-CNS' => 'Saatlik/kapsam pazarlığı beklenen olabilir.',
        ],
        'SIG-NEG-003' => [
            'CTX-DIG' => 'Düşük tur normal olabilir.',
            'CTX-B2B' => 'Düşük tur, yetersiz inceleme riski olabilir — bilgilendirme.',
            'CTX-ONE' => 'Düşük tur, basit iş için beklenen olabilir.',
        ],
        'SIG-TIME-001' => [
            'CTX-DIG' => 'Mikro işlerde hızlı kabul normal olabilir.',
            'CTX-B2B' => 'Çok hızlı kabul, detaylı inceleme ihtiyacını artırabilir (bilgi, hüküm değil).',
            'CTX-AGY' => 'Tekrarlayan eşleşmede hız beklenen olabilir.',
        ],
        'SIG-TIME-002' => [
            'CTX-B2B' => 'Uzun müzakere normal olabilir.',
            'CTX-ONE' => 'Uzun müzakere, basit iş için olağandışı olabilir.',
            'CTX-LNG' => 'Uzun müzakere uzun vadeli projelerde normal olabilir.',
        ],
        'SIG-TIME-003' => [
            'CTX-CON' => 'Gecikme sektör normları içinde olabilir (sözleşme tarihi varsa ona bakılır).',
            'CTX-DIG' => 'Kısa döngülü dijital işte gecikme dikkat çağırıcı olabilir.',
            'CTX-VID' => 'Revizyon turu nedeniyle gecikme yorumlanabilir — milestone context gerekir.',
        ],
        'SIG-TIME-004' => [
            'CTX-B2B' => 'Structured deadline varsa gözlem objektiftir; sektör yorumu bağlama bağlıdır.',
            'CTX-LNG' => 'Milestone projelerde teslim kayması bağlamsal okunmalıdır.',
        ],
        'SIG-DSP-001' => [
            'CTX-SEC' => 'Dispute oranı platform ortalamasıyla kıyas anlamlı olabilir.',
            'CTX-SW' => 'Dispute açan tarafın rolü signal\'da ayırt edilmez — mağdur da dispute açar.',
        ],
        'SIG-SET-001' => [
            'CTX-ONE' => 'İlk işlem bandında oran anlamsız olabilir (n küçük).',
            'CTX-AGY' => 'Yüksek tamamlama oranı beklenen profil olabilir.',
        ],
        'SIG-SET-002' => [
            'CTX-LNG' => 'Aktif devam eden işlerde oran geçici olarak düşük görünebilir.',
            'CTX-B2B' => 'Kurumsal işlerde tamamlama süreci uzayabilir.',
        ],
        'SIG-SET-003' => [
            'CTX-SW' => 'Settlement başarısızlığı infra veya süreç gözlemi olabilir (SIG-ACT-002 ile birlikte okunmalı).',
            'CTX-B2B' => 'Engine recovery kullanıcı hatası değil, infra gözlemi olabilir.',
        ],
        'SIG-BEH-001' => [
            'CTX-ONE' => 'Veri yetersizliği gözlemi; risk etiketi değil.',
            'CTX-B2B' => 'Yüksek tutar bandında kullanıcı ekstra dikkat isteyebilir — karar kullanıcıda.',
            'CTX-SW' => 'İlk işlem; geçmiş pattern henüz oluşmamış olabilir.',
        ],
        'SIG-BEH-002' => [
            'CTX-SW' => 'Tekrarlayan eşleşme olağan olabilir.',
            'CTX-AGY' => 'Tekrarlayan işbirliği ajans/portföy profiliyle uyumlu olabilir.',
            'CTX-SEC' => 'Repeat yoksa normal; repeat varsa ilişki gözlemi (hüküm değil).',
        ],
        'SIG-BEH-003' => [
            'CTX-SW' => 'Karışık iş türlerinde tutarlılık metriği yanıltıcı olabilir — segment gerekir.',
            'CTX-B2B' => 'Tamamlama süresi ortalaması iş türüne göre okunmalıdır.',
        ],
        'SIG-CTR-001' => [
            'CTX-SW' => 'Tutar/scope değişimi yazılım ve uzun projede beklenebilir.',
            'CTX-LNG' => 'Sözleşme volatilitesi milestone projelerde görülebilir.',
            'CTX-SEC' => 'Volatilite düşük karmaşıklıkta olağandışı olabilir.',
        ],
        'SIG-CTR-002' => [
            'CTX-B2B' => 'Kabul sonrası değişiklik yok pozitif süreç göstergesi olabilir (hüküm değil).',
            'CTX-SW' => 'Stabil sözleşme yazılım işlerinde olumlu süreç göstergesi olabilir.',
        ],
        'SIG-ACT-001' => [
            'CTX-LNG' => 'Uzun sessizlik beklenen olabilir.',
            'CTX-DIG' => 'Dijital anlık teslimatta sessizlik dikkat çağırıcı olabilir.',
        ],
        'SIG-ACT-002' => [
            'CTX-SW' => 'Actor davranışı değil, sistem olayı; risk actor\'a yüklenmemeli.',
            'CTX-B2B' => 'Actor davranışı değil, sistem olayı; risk actor\'a yüklenmemeli.',
            'CTX-ONE' => 'Actor davranışı değil, sistem olayı; risk actor\'a yüklenmemeli.',
        ],
    ];
}

/** @return array<string,list<string>> */
function zinesh_context_catalog_signal_universal_contexts(): array
{
    return [
        'SIG-DSP-001' => ['CTX-SEC', 'CTX-SW', 'CTX-B2B', 'CTX-ONE'],
        'SIG-BEH-001' => ['CTX-ONE', 'CTX-B2B', 'CTX-SW'],
        'SIG-ACT-002' => ['CTX-SW', 'CTX-B2B', 'CTX-ONE', 'CTX-PHY', 'CTX-SEC'],
    ];
}
