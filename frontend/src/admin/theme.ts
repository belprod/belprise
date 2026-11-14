/**
 * The organization's colours (§5.7). The server writes them into the page
 * before the first paint; after signing in, switching organization or
 * saving the settings, they are applied here (CSSOM: allowed by the CSP).
 */
interface StyleTarget {
  style: Pick<CSSStyleDeclaration, 'setProperty' | 'removeProperty'>;
}

let applied: string[] = [];

export function applyTheme(
  vars: Record<string, string> | null | undefined,
  root: StyleTarget = document.documentElement,
  meta: { content: string } | null = typeof document === 'undefined' ? null : document.querySelector<HTMLMetaElement>('meta[name="color-scheme"]'),
): void {
  for (const name of applied) {
    root.style.removeProperty(name);
  }
  applied = [];
  for (const [name, value] of Object.entries(vars ?? {})) {
    if (/^(--[a-z0-9-]+|color-scheme)$/.test(name) && /^[#a-z0-9%]+$/i.test(value)) {
      root.style.setProperty(name, value);
      applied.push(name);
    }
  }
  if (meta) {
    meta.content = vars?.['color-scheme'] ?? 'dark';
  }
}
