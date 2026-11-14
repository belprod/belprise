/** Unread client comments: the badge of "Révisions" in the menu (§5.7). */
import { api } from '../lib/api';

class Unread {
  count = $state(0);

  async refresh(): Promise<void> {
    try {
      this.count = (await api<{ unread: number }>('GET', '/api/admin/unread')).unread;
    } catch {
      // keep the last value
    }
  }
}

export const unread = new Unread();
