/**
 * The client's identity, kept in this browser only (§2: no client account):
 * the name typed at the first comment, and a random token that lets the
 * author edit or delete their own comments.
 */
const KEY = 'belprise.author';

export interface Author {
  name: string;
  token: string;
}

function randomToken(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(32));
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function read(): Partial<Author> {
  try {
    const value = JSON.parse(localStorage.getItem(KEY) ?? '{}') as Partial<Author>;
    return typeof value === 'object' && value !== null ? value : {};
  } catch {
    return {};
  }
}

function write(author: Author): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(author));
  } catch {
    // storage unavailable: the identity lasts for this page only
  }
}

let current: Author | null = null;

/** The author; the token is created on first use. */
export function author(): Author {
  if (!current) {
    const saved = read();
    current = {
      name: typeof saved.name === 'string' ? saved.name : '',
      token: typeof saved.token === 'string' && /^[0-9a-f]{64}$/.test(saved.token) ? saved.token : randomToken(),
    };
    write(current);
  }
  return current;
}

export function setAuthorName(name: string): void {
  current = { ...author(), name: name.trim() };
  write(current);
}
