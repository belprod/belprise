import { api } from '../lib/api';
import type { CommentStatus, CommentsPayload, NewComment, ReviewApi, ReviewComment, VersionState } from './types';

/** Client review page: through the link (/api/v/{n°}{key}). CSRF and author token are set globally. */
export function clientReviewApi(ref: string): ReviewApi {
  const base = `/api/v/${ref}`;
  return {
    mode: 'client',
    list: (versionId) => api<CommentsPayload>('GET', `${base}/versions/${versionId}/comments`),
    create: (versionId, comment: NewComment) => api<ReviewComment>('POST', `${base}/versions/${versionId}/comments`, comment),
    update: (id, text) => api<ReviewComment>('PATCH', `${base}/comments/${id}`, { text }),
    remove: async (id) => {
      await api('DELETE', `${base}/comments/${id}`);
    },
    approve: (versionId, author) => api<VersionState>('POST', `${base}/versions/${versionId}/approve`, { author }),
  };
}

/** Admin review: replies signed "belprod.", statuses, deletion, read marks. */
export function adminReviewApi(): ReviewApi {
  return {
    mode: 'admin',
    list: (versionId) => api<CommentsPayload>('GET', `/api/admin/versions/${versionId}/comments`),
    create: (versionId, comment: NewComment) => api<ReviewComment>('POST', `/api/admin/versions/${versionId}/comments`, comment),
    update: (id, text) => api<ReviewComment>('PATCH', `/api/admin/comments/${id}`, { text }),
    remove: async (id) => {
      await api('DELETE', `/api/admin/comments/${id}`);
    },
    setStatus: (id, status: CommentStatus, reason?: string) =>
      api<ReviewComment>('PATCH', `/api/admin/comments/${id}`, reason === undefined ? { status } : { status, reason }),
    markRead: async (versionId) => {
      await api('POST', `/api/admin/versions/${versionId}/comments/read`);
    },
  };
}
