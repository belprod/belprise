/** Display helpers (French). */

const dateFormat = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
const longDateFormat = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

export function formatDate(iso: string): string {
  return dateFormat.format(new Date(iso));
}

/** Instant → "31 décembre 2026" (Paris). */
export function formatLongDate(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Paris' }).format(new Date(iso));
}

/** "2026-12-31" → "31 décembre 2026" (calendar date, no time zone shift). */
export function formatDay(day: string): string {
  const [year, month, date] = day.split('-').map(Number) as [number, number, number];
  return longDateFormat.format(new Date(year, month - 1, date));
}

export function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
  return `${count} ${count > 1 ? pluralForm : singular}`;
}

/** Case- and accent-insensitive search. */
export function normalize(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

const decimal1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1, minimumFractionDigits: 1 });

/** "2,4 Go", "850,0 Mo" (decimal units, like Finder). */
export function formatBytes(bytes: number): string {
  if (bytes >= 1e12) return `${decimal1.format(bytes / 1e12)} To`;
  if (bytes >= 1e9) return `${decimal1.format(bytes / 1e9)} Go`;
  if (bytes >= 1e6) return `${decimal1.format(bytes / 1e6)} Mo`;
  if (bytes <= 0) return '0 Ko';
  return `${Math.max(1, Math.round(bytes / 1e3))} Ko`;
}

/** "12,3 Mo/s" */
export function formatSpeed(bytesPerSecond: number): string {
  return `${formatBytes(bytesPerSecond)}/s`;
}

/** "1 h 05 min", "4 min 20 s", "35 s" */
export function formatSeconds(total: number): string {
  const seconds = Math.max(0, Math.round(total));
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  if (h > 0) return `${h} h ${String(m).padStart(2, '0')} min`;
  if (m > 0) return `${m} min ${String(s).padStart(2, '0')} s`;
  return `${s} s`;
}

/** "à l’instant", "il y a 5 min", "il y a 2 h", "hier", then the date. */
export function formatRelative(iso: string, now: Date = new Date()): string {
  const date = new Date(iso);
  const minutes = Math.floor((now.getTime() - date.getTime()) / 60000);
  if (minutes < 1) return 'à l’instant';
  if (minutes < 60) return `il y a ${minutes} min`;
  if (minutes < 24 * 60 && date.getDate() === now.getDate()) return `il y a ${Math.floor(minutes / 60)} h`;
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (date.toDateString() === yesterday.toDateString()) return 'hier';
  return formatDate(iso);
}
