import { useState } from 'react';
import { Calculator } from 'lucide-react';
import {
  ZINESH_JOB_COMMISSION_RATE,
  calculateEscrowFee,
  formatCommissionPercent,
} from '../../lib/protocolRates';

export default function LandingFeeCalculator() {
  const [amount, setAmount] = useState(50000);
  const [feePayer, setFeePayer] = useState<'split' | 'buyer' | 'seller'>('split');

  const rate = ZINESH_JOB_COMMISSION_RATE;
  const totalFee = calculateEscrowFee(amount, rate);

  let buyerPaysFee = 0;
  let sellerPaysFee = 0;
  if (feePayer === 'buyer') buyerPaysFee = totalFee;
  else if (feePayer === 'seller') sellerPaysFee = totalFee;
  else {
    buyerPaysFee = Math.round(totalFee / 2);
    sellerPaysFee = totalFee - buyerPaysFee;
  }

  const buyerTotal = amount + buyerPaysFee;
  const sellerNet = amount - sellerPaysFee;

  return (
    <section id="calculator" className="relative border-b border-slate-800 bg-slate-900 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <Calculator className="h-3.5 w-3.5" /> Komisyon hesaplayıcı
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Emanet komisyonu tahmini</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Oran, Zinesh protokol sabitlerinden alınır. Kesin tutar işlem anında konsolda doğrulanır.
          </p>
        </div>

        <div className="mx-auto mt-12 grid max-w-4xl grid-cols-1 items-center gap-8 rounded-3xl border border-slate-800 bg-slate-950 p-6 shadow-2xl sm:p-8 md:grid-cols-12">
          <div className="space-y-6 md:col-span-7">
            <div>
              <div className="mb-2 flex items-center justify-between">
                <label className="text-xs font-bold uppercase tracking-wider text-slate-300">İşlem tutarı</label>
                <span className="font-mono text-2xl font-black text-emerald-400">
                  ₺{amount.toLocaleString('tr-TR')}
                </span>
              </div>
              <input
                type="range"
                min={1000}
                max={500000}
                step={2500}
                value={amount}
                onChange={(e) => setAmount(Number(e.target.value))}
                className="w-full cursor-pointer accent-emerald-400"
              />
              <div className="mt-1 flex justify-between font-mono text-[10px] text-slate-500">
                <span>₺1.000</span>
                <span>₺250.000</span>
                <span>₺500.000+</span>
              </div>
            </div>

            <div>
              <label className="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-300">
                Komisyon paylaşımı
              </label>
              <div className="grid grid-cols-3 gap-2">
                {(
                  [
                    ['split', '%50 / %50'],
                    ['buyer', 'Sadece alıcı'],
                    ['seller', 'Sadece satıcı'],
                  ] as const
                ).map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setFeePayer(key)}
                    className={`cursor-pointer rounded-xl px-3 py-2.5 text-center text-xs font-bold transition-all ${
                      feePayer === key
                        ? 'bg-emerald-500 text-slate-950 shadow'
                        : 'border border-slate-800 bg-slate-900 text-slate-400 hover:text-white'
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            <div className="space-y-1.5 rounded-xl border border-slate-800 bg-slate-900/60 p-4 text-xs">
              <div className="flex items-center justify-between font-bold text-slate-300">
                <span>Protokol komisyon oranı:</span>
                <span className="font-mono text-emerald-400">{formatCommissionPercent(rate)}</span>
              </div>
              <p className="text-[11px] text-slate-400">
                Kaynak: <code className="text-emerald-300">protocol_constants.php</code> → job_commission_rate
              </p>
            </div>
          </div>

          <div className="space-y-4 rounded-2xl border border-slate-800 bg-slate-900 p-6 md:col-span-5">
            <h3 className="border-b border-slate-800 pb-2 text-xs font-bold uppercase tracking-wider text-slate-400">
              Tahmini özet
            </h3>
            <div className="space-y-3 text-xs">
              <div className="flex items-center justify-between">
                <span className="text-slate-400">Toplam komisyon:</span>
                <span className="font-mono text-lg font-bold text-emerald-400">
                  ₺{totalFee.toLocaleString('tr-TR')}
                </span>
              </div>
              <div className="flex items-center justify-between border-t border-slate-800/80 pt-2 text-slate-300">
                <span>Alıcı toplam:</span>
                <span className="font-mono font-bold text-white">₺{buyerTotal.toLocaleString('tr-TR')}</span>
              </div>
              <div className="flex items-center justify-between text-slate-300">
                <span>Satıcı net:</span>
                <span className="font-mono font-bold text-white">₺{sellerNet.toLocaleString('tr-TR')}</span>
              </div>
            </div>
            <p className="rounded-xl border border-amber-500/20 bg-amber-950/20 p-3 text-[10px] text-amber-200/80">
              Bu hesaplayıcı bilgilendirme amaçlıdır; gerçek işlem tutarı konsolda onaylanır.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
