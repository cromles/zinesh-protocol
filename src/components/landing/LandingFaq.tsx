import { useState } from 'react';
import { ChevronDown, ChevronUp, HelpCircle, Search } from 'lucide-react';
import { LANDING_FAQ } from '../../data/landingContent';

const CATEGORIES = [
  { id: 'all', label: 'Tümü' },
  { id: 'genel', label: 'Genel' },
  { id: 'guvenlik', label: 'Güvenlik' },
  { id: 'anlasmazlik', label: 'Anlaşmazlık' },
  { id: 'ucretler', label: 'Ücretler' },
] as const;

export default function LandingFaq() {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [openId, setOpenId] = useState<string>('faq-1');

  const filteredFaqs = LANDING_FAQ.filter((item) => {
    const q = searchQuery.toLowerCase();
    const matchesSearch =
      item.question.toLowerCase().includes(q) || item.answer.toLowerCase().includes(q);
    const matchesCategory = selectedCategory === 'all' || item.category === selectedCategory;
    return matchesSearch && matchesCategory;
  });

  return (
    <section id="faq" className="border-b border-slate-800 bg-slate-950 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <HelpCircle className="h-3.5 w-3.5" /> SSS
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Sıkça sorulan sorular</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Zinesh emanet süreci, güvenlik ve ücretler hakkında temel bilgiler.
          </p>
        </div>

        <div className="mx-auto mt-8 max-w-2xl space-y-3">
          <div className="relative">
            <Search className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Soru ara..."
              className="w-full rounded-2xl border border-slate-800 bg-slate-900 py-3 pl-11 pr-4 text-xs text-slate-200 focus:border-emerald-500 focus:outline-none"
            />
          </div>
          <div className="flex flex-wrap justify-center gap-2 text-xs">
            {CATEGORIES.map((cat) => (
              <button
                key={cat.id}
                type="button"
                onClick={() => setSelectedCategory(cat.id)}
                className={`cursor-pointer rounded-lg px-3.5 py-1.5 font-medium transition-all ${
                  selectedCategory === cat.id
                    ? 'bg-emerald-500 font-bold text-slate-950'
                    : 'border border-slate-800 bg-slate-900 text-slate-400 hover:text-white'
                }`}
              >
                {cat.label}
              </button>
            ))}
          </div>
        </div>

        <div className="mx-auto mt-8 max-w-3xl space-y-3">
          {filteredFaqs.length > 0 ? (
            filteredFaqs.map((faq) => {
              const isOpen = openId === faq.id;
              return (
                <div key={faq.id} className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
                  <button
                    type="button"
                    onClick={() => setOpenId(isOpen ? '' : faq.id)}
                    className="flex w-full cursor-pointer items-center justify-between gap-4 px-6 py-4 text-left text-sm font-bold text-slate-100 transition-colors hover:text-emerald-400"
                  >
                    <span>{faq.question}</span>
                    {isOpen ? (
                      <ChevronUp className="h-4 w-4 shrink-0 text-emerald-400" />
                    ) : (
                      <ChevronDown className="h-4 w-4 shrink-0 text-slate-500" />
                    )}
                  </button>
                  {isOpen && (
                    <div className="border-t border-slate-800/60 px-6 pb-5 pt-3 text-xs leading-relaxed text-slate-300">
                      {faq.answer}
                    </div>
                  )}
                </div>
              );
            })
          ) : (
            <div className="py-8 text-center text-xs text-slate-500">Aramanızla eşleşen soru bulunamadı.</div>
          )}
        </div>
      </div>
    </section>
  );
}
