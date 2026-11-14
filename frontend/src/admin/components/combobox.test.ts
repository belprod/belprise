import { describe, expect, it } from 'vitest';
import { filterOptions, groupOptions, type ComboOption } from './combobox';

const options: ComboOption[] = [
  { value: '', label: 'Projet : tous' },
  { value: '1', label: 'Film manifeste', group: 'Maison Aurore' },
  { value: '2', label: 'Captation plénière', group: 'Fondation Hélios' },
  { value: '3', label: 'Module de formation', group: 'Fondation Hélios' },
];

describe('filterOptions', () => {
  it('keeps everything for an empty query', () => {
    expect(filterOptions(options, '  ')).toEqual(options);
  });

  it('ignores accents and case', () => {
    expect(filterOptions(options, 'PLENIERE').map((o) => o.value)).toEqual(['2']);
  });

  it('needs every word, in the label or the group', () => {
    expect(filterOptions(options, 'helios module').map((o) => o.value)).toEqual(['3']);
    expect(filterOptions(options, 'aurore film').map((o) => o.value)).toEqual(['1']);
    expect(filterOptions(options, 'aurore module')).toEqual([]);
  });
});

describe('groupOptions', () => {
  it('puts loose options first, then groups alphabetically, keeping the order inside a group', () => {
    const mixed: ComboOption[] = [
      { value: 'a', label: 'Z', group: 'Maison Aurore' },
      { value: 'b', label: 'B', group: 'Fondation Hélios' },
      { value: '', label: 'Tous' },
      { value: 'c', label: 'A', group: 'Fondation Hélios' },
    ];
    expect(groupOptions(mixed).map((o) => o.value)).toEqual(['', 'b', 'c', 'a']);
    expect(groupOptions(options.slice(0, 1))).toEqual(options.slice(0, 1));
  });
});
