import { describe, expect, it } from 'vitest';
import { applyTheme } from './theme';

function fakeRoot() {
  const props = new Map<string, string>();
  return {
    props,
    style: {
      setProperty: (name: string, value: string | null) => void props.set(name, String(value)),
      removeProperty: (name: string) => (props.delete(name), ''),
    },
  };
}

describe('applyTheme', () => {
  it('sets the variables, then removes them when the theme goes', () => {
    const root = fakeRoot();
    const meta = { content: 'dark' };
    applyTheme({ '--bg': '#f4f4f5', '--accent': '#1f6feb', 'color-scheme': 'light' }, root, meta);
    expect(Object.fromEntries(root.props)).toEqual({ '--bg': '#f4f4f5', '--accent': '#1f6feb', 'color-scheme': 'light' });
    expect(meta.content).toBe('light');

    applyTheme({ '--accent': '#2ee59d' }, root, meta);
    expect(Object.fromEntries(root.props)).toEqual({ '--accent': '#2ee59d' });
    expect(meta.content).toBe('dark');

    applyTheme(null, root, meta);
    expect(root.props.size).toBe(0);
  });

  it('ignores anything that is not a colour variable', () => {
    const root = fakeRoot();
    applyTheme({ '--bg': 'url(https://evil.example/x)', background: '#000', '--status-ink': '42%' }, root, null);
    expect(Object.fromEntries(root.props)).toEqual({ '--status-ink': '42%' });
  });
});
