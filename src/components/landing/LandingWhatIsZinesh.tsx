import { ArrowRight, CheckCircle, Eye, FileText, Lock, RefreshCw, Scale, ShieldCheck } from 'lucide-react';

const STEPS = [
  {
    num: '01',
    title: 'Dışarıda anlaşın',
    body: 'Fiyat ve teslim koşulları taraflar arasında netleşir. Zinesh eşleştirme yapmaz.',
    icon: FileText,
    tag: 'Ön anlaşma',
  },
  {
    num: '02',
    title: 'Zinesh\'te bağlanın',
    body: 'Karşı tarafın 5 haneli üye numarasıyla emanet odası açılır; sözleşme metni oluşturulur.',
    icon: Lock,
    tag: 'Üye no ile bağlantı',
  },
  {
    num: '03',
    title: 'Tutar kilitlenir',
    body: 'Karşılıklı onay sonrası tutar emanet sürecinde kilitlenir.',
    icon: RefreshCw,
    tag: 'Kilit',
  },
  {
    num: '04',
    title: 'Teslim ve inceleme',
    body: 'İş gerçekleştirilir; taraflar konsolda durumu takip eder.',
    icon: Eye,
    tag: 'Kayıt altı',
  },
  {
    num: '05',
    title: 'Onay veya anlaşmazlık',
    body: 'Onay sonrası ödeme serbest bırakılır; sorun varsa anlaşmazlık süreci başlar.',
    icon: ArrowRight,
    tag: 'Kapanış',
    highlight: true,
  },
] as const;

export default function LandingWhatIsZinesh() {
  return (
    <section id="how-it-works" className="relative border-b border-slate-800 bg-slate-900 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-4 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <ShieldCheck className="h-4 w-4" /> Nasıl çalışır?
          </div>
          <h2 className="text-3xl font-extrabold text-slate-100 sm:text-4xl">Güvenmek zorunda kalmadan anlaşın</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Zinesh bir kripto cüzdanı veya pazaryeri değildir. Mevcut anlaşmanızı emanet odasında yürütmenizi sağlar.
          </p>
        </div>

        <div className="mt-14 grid grid-cols-1 gap-4 md:grid-cols-5">
          {STEPS.map((step) => {
            const Icon = step.icon;
            return (
              <div
                key={step.num}
                className={`flex flex-col justify-between rounded-2xl border p-5 transition-all hover:border-emerald-500/50 ${
                  step.highlight
                    ? 'border-emerald-500/40 bg-slate-950/80 shadow-lg shadow-emerald-950/30'
                    : 'border-slate-800 bg-slate-950/80'
                }`}
              >
                <div>
                  <div className="mb-3 flex items-center justify-between">
                    <span
                      className={`flex h-8 w-8 items-center justify-center rounded-lg border text-sm font-bold ${
                        step.highlight
                          ? 'border-emerald-500 bg-emerald-500 text-slate-950'
                          : 'border-emerald-500/30 bg-emerald-500/20 text-emerald-400'
                      }`}
                    >
                      {step.num}
                    </span>
                    <Icon className="h-5 w-5 text-slate-400" />
                  </div>
                  <h3 className={`mb-1 text-base font-bold ${step.highlight ? 'text-emerald-400' : 'text-slate-100'}`}>
                    {step.title}
                  </h3>
                  <p className="text-xs leading-relaxed text-slate-400">{step.body}</p>
                </div>
                <div className="mt-4 flex items-center gap-1 border-t border-slate-800 pt-3 text-[11px] font-medium text-emerald-400">
                  <CheckCircle className="h-3.5 w-3.5" />
                  {step.tag}
                </div>
              </div>
            );
          })}
        </div>

        <div className="mt-16 grid grid-cols-1 gap-6 md:grid-cols-3">
          <div className="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
            <Lock className="mb-4 h-6 w-6 text-emerald-400" />
            <h3 className="mb-2 text-lg font-bold text-slate-100">Emanet kilidi</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              Onay öncesi tutar serbest bırakılmaz; süreç konsolda kayıt altına alınır.
            </p>
          </div>
          <div className="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
            <Scale className="mb-4 h-6 w-6 text-emerald-400" />
            <h3 className="mb-2 text-lg font-bold text-slate-100">Anlaşmazlık süreci</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              İhtilaf durumunda kayıtlı sözleşme ve kanıtlar esas alınır; fonlar kilitli kalır.
            </p>
          </div>
          <div className="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
            <FileText className="mb-4 h-6 w-6 text-emerald-400" />
            <h3 className="mb-2 text-lg font-bold text-slate-100">Yazılı sözleşme</h3>
            <p className="text-xs leading-relaxed text-slate-400">
              Her oda için sözleşme metni ve onay adımları saklanır; önce sözleşme, sonra kasa.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
