/** Public demo (CAHIER_DES_CHARGES §5.16): shared accounts, reset every 6 hours, no file sent. */
export interface DemoAccount {
  role: 'admin' | 'editor' | 'viewer';
  name: string;
  email: string;
  password: string;
}

export interface DemoState {
  /** Next reset (ISO, UTC) */
  resetAt: string;
  accounts: DemoAccount[];
  /** Credit of the films (CC BY) */
  credit: string;
}

/** "2 h 14" (non-breaking spaces), "14 min", "moins d’une minute": time left before the reset. */
export function untilReset(resetAt: string, now: number = Date.now()): string {
  const minutes = Math.floor((Date.parse(resetAt) - now) / 60_000);
  if (minutes < 1) return 'moins d’une minute';
  if (minutes < 60) return `${minutes}\u00a0min`;
  return `${Math.floor(minutes / 60)}\u00a0h\u00a0${String(minutes % 60).padStart(2, '0')}`;
}
