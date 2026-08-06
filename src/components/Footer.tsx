import React from 'react';
import { Send, Mail, Instagram } from 'lucide-react';
import ZineshLogo from './ZineshLogo';

export default function Footer() {
  return (
    <footer id="footer" className="relative border-t border-white/[0.06] bg-[#040408] px-4 sm:px-6 w-full max-w-full">
      <div className="section-screen-inner mx-auto max-w-7xl w-full">
        <div className="pb-12 border-b border-white/10">
          <div className="max-w-md">
            <div className="flex items-center gap-3.5">
              <ZineshLogo
                size="sm"
                showText={false}
                transparentBg={true}
                pulseGlow={false}
              />
              <span className="font-display text-xl font-black tracking-tighter uppercase text-white tracking-[0.02em]">
                ZINESH
              </span>
            </div>

            <p className="mt-4 font-sans text-sm text-white/70 leading-relaxed font-medium">
              Önce yazılı sözleşme, sonra kasa. Tanımadığın biriyle güven içinde iş yapmanı sağlar;
              anlaşmazlıkta karar sözleşmeye göre verilir.
            </p>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row justify-between items-center gap-6 pt-12">
          <p className="font-mono text-[10px] tracking-widest text-zinc-400 font-medium uppercase">
            ÖNCE SÖZLEŞME · SONRA KASA
          </p>

          <div className="flex items-center gap-4 flex-wrap justify-center sm:justify-end">
            <a
              href="/nedir/"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              Zinesh Nedir?
            </a>

            <a
              href="/kurucu/"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              Kurucu
            </a>

            <a
              href="/sss/"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              SSS
            </a>

            <a
              href="https://t.me/zinesh1"
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              <Send className="h-3.5 w-3.5 text-sky-400" />
              <span>Telegram</span>
            </a>

            <a
              href="https://x.com/zineshprotocol"
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              <svg className="h-3.5 w-3.5 text-zinc-300" viewBox="0 0 24 24" fill="currentColor">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
              </svg>
              <span>X (Twitter)</span>
            </a>

            <a
              href="https://www.instagram.com/zinesh.protocol?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw=="
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition"
            >
              <Instagram className="h-3.5 w-3.5 text-pink-400" />
              <span>Instagram</span>
            </a>

            <a
              href="mailto:zinesh.protocol@gmail.com"
              className="flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 border border-white/10 font-mono text-xs text-zinc-300 hover:text-white hover:bg-white/10 transition max-w-full"
            >
              <Mail className="h-3.5 w-3.5 text-purple-400 shrink-0" />
              <span className="truncate">zinesh.protocol@gmail.com</span>
            </a>
          </div>
        </div>

        <div className="mt-8 pt-6 border-t border-white/5 text-center space-y-4">
          <p className="font-sans text-[10.5px] text-zinc-400 font-medium max-w-4xl mx-auto leading-relaxed">
            <strong>Yasal Bilgilendirme:</strong> Zinesh bir yatırım ürünü veya kripto borsası değildir.
            Tanımadığınız kişilerle yaptığınız anlaşmalarda şartlar yazılı sözleşmede tutulur; para emanet
            kasasında bekler. Anlaşmazlıkta yazılı sözleşme esas alınır. Yatırım tavsiyesi, kâr vaadi veya
            garantili getiri sunulmaz.
          </p>
          <div className="pt-2 font-mono text-[11px] text-zinc-400 space-y-1">
            <div className="font-bold text-white tracking-wider">Zinesh</div>
            <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
              <a href="/gizlilik/" className="hover:text-zinc-200 transition">
                Gizlilik
              </a>
              <a href="/kullanim-kosullari/" className="hover:text-zinc-200 transition">
                Kullanım Koşulları
              </a>
            </div>
            <div>Kurucu: Yasin Karademir</div>
            <div className="text-zinc-400 font-medium">© 2026 Zinesh. Tüm hakları saklıdır.</div>
          </div>
        </div>
      </div>
    </footer>
  );
}
