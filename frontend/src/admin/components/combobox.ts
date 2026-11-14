import { normalize } from '../../lib/format';

export interface ComboOption {
  value: string;
  label: string;
  /** Heading the option is listed under (projects by client, for instance) */
  group?: string;
}

/**
 * Options matching what was typed: every word must appear in the label or
 * the group, accents and case ignored ("aurore film" finds "Film manifeste"
 * under "Maison Aurore"). An empty query keeps everything.
 */
export function filterOptions(options: ComboOption[], query: string): ComboOption[] {
  const words = normalize(query).split(/\s+/).filter(Boolean);
  if (words.length === 0) return options;
  return options.filter((option) => {
    const haystack = normalize(`${option.label} ${option.group ?? ''}`);
    return words.every((word) => haystack.includes(word));
  });
}

/**
 * Options without a group first (like "Client : tous"), then grouped ones,
 * groups in alphabetical order; the order inside a group is kept.
 */
export function groupOptions(options: ComboOption[]): ComboOption[] {
  if (!options.some((o) => o.group)) return options;
  const loose = options.filter((o) => !o.group);
  const grouped = options
    .map((option, index) => ({ option, index }))
    .filter(({ option }) => option.group)
    .sort((a, b) => a.option.group!.localeCompare(b.option.group!, 'fr') || a.index - b.index)
    .map(({ option }) => option);
  return [...loose, ...grouped];
}
