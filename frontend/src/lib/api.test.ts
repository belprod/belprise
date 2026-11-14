import { describe, expect, it, vi } from 'vitest';
import { api, ApiError, setCsrfToken } from './api';

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}

describe('api', () => {
  it('sends the CSRF token on POST and keeps the rotated one', async () => {
    setCsrfToken('first');
    const fetcher = vi.fn<typeof fetch>(async () => jsonResponse({ csrf: 'second' }));

    await api('POST', '/api/admin/login', { username: 'a' }, fetcher);
    await api('POST', '/api/admin/logout', undefined, fetcher);

    const firstHeaders = fetcher.mock.calls[0]?.[1]?.headers as Record<string, string>;
    const secondHeaders = fetcher.mock.calls[1]?.[1]?.headers as Record<string, string>;
    expect(firstHeaders['X-CSRF-Token']).toBe('first');
    expect(secondHeaders['X-CSRF-Token']).toBe('second');
  });

  it('does not send the CSRF token on GET', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => jsonResponse({}));
    await api('GET', '/api/admin/session', undefined, fetcher);
    const headers = fetcher.mock.calls[0]?.[1]?.headers as Record<string, string>;
    expect(headers['X-CSRF-Token']).toBeUndefined();
  });

  it('turns an error response into an ApiError with the server message', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => jsonResponse({ error: 'Trop de tentatives.', retryAfter: 4 }, 429));
    const error = await api('POST', '/api/admin/login', {}, fetcher).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(429);
    expect((error as ApiError).message).toBe('Trop de tentatives.');
    expect((error as ApiError).data.retryAfter).toBe(4);
  });

  it('reports network failures in French', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => {
      throw new TypeError('Failed to fetch');
    });
    await expect(api('GET', '/api/admin/session', undefined, fetcher)).rejects.toThrow('Connexion impossible');
  });
});
