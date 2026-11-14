import type { VersionStatus } from '../lib/status';

export type CommentStatus = 'todo' | 'done' | 'refused';

export interface ReviewComment {
  id: number;
  parentId: number | null;
  author: string;
  isAdmin: boolean;
  /** Positions in frames from the start of the video */
  frameIn: number;
  frameOut: number | null;
  text: string;
  /** Top-level comments only */
  status: CommentStatus | null;
  deleted: boolean;
  createdAt: string;
  /** Written from this browser */
  mine: boolean;
  /** Admin only */
  read?: boolean;
}

export interface VersionState {
  id: number;
  status: VersionStatus;
  approvedBy: string | null;
  approvedAt: string | null;
}

export interface CommentsPayload {
  version: VersionState;
  comments: ReviewComment[];
}

export interface NewComment {
  text: string;
  frameIn?: number;
  frameOut?: number | null;
  parentId?: number;
  author?: string;
}

/** Same review interface for the client link and the admin (§5.7). */
export interface ReviewApi {
  mode: 'client' | 'admin';
  list(versionId: number): Promise<CommentsPayload>;
  create(versionId: number, comment: NewComment): Promise<ReviewComment>;
  update(id: number, text: string): Promise<ReviewComment>;
  remove(id: number): Promise<void>;
  setStatus?(id: number, status: CommentStatus, reason?: string): Promise<ReviewComment>;
  approve?(versionId: number, author: string): Promise<VersionState>;
  markRead?(versionId: number): Promise<void>;
}
