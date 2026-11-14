import type { AdminSession } from './session';

/** Signed-in session, shared by the admin pages (role, organization). */
export const current = $state<{ session: AdminSession | null }>({ session: null });
