/**
 * Site of the project, belprise.fr (CAHIER_DES_CHARGES §5.15): static pages,
 * no JavaScript, same fonts and colours as the application. Built into
 * site/dist/ (indexable, unlike the application).
 *
 * At build time: the shared header and footer, the "Versions" page from
 * CHANGELOG.md, the "Documentation" page from DEPLOIEMENT.md, the download
 * page of the latest version, and versions.json (read once a day by the
 * installations, decision D8).
 */
import { defineConfig, type Plugin } from 'vite';
import { Marked, type Tokens } from 'marked';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = path.dirname(fileURLToPath(import.meta.url));
const siteDir = path.join(rootDir, 'site');
const REPOSITORY = 'https://github.com/belprod/belprise';

export interface Release {
  version: string;
  /** "1 décembre 2026", or null */
  date: string | null;
  /** false while the section says "en préparation" */
  released: boolean;
}

/** Versions of CHANGELOG.md, newest first: "## 3.0.1 — 1 décembre 2026", "## 3.0.0 — en préparation". */
export function releases(changelog: string): Release[] {
  return [...changelog.matchAll(/^## (\d+\.\d+\.\d+)(?: — (.+))?$/gm)].map((m) => ({
    version: m[1]!,
    date: m[2] && !/préparation/i.test(m[2]) ? m[2].trim() : null,
    released: !m[2] || !/préparation/i.test(m[2]),
  }));
}

/** The latest version published on GitHub (3.x and later: older ones were never public). */
export function latestDownload(list: Release[]): Release | null {
  return list.find((r) => r.released && Number(r.version.split('.')[0]) >= 3) ?? null;
}

/** GitHub-like heading anchors, so the links between sections of the guides keep working. */
export function slug(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/<[^>]+>/g, '')
    .replace(/[^\p{L}\p{N}\s-]/gu, '')
    .replace(/\s/g, '-');
}

/** Markdown to HTML: anchors on headings, repository files linked on GitHub, external links without referrer. */
export function markdown(source: string, toc?: { level: number; id: string; text: string }[]): string {
  const marked = new Marked({
    gfm: true,
    renderer: {
      heading(this: { parser: { parseInline(tokens: Tokens.Generic[]): string } }, { tokens, depth, text }: Tokens.Heading) {
        const id = slug(text);
        if (toc && depth <= 3) toc.push({ level: depth, id, text: text.replace(/\*\*/g, '') });
        return `<h${depth} id="${id}">${this.parser.parseInline(tokens)}</h${depth}>\n`;
      },
      link(this: { parser: { parseInline(tokens: Tokens.Generic[]): string } }, { href, tokens }: Tokens.Link) {
        let target = href;
        if (!/^(https?:|mailto:|#)/.test(href)) {
          target = `${REPOSITORY}/blob/main/${href.replace(/^\.\//, '')}`;
        }
        const external = /^https?:/.test(target);
        return `<a href="${target}"${external ? ' rel="noopener noreferrer"' : ''}>${this.parser.parseInline(tokens)}</a>`;
      },
    },
  });

  return marked.parse(source, { async: false }) as string;
}

function belprisePages(): Plugin {
  const read = (file: string): string => readFileSync(path.join(rootDir, file), 'utf8');
  const partial = (name: string): string => readFileSync(path.join(siteDir, 'partials', `${name}.html`), 'utf8');

  return {
    name: 'belprise-pages',
    transformIndexHtml(html) {
      const changelog = read('CHANGELOG.md');
      const list = releases(changelog);
      const latest = latestDownload(list);
      const toc: { level: number; id: string; text: string }[] = [];
      // The guide without its own title (the page has one).
      const guide = markdown(read('DEPLOIEMENT.md').replace(/^# .+\n/, ''), toc);
      const tocHtml = toc
        .filter((t) => t.level === 2)
        .map((t) => `<li><a href="#${t.id}">${t.text}</a></li>`)
        .join('');
      const download = latest
        ? `${REPOSITORY}/releases/download/v${latest.version}/belprise-${latest.version}.zip`
        : `${REPOSITORY}/releases`;

      return html
        .replace('<!--#header-->', partial('header'))
        .replace('<!--#footer-->', partial('footer'))
        .replace('<!--#changelog-->', markdown(changelog.replace(/^# .+\n/, '')))
        .replace('<!--#guide-->', guide)
        .replace('<!--#guide-toc-->', `<ul>${tocHtml}</ul>`)
        .replaceAll('%LATEST_VERSION%', latest?.version ?? '')
        .replaceAll('%LATEST_DATE%', latest?.date ?? '')
        .replaceAll('%DOWNLOAD_URL%', download)
        .replaceAll('%RELEASES_URL%', `${REPOSITORY}/releases`)
        .replaceAll('%REPOSITORY%', REPOSITORY)
        .replace(/<!--#if-latest-->([\s\S]*?)<!--#else-->([\s\S]*?)<!--#end-->/g, (_, yes: string, no: string) => (latest ? yes : no));
    },
    generateBundle() {
      const latest = latestDownload(releases(read('CHANGELOG.md')));
      this.emitFile({
        type: 'asset',
        fileName: 'versions.json',
        source: JSON.stringify({ version: latest?.version ?? null, url: 'https://belprise.fr/versions/' }) + '\n',
      });
    },
  };
}

export default defineConfig({
  root: siteDir,
  base: '/',
  publicDir: path.join(siteDir, 'static'),
  plugins: [belprisePages()],
  build: {
    outDir: path.join(siteDir, 'dist'),
    emptyOutDir: true,
    assetsDir: 'assets',
    assetsInlineLimit: 0,
    rollupOptions: {
      input: {
        index: path.join(siteDir, 'index.html'),
        download: path.join(siteDir, 'telecharger', 'index.html'),
        versions: path.join(siteDir, 'versions', 'index.html'),
        guide: path.join(siteDir, 'documentation', 'index.html'),
        legal: path.join(siteDir, 'mentions-legales.html'),
        privacy: path.join(siteDir, 'confidentialite.html'),
        notFound: path.join(siteDir, '404.html'),
      },
    },
  },
  server: { port: 5174, strictPort: true },
});
