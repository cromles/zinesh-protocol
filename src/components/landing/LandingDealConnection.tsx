import { useState } from 'react';
import { ArrowRight, Link2, ShieldCheck, UserPlus } from 'lucide-react';

type LandingDealConnectionProps = {
  onJoinClick: () => void;
  isLoggedIn?: boolean;
  onGoToConsole?: () => void;
};

export default function LandingDealConnection({
  onJoinClick,
  isLoggedIn,
  onGoToConsole,
}: LandingDealConnectionProps) {
  const [partnerId, setPartnerId] = useState('');
  const [dealTitle, setDealTitle] = useState('');
  const [dealAmount, setDealAmount] = useState('');

  const handleContinue = () => {
    if (isLoggedIn) {
      onGoToConsole?.();
    } else {
      onJoinClick();
    }
  };

  return (
    <section id="deal-connection" className="border-b border-slate-800 bg-slate-950 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <Link2 className="h-3.5 w-3.5" /> Bağlantı hazırlığı
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Karşı tarafı üye numarasıyla bağlayın</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Zinesh davet linki üretmez; taraflar konsolda 5 haneli üye numarası ile birbirine bağlanır.
          </p>
        </div>

        <div className="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-8 rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl sm:p-8 md:grid-cols-2">
          <div className="space-y-4">
            <h3 className="flex items-center gap-2 text-base font-bold text-slate-100">
              <UserPlus className="h-5 w-5 text-emerald-400" />
              Bağlantı bilgileri
            </h3>

            <div>
              <label className="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-400">
                Karşı taraf üye numarası
              </label>
              <input
                type="text"
                value={partnerId}
                onChange={(e) => setPartnerId(e.target.value.replace(/\D/g, '').slice(0, 5))}
                className="w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-2.5 font-mono text-sm text-emerald-400 focus:border-emerald-500 focus:outline-none"
                placeholder="Örn: 88214"
                maxLength={5}
                inputMode="numeric"
              />
              <p className="mt-1 text-[10px] text-slate-500">5 haneli ZN-ID — gerçek bağlantı konsolda yapılır.</p>
            </div>

            <div>
              <label className="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-400">
                İşlem tanımı (isteğe bağlı)
              </label>
              <input
                type="text"
                value={dealTitle}
                onChange={(e) => setDealTitle(e.target.value)}
                className="w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-2.5 text-xs text-slate-200 focus:border-emerald-500 focus:outline-none"
                placeholder="Örn: Domain devri"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-400">
                Anlaşılan tutar (TL, isteğe bağlı)
              </label>
              <input
                type="number"
                value={dealAmount}
                onChange={(e) => setDealAmount(e.target.value)}
                className="w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-2.5 font-mono text-xs text-slate-200 focus:border-emerald-500 focus:outline-none"
                placeholder="25000"
              />
            </div>

            <button
              type="button"
              onClick={handleContinue}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-400 py-3 text-xs font-black uppercase tracking-wider text-slate-950 shadow-md transition-all hover:bg-emerald-300"
            >
              {isLoggedIn ? 'Konsolda devam et' : 'Giriş yap ve bağlan'}
              <ArrowRight className="h-4 w-4" />
            </button>
          </div>

          <div className="flex flex-col justify-between space-y-4 rounded-2xl border border-slate-800 bg-slate-950 p-6">
            <div>
              <div className="mb-3 flex items-center justify-between border-b border-slate-800 pb-3">
                <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-emerald-400">
                  <ShieldCheck className="h-4 w-4" /> Önizleme
                </span>
                <span className="font-mono text-[10px] text-slate-500">UI hazırlığı</span>
              </div>
              <div className="space-y-2 text-xs">
                <div className="text-sm font-bold text-slate-300">
                  {dealTitle.trim() || 'İşlem tanımı girilmedi'}
                </div>
                {dealAmount && (
                  <div className="font-mono text-xl font-black text-emerald-400">
                    ₺{Number(dealAmount).toLocaleString('tr-TR')}
                  </div>
                )}
                {partnerId && (
                  <p className="font-mono text-emerald-300">
                    Karşı taraf: <strong>ZN-{partnerId.padStart(5, '0')}</strong>
                  </p>
                )}
                <p className="text-[11px] leading-relaxed text-slate-400">
                  Gerçek emanet odası oluşturma ve bağlantı işlemi giriş yaptıktan sonra konsolda tamamlanır.
                </p>
              </div>
            </div>
            <div className="rounded-xl border border-amber-500/20 bg-amber-950/20 p-3 text-[10px] text-amber-200/90">
              Bu bölüm mock URL üretmez. Davet token sistemi henüz yoktur; mevcut üye numarası bağlantısı
              kullanılır.
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
