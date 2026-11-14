/** JSON client for /api/… with the CSRF token on state-changing requests. */

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly data: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

let csrfToken = '';
let extraHeaders: Record<string, string> = {};
let onUnauthorized: (() => void) | null = null;

export function setCsrfToken(token: string): void {
  csrfToken = token;
}

/** Headers added to every request (the client pages' author token). */
export function setExtraHeaders(headers: Record<string, string>): void {
  extraHeaders = headers;
}

export function getCsrfToken(): string {
  return csrfToken;
}

/** Lets non-fetch requests (XMLHttpRequest uploads) report an expired session. */
export function notifyUnauthorized(): void {
  onUnauthorized?.();
}

/** Called when the admin session has expired (HTTP 401). */
export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler;
}

const FALLBACK_ERROR = 'Une erreur est survenue. Réessayez.';
const NETWORK_ERROR = 'Connexion impossible. Vérifiez votre réseau.';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export async function api<T>(method: Method, path: string, body?: unknown, fetcher: typeof fetch = fetch): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  return send<T>(method, path, headers, body === undefined ? undefined : JSON.stringify(body), fetcher);
}

/** multipart/form-data upload (the browser sets the boundary). */
export async function upload<T>(path: string, form: FormData, fetcher: typeof fetch = fetch): Promise<T> {
  return send<T>('POST', path, { Accept: 'application/json' }, form, fetcher);
}

async function send<T>(
  method: Method,
  path: string,
  headers: Record<string, string>,
  body: BodyInit | undefined,
  fetcher: typeof fetch,
): Promise<T> {
  Object.assign(headers, extraHeaders);
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = csrfToken;
  }

  let response: Response;
  try {
    response = await fetcher(path, { method, headers, body, credentials: 'same-origin' });
  } catch {
    throw new ApiError(NETWORK_ERROR, 0);
  }

  const isJson = response.headers.get('Content-Type')?.includes('application/json') ?? false;
  const data: unknown = isJson ? await response.json().catch(() => null) : null;

  if (response.status === 401) {
    onUnauthorized?.();
  }
  // The organization now asks for two-factor authentication: reloading shows its set-up screen (§5.7).
  if (response.status === 403 && isRecord(data) && data.totpSetupRequired === true) {
    window.location.reload();
  }
  if (!response.ok) {
    const fields = isRecord(data) ? data : {};
    const message = typeof fields.error === 'string' ? fields.error : FALLBACK_ERROR;
    throw new ApiError(message, response.status, fields);
  }
  if (isRecord(data) && typeof data.csrf === 'string') {
    setCsrfToken(data.csrf);
  }
  return data as T;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
