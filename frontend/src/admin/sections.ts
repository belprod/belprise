/** Admin sections and routes (keep in sync with Belprise\Controller\AdminController). */

export const ADMIN_BASE = '/admin';

export type SectionId = 'dashboard' | 'revisions' | 'clients' | 'projects' | 'videos' | 'collections' | 'team' | 'settings';

export interface Section {
  id: SectionId;
  path: string;
  /** Menu label */
  label: string;
  /** Monospace label above the title */
  eyebrow: string;
  title: string;
  /** Has /new and /{id} pages */
  resource: boolean;
  /** Shown to administrators only */
  adminOnly?: boolean;
}

export const SECTIONS: readonly Section[] = [
  { id: 'dashboard', path: ADMIN_BASE, label: 'Tableau de bord', eyebrow: 'Organisation', title: 'Tableau de bord', resource: false },
  { id: 'revisions', path: `${ADMIN_BASE}/revisions`, label: 'Révisions', eyebrow: 'Révisions', title: 'Révisions des clients', resource: false },
  { id: 'clients', path: `${ADMIN_BASE}/clients`, label: 'Clients', eyebrow: 'Clients', title: 'Clients', resource: true },
  { id: 'projects', path: `${ADMIN_BASE}/projects`, label: 'Projets', eyebrow: 'Projets', title: 'Projets', resource: true },
  { id: 'videos', path: `${ADMIN_BASE}/videos`, label: 'Vidéos', eyebrow: 'Vidéos', title: 'Vidéos', resource: true },
  { id: 'collections', path: `${ADMIN_BASE}/collections`, label: 'Collections', eyebrow: 'Prospects', title: 'Collections', resource: true },
  { id: 'team', path: `${ADMIN_BASE}/team`, label: 'Équipe', eyebrow: 'Organisation', title: 'Équipe', resource: false, adminOnly: true },
  { id: 'settings', path: `${ADMIN_BASE}/settings`, label: 'Mon compte', eyebrow: 'Réglages', title: 'Mon compte', resource: false },
];

export type AdminRoute =
  | { section: Section; view: 'index' }
  | { section: Section; view: 'new' }
  | { section: Section; view: 'detail'; id: number };

/** Route for a URL path, ignoring a trailing slash; null if unknown. */
export function parseAdminRoute(pathname: string): AdminRoute | null {
  const normalized = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  for (const section of SECTIONS) {
    if (normalized === section.path) {
      return { section, view: 'index' };
    }
    if (!section.resource || !normalized.startsWith(`${section.path}/`)) {
      continue;
    }
    const rest = normalized.slice(section.path.length + 1);
    if (rest === 'new') {
      return { section, view: 'new' };
    }
    if (/^[1-9][0-9]{0,9}$/.test(rest)) {
      return { section, view: 'detail', id: Number(rest) };
    }
  }
  return null;
}

export function sectionPath(id: SectionId): string {
  return SECTIONS.find((section) => section.id === id)!.path;
}
