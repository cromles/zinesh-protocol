import { useState } from 'react';
import { Eye, FileText, Lock, Scale, Shield, X } from 'lucide-react';

export default function LandingSecurity() {
  const [modalOpen, setModalOpen] = useState(false);

  return (
    <section id="security" className="border-b border-slate-800 bg-slate-900 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <Lock className="h-3.5 w-3.5" /> Güvenlik mimarisi
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Emanet sürecinin temelleri</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Zinesh bir cüzdan veya pazaryeri değil; anlaşmayı kayıt altına alan emanet süreci ürünüdür.
          </p>
        </div>

        <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-6 md:grid-cols-3">
          <div className="space-y-3 rounded-2xl border border-slate-800 bg-slate-950 p-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
              <Lock className="h-5 w-5" />
            </div>
            <h3 className="text-base font-bold text-slate-100">Emanet kilidi</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              Karşılıklı onay sonrası tutar emanet sürecinde kilitlenir; onay öncesi serbest bırakılmaz.
            </p>
          </div>
          <div className="space-y-3 rounded-2xl border border-slate-800 bg-slate-950 p-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
              <Shield className="h-5 w-5" />
            </div>
            <h3 className="text-base font-bold text-slate-100">Şifreli oturum</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              Kimlik doğrulama ve panel erişimi güvenli oturum katmanı üzerinden yönetilir.
            </p>
          </div>
          <div className="space-y-3 rounded-2xl border border-slate-800 bg-slate-950 p-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
              <Scale className="h-5 w-5" />
            </div>
            <h3 className="text-base font-bold text-slate-100">Dijital sözleşme</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              Her emanet odası için sözleşme metni ve onay adımları kayıt altına alınır.
            </p>
          </div>
        </div>

        <div className="mx-auto mt-10 flex max-w-3xl flex-col items-center justify-between gap-4 rounded-2xl border border-emerald-500/30 bg-slate-950/80 p-6 sm:flex-row">
          <div className="flex items-center gap-3 text-xs text-slate-300">
            <FileText className="h-8 w-8 shrink-0 text-emerald-400" />
            <div>
              <div className="text-sm font-bold text-slate-100">Örnek emanet sözleşmesi taslağı</div>
              <div className="text-[11px] text-slate-400">Bilgilendirme amaçlı özet — hukuki danışmanlık değildir.</div>
            </div>
          </div>
          <button
            type="button"
            onClick={() => setModalOpen(true)}
            className="flex shrink-0 cursor-pointer items-center gap-2 rounded-xl bg-emerald-500 px-5 py-2.5 text-xs font-bold text-slate-950 transition-all hover:bg-emerald-400"
          >
            <Eye className="h-4 w-4" />
            Taslağı görüntüle
          </button>
        </div>
      </div>

      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm">
          <div className="relative max-h-[85vh] w-full max-w-2xl space-y-4 overflow-y-auto rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl sm:p-8">
            <button
              type="button"
              onClick={() => setModalOpen(false)}
              className="absolute right-5 top-5 cursor-pointer rounded-lg bg-slate-800 p-2 text-slate-400 hover:text-white"
            >
              <X className="h-5 w-5" />
            </button>
            <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-400">
              <Shield className="h-4 w-4" /> ÖRNEK EMANET SÖZLEŞMESİ TASLAĞI
            </div>
            <h3 className="text-xl font-bold text-slate-100">Emanet süreci özet maddeleri</h3>
            <div className="space-y-3 rounded-xl border border-slate-800 bg-slate-950 p-4 font-mono text-xs leading-relaxed text-slate-300">
              <p>
                <strong>MADDE 1:</strong> Taraflar dışarıda anlaşır; Zinesh emanet sürecini yönetir.
              </p>
              <p>
                <strong>MADDE 2:</strong> Tutar, karşılıklı onay ve kilit adımları tamamlanana kadar emanet sürecinde
                kalır.
              </p>
              <p>
                <strong>MADDE 3:</strong> Teslim onayı sonrası ödeme serbest bırakılır; ihtilaf halinde anlaşmazlık
                süreci işler.
              </p>
            </div>
            <p className="text-[11px] text-slate-500">Bu taslak yalnızca bilgilendirme amaçlıdır.</p>
            <button
              type="button"
              onClick={() => setModalOpen(false)}
              className="cursor-pointer rounded-lg bg-slate-800 px-4 py-2 text-xs font-bold text-slate-200 hover:bg-slate-700"
            >
              Kapat
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
