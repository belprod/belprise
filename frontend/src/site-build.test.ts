import { describe, expect, it } from 'vitest';
import { latestDownload, markdown, releases, slug } from '../../vite.site.config';

describe('site of the project (vite.site.config.ts)', () => {
  const changelog = '# Versions\n\n## 3.1.0 — en préparation\n\n- a\n\n## 3.0.1 — 1 décembre 2026\n\n- b\n\n## 3.0.0 — 20 novembre 2026\n\n## 2.1.1\n';

  it('reads the versions of CHANGELOG.md, newest first', () => {
    expect(releases(changelog)).toEqual([
      { version: '3.1.0', date: null, released: false },
      { version: '3.0.1', date: '1 décembre 2026', released: true },
      { version: '3.0.0', date: '20 novembre 2026', released: true },
      { version: '2.1.1', date: null, released: true },
    ]);
  });

  it('offers the latest published 3.x only (2.x was never public, "en préparation" is not out)', () => {
    expect(latestDownload(releases(changelog))?.version).toBe('3.0.1');
    expect(latestDownload(releases('## 3.0.0 — en préparation\n\n## 2.1.1\n'))).toBeNull();
  });

  it('makes GitHub-like anchors, so the links of the guides keep working', () => {
    expect(slug('1. Hébergement mutualisé (cPanel)')).toBe('1-hébergement-mutualisé-cpanel');
    expect(slug('2. Docker (VPS, NAS)')).toBe('2-docker-vps-nas');
    expect(slug('4.3 Comptes : en cas de perte d’accès')).toBe('43-comptes--en-cas-de-perte-daccès');
  });

  it('turns repository files into GitHub links, keeps anchors, adds no referrer to external links', () => {
    const toc: { level: number; id: string; text: string }[] = [];
    const html = markdown('## 1. Installer\n\n[licence](LICENSE), [§1](#1-installer), [site](https://belprod.fr)\n', toc);
    expect(html).toContain('<h2 id="1-installer">1. Installer</h2>');
    expect(html).toContain('href="https://github.com/belprod/belprise/blob/main/LICENSE"');
    expect(html).toContain('href="#1-installer"');
    expect(html).toContain('href="https://belprod.fr" rel="noopener noreferrer"');
    expect(toc).toEqual([{ level: 2, id: '1-installer', text: '1. Installer' }]);
  });
});
