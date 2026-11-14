import { api } from '../lib/api';

export type Role = 'admin' | 'editor' | 'viewer';

export const ROLE_LABELS: Record<Role, string> = { admin: 'Administrateur', editor: 'Éditeur', viewer: 'Lecteur' };

export interface SessionUser {
  id: number;
  name: string;
  email: string;
  role: Role;
}

export interface StorageUsage {
  /** Bytes; null: no limit (3.0) */
  quota: number | null;
  /** "250 Go", or "Sans limite" */
  quotaLabel: string;
  used: number;
  reserved: number;
  /** (used + reserved) / quota */
  ratio: number;
}

/** Downgrade awaiting its purge, deletion awaiting its date (§5.10, §5.12). */
export interface Lifecycle {
  purgeAt: string | null;
  /** Quota before it was lowered ("1 To", "Sans limite") */
  downgradedFrom: string | null;
  deletionAt: string | null;
  /** Who asked: only the operator cancels the operator's request */
  deletionBy: 'admin' | 'operator' | null;
  deletionRequestedBy: string | null;
}

/** The organization and its back-office identity (§5.7). */
export interface SessionOrganization {
  id: number;
  name: string;
  logoUrl: string | null;
  accentColor: string;
  theme: 'dark' | 'light';
  /** Two-factor authentication required for the whole team (§5.7) */
  totpRequired?: boolean;
}

export interface AdminSession {
  setupRequired: boolean;
  authenticated: boolean;
  user: SessionUser | null;
  organization: SessionOrganization | null;
  /** CSS variables of the organization's identity (computed by the server) */
  theme?: Record<string, string> | null;
  storage?: StorageUsage | null;
  lifecycle?: Lifecycle | null;
  /** Password accepted, authenticator code awaited */
  totpRequired?: boolean;
  totpEnabled?: boolean;
  /** Required by the organization and not set up yet: only the set-up is open */
  totpSetupRequired?: boolean;
  /** Unused two-factor recovery codes */
  recoveryCodesLeft?: number;
  /** Where the playback versions are encoded (§5.7 bis) */
  encoding?: 'browser' | 'server' | null;
  csrf: string;
}

export const SIGNED_OUT: AdminSession = {
  setupRequired: false,
  authenticated: false,
  user: null,
  organization: null,
  totpRequired: false,
  csrf: '',
};

/** What a role may do (same rules as Belprise\Security\Roles on the server). */
export function can(session: AdminSession, action: 'edit' | 'delete' | 'team'): boolean {
  const role = session.user?.role;
  if (action === 'edit') return role === 'admin' || role === 'editor';
  return role === 'admin';
}

export interface SetupData {
  organization: string;
  name: string;
  email: string;
  password: string;
  confirmation: string;
}

export function setupAccount(data: SetupData): Promise<AdminSession> {
  return api<AdminSession>('POST', '/api/admin/setup', data);
}

export function login(email: string, password: string): Promise<AdminSession> {
  return api<AdminSession>('POST', '/api/admin/login', { email, password });
}

export function logout(): Promise<AdminSession> {
  return api<AdminSession>('POST', '/api/admin/logout');
}

export function loginCode(code: string): Promise<AdminSession> {
  return api<AdminSession>('POST', '/api/admin/login/totp', { code });
}
