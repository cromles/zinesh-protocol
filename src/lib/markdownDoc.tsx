import React from 'react';

export type TocItem = { id: string; text: string; level: 2 | 3 };

function slugify(text: string): string {
  return text
    .trim()
    .toLowerCase()
    .replace(/ı/g, 'i')
    .replace(/ğ/g, 'g')
    .replace(/ü/g, 'u')
    .replace(/ş/g, 's')
    .replace(/ö/g, 'o')
    .replace(/ç/g, 'c')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

export function extractToc(markdown: string): TocItem[] {
  const items: TocItem[] = [];
  for (const line of markdown.split(/\r?\n/)) {
    const m = /^(#{2,3})\s+(.+)$/.exec(line.trim());
    if (!m) continue;
    const level = m[1].length as 2 | 3;
    const text = m[2].replace(/\*\*/g, '').trim();
    items.push({ id: slugify(text), text, level });
  }
  return items;
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function inlineFormat(text: string): string {
  let out = escapeHtml(text);
  out = out.replace(/`([^`]+)`/g, '<code class="md-inline-code">$1</code>');
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong class="text-zinc-100 font-semibold">$1</strong>');
  out = out.replace(/\*([^*]+)\*/g, '<em class="text-zinc-300 not-italic">$1</em>');
  return out;
}

function parseTableRow(line: string): string[] {
  return line
    .trim()
    .replace(/^\|/, '')
    .replace(/\|$/, '')
    .split('|')
    .map((c) => c.trim());
}

const styles = {
  h1: 'font-display text-4xl sm:text-5xl font-black text-white tracking-tight leading-[1.08] mb-3 scroll-mt-28',
  h2: 'font-display text-2xl sm:text-[1.65rem] font-bold text-white tracking-tight mt-16 mb-5 pt-10 border-t border-white/[0.07] scroll-mt-28 first:mt-0 first:pt-0 first:border-t-0',
  h3: 'font-sans text-lg font-semibold text-zinc-100 mt-8 mb-3 scroll-mt-28',
  p: 'my-4 text-[0.98rem] sm:text-[1.02rem] text-zinc-400 leading-[1.85] font-sans font-medium',
  hr: 'my-14 border-0 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent',
  blockquote:
    'my-8 rounded-2xl border border-purple-500/20 bg-gradient-to-br from-purple-500/[0.08] to-transparent px-5 py-4 sm:px-6 sm:py-5 text-[1.02rem] text-zinc-200 leading-relaxed font-sans shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]',
  ul: 'my-5 space-y-2.5 pl-0 list-none',
  ulItem:
    'relative pl-5 text-zinc-400 text-[0.98rem] leading-relaxed font-sans before:absolute before:left-0 before:top-[0.55em] before:h-1.5 before:w-1.5 before:rounded-full before:bg-amber-400/80',
  ol: 'my-5 space-y-3 pl-0 list-none counter-reset-md-ol',
  olItem:
    'relative pl-8 text-zinc-400 text-[0.98rem] leading-relaxed font-sans [counter-increment:md-ol] before:absolute before:left-0 before:top-0 before:flex before:h-6 before:w-6 before:items-center before:justify-center before:rounded-full before:bg-white/[0.06] before:font-mono before:text-[10px] before:font-bold before:text-amber-300/90 before:content-[counter(md-ol)]',
  pre: 'my-6 overflow-x-auto rounded-2xl border border-white/[0.08] bg-[#050508] p-5 text-[0.82rem] font-mono text-zinc-300 leading-relaxed shadow-inner',
  tableWrap: 'my-8 overflow-x-auto rounded-2xl border border-white/[0.08] bg-white/[0.02]',
  th: 'px-4 py-3 text-left font-mono text-[10px] uppercase tracking-widest text-amber-300/90 font-bold',
  td: 'px-4 py-3 text-sm text-zinc-400 font-sans border-t border-white/[0.05]',
};

export function MarkdownDocument({ markdown }: { markdown: string }) {
  const lines = markdown.split(/\r?\n/);
  const nodes: React.ReactNode[] = [];
  let i = 0;
  let key = 0;

  while (i < lines.length) {
    const line = lines[i];
    const trimmed = line.trim();

    if (trimmed === '') {
      i += 1;
      continue;
    }

    if (trimmed === '---') {
      nodes.push(<hr key={key++} className={styles.hr} />);
      i += 1;
      continue;
    }

    const heading = /^(#{1,3})\s+(.+)$/.exec(trimmed);
    if (heading) {
      const level = heading[1].length;
      const text = heading[2].replace(/\*\*/g, '');
      const id = slugify(text);
      const content = inlineFormat(text);
      if (level === 1) {
        nodes.push(
          <h1
            key={key++}
            id={id}
            className={styles.h1}
            dangerouslySetInnerHTML={{ __html: content }}
          />,
        );
      } else if (level === 2) {
        nodes.push(
          <h2
            key={key++}
            id={id}
            className={styles.h2}
            dangerouslySetInnerHTML={{ __html: content }}
          />,
        );
      } else {
        nodes.push(
          <h3
            key={key++}
            id={id}
            className={styles.h3}
            dangerouslySetInnerHTML={{ __html: content }}
          />,
        );
      }
      i += 1;
      continue;
    }

    if (trimmed.startsWith('```')) {
      const lang = trimmed.slice(3).trim();
      const codeLines: string[] = [];
      i += 1;
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        codeLines.push(lines[i]);
        i += 1;
      }
      i += 1;
      nodes.push(
        <pre key={key++} className={styles.pre} data-lang={lang || undefined}>
          <code>{codeLines.join('\n')}</code>
        </pre>,
      );
      continue;
    }

    if (trimmed.startsWith('|') && i + 1 < lines.length && /^\|?[\s:-]+\|/.test(lines[i + 1])) {
      const header = parseTableRow(trimmed);
      i += 2;
      const rows: string[][] = [];
      while (i < lines.length && lines[i].trim().startsWith('|')) {
        rows.push(parseTableRow(lines[i]));
        i += 1;
      }
      nodes.push(
        <div key={key++} className={styles.tableWrap}>
          <table className="w-full min-w-[280px] border-collapse">
            <thead className="bg-white/[0.03]">
              <tr>
                {header.map((cell) => (
                  <th key={cell} className={styles.th}>
                    {cell}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, ri) => (
                <tr key={ri} className="hover:bg-white/[0.02] transition-colors">
                  {row.map((cell, ci) => (
                    <td key={ci} className={styles.td}>
                      <span dangerouslySetInnerHTML={{ __html: inlineFormat(cell) }} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>,
      );
      continue;
    }

    if (trimmed.startsWith('> ')) {
      const quoteLines: string[] = [];
      while (i < lines.length && lines[i].trim().startsWith('> ')) {
        quoteLines.push(lines[i].trim().slice(2));
        i += 1;
      }
      nodes.push(
        <blockquote
          key={key++}
          className={styles.blockquote}
          dangerouslySetInnerHTML={{ __html: inlineFormat(quoteLines.join(' ')) }}
        />,
      );
      continue;
    }

    if (/^\d+\.\s/.test(trimmed)) {
      const items: string[] = [];
      while (i < lines.length && /^\d+\.\s/.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^\d+\.\s+/, ''));
        i += 1;
      }
      nodes.push(
        <ol key={key++} className={styles.ol}>
          {items.map((item) => (
            <li
              key={item}
              className={styles.olItem}
              dangerouslySetInnerHTML={{ __html: inlineFormat(item) }}
            />
          ))}
        </ol>,
      );
      continue;
    }

    if (trimmed.startsWith('- ')) {
      const items: string[] = [];
      while (i < lines.length && lines[i].trim().startsWith('- ')) {
        items.push(lines[i].trim().slice(2));
        i += 1;
      }
      nodes.push(
        <ul key={key++} className={styles.ul}>
          {items.map((item) => (
            <li
              key={item}
              className={styles.ulItem}
              dangerouslySetInnerHTML={{ __html: inlineFormat(item) }}
            />
          ))}
        </ul>,
      );
      continue;
    }

    const para: string[] = [trimmed];
    i += 1;
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !/^(#{1,3}|```|\||>|---|- |\d+\.\s)/.test(lines[i].trim())
    ) {
      para.push(lines[i].trim());
      i += 1;
    }
    nodes.push(
      <p
        key={key++}
        className={styles.p}
        dangerouslySetInnerHTML={{ __html: inlineFormat(para.join(' ')) }}
      />,
    );
  }

  return (
    <div className="markdown-doc [&_.md-inline-code]:rounded-md [&_.md-inline-code]:bg-white/[0.06] [&_.md-inline-code]:border [&_.md-inline-code]:border-white/[0.08] [&_.md-inline-code]:px-1.5 [&_.md-inline-code]:py-0.5 [&_.md-inline-code]:font-mono [&_.md-inline-code]:text-[0.88em] [&_.md-inline-code]:text-amber-200/90">
      {nodes}
    </div>
  );
}
