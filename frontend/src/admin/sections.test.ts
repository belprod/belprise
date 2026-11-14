import { describe, expect, it } from 'vitest';
import { SECTIONS, parseAdminRoute } from './sections';

describe('parseAdminRoute', () => {
  it('maps /admin to the dashboard', () => {
    expect(parseAdminRoute('/admin')).toEqual({ section: SECTIONS[0], view: 'index' });
  });

  it('maps every section path back to its index', () => {
    for (const section of SECTIONS) {
      expect(parseAdminRoute(section.path)).toEqual({ section, view: 'index' });
    }
  });

  it('recognises creation and detail pages of resources', () => {
    expect(parseAdminRoute('/admin/clients/new')).toMatchObject({ view: 'new', section: { id: 'clients' } });
    expect(parseAdminRoute('/admin/videos/42')).toMatchObject({ view: 'detail', id: 42, section: { id: 'videos' } });
    expect(parseAdminRoute('/admin/projects/7/')).toMatchObject({ view: 'detail', id: 7 });
  });

  it('rejects sub-pages of non-resource sections and malformed ids', () => {
    expect(parseAdminRoute('/admin/settings/new')).toBeNull();
    expect(parseAdminRoute('/admin/clients/0')).toBeNull();
    expect(parseAdminRoute('/admin/clients/abc')).toBeNull();
    expect(parseAdminRoute('/admin/clients/1/2')).toBeNull();
  });

  it('returns null for unknown paths', () => {
    expect(parseAdminRoute('/admin/unknown')).toBeNull();
    expect(parseAdminRoute('/administration')).toBeNull();
    expect(parseAdminRoute('/')).toBeNull();
  });
});
