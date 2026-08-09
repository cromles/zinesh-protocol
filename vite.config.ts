import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import type { Plugin } from 'vite';
import { defineConfig } from 'vite';

/** Build çıktısındaki CSS linkini render-blocking olmaktan çıkarır. */
function asyncCssPlugin(): Plugin {
  return {
    name: 'zinesh-async-css',
    apply: 'build',
    transformIndexHtml(html, ctx) {
      if (ctx.path.includes('teknik-dokuman')) {
        return html;
      }
      return html.replace(
        /<link rel="stylesheet" crossorigin href="([^"]+\.css)">/g,
        (_, href) =>
          `<link rel="preload" href="${href}" as="style" onload="this.onload=null;this.rel='stylesheet'">` +
          `<noscript><link rel="stylesheet" href="${href}"></noscript>`,
      );
    },
  };
}

/** Lazy chunk'lar için modulepreload üretme — critical path'i şişirmez. */
const DEFERRED_CHUNK_PATTERN =
  /(?:firebase|motion|vendor-lucide|AlphaConsole|LeadModal|Toaster|FoundingCampaign|HowItWorks|SolutionSection|EconomicModel|ZineshEmergence|Footer)/;

function manualVendorChunk(id: string): string | undefined {
  if (!id.includes('node_modules')) return undefined;

  // React'i motion'dan ÖNCE ayır — aksi halde index.js motion chunk'ına bağımlı kalır (~70 KiB unused JS)
  if (id.includes('react-dom') || id.includes('/react/') || id.includes('\\react\\')) {
    return 'vendor-react';
  }
  if (id.includes('motion')) {
    return 'motion';
  }
  if (id.includes('lucide-react')) {
    return 'vendor-lucide';
  }
  if (id.includes('@google/genai')) {
    return 'vendor-genai';
  }
  return undefined;
}

export default defineConfig(() => {
  return {
    plugins: [react(), tailwindcss(), asyncCssPlugin()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      },
    },
    build: {
      modulePreload: {
        polyfill: false,
        resolveDependencies(_filename, deps) {
          return deps.filter((dep) => !DEFERRED_CHUNK_PATTERN.test(dep));
        },
      },
      rollupOptions: {
        input: {
          main: path.resolve(__dirname, 'index.html'),
          teknikDokuman: path.resolve(__dirname, 'teknik-dokuman/index.html'),
        },
        output: {
          manualChunks(id) {
            return manualVendorChunk(id);
          },
        },
      },
    },
    server: {
      hmr: process.env.DISABLE_HMR !== 'true',
      watch: process.env.DISABLE_HMR === 'true' ? null : {},
      proxy: {
        '/api': {
          target: process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8787',
          changeOrigin: true,
          secure: false,
        },
      },
    },
  };
});
