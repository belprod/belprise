/** Shapes returned by the admin API (see Belprise\Controller\Admin\Presenter). */
import type { VersionStatus } from '../lib/status';

export type LinkType = 'space' | 'client' | 'view' | 'collection';

export interface LinkInfo {
  id: number;
  type: LinkType;
  path: string;
  isActive: boolean;
  hasPassword: boolean;
  /** Last day the link works, "YYYY-MM-DD" (Paris time) */
  expiresOn: string | null;
  status: 'active' | 'disabled' | 'expired';
}

export interface Client {
  id: number;
  name: string;
  logoUrl: string | null;
  bgColor: string;
  textColor: string;
  accentColor: string;
  welcomeText: string;
  archived: boolean;
  createdAt: string;
  projectCount?: number;
  videoCount?: number;
  link?: LinkInfo;
}

export interface Project {
  id: number;
  name: string;
  clientId: number;
  clientName: string;
  videoCount: number;
  archived: boolean;
  clientArchived: boolean;
  createdAt: string;
}

export interface Video {
  id: number;
  number: number;
  title: string;
  projectId: number;
  projectName: string;
  clientId: number;
  clientName: string;
  tags: string[];
  downloadAllowed: boolean;
  archived: boolean;
  parentArchived: boolean;
  /** Status of the latest published version */
  status: VersionStatus | null;
  versionCount: number;
  lastVersion: number | null;
  /** Latest version import, else creation */
  lastActivityAt: string;
  createdAt: string;
  links?: { client: LinkInfo; view: LinkInfo };
}

export type { VersionStatus };

export interface Version {
  id: number;
  videoId: number;
  number: number;
  status: VersionStatus;
  isPublished: boolean;
  publishedAt: string | null;
  approvedBy: string | null;
  approvedAt: string | null;
  createdAt: string;
  originalName: string | null;
  size: number | null;
  codec: string | null;
  width: number | null;
  height: number | null;
  fpsNum: number | null;
  fpsDen: number | null;
  dropFrame: boolean;
  durationFrames: number | null;
  startFrame: number;
  startTimecode: string | null;
  timecodeSource: 'tmcd' | 'xmp' | 'default' | 'manual' | null;
  bitRate: number | null;
  videoUrl: string | null;
  /** HLS playback versions (§5.7 bis), null until encoded */
  hlsUrl: string | null;
  /** H.264 / HEVC master: browsers read it (else ProRes / MXF, reviewed through the playback version) */
  playable: boolean;
  /** Encoding on the server while it matters (§5.7 bis): waiting, running or failed */
  encoding?: { status: 'queued' | 'running' | 'failed'; progress: number; error: string | null; attempts: number } | null;
  posterUrl: string | null;
  posterFrame: number | null;
  thumbUrl: string | null;
  spriteUrl: string | null;
  sprite: { columns: number; count: number; width: number; height: number; interval: number } | null;
}

export interface PendingUpload {
  id: number;
  name: string;
  size: number;
  bytesReceived: number;
  videoId: number | null;
  videoNumber: number | null;
  videoTitle: string | null;
  updatedAt: string;
}

export interface CollectionSummary {
  id: number;
  title: string;
  videoCount: number;
  updatedAt: string;
  link: LinkInfo | null;
}

/** A video of a collection, with the version prospects see. */
export interface CollectionVideo {
  id: number;
  number: number;
  title: string;
  clientName: string;
  projectName: string;
  /** Latest approved published version, else latest published; null: not shown */
  version: number | null;
  approved: boolean;
  thumbUrl: string | null;
}

export interface Collection {
  id: number;
  title: string;
  introduction: string;
  bgColor: string;
  textColor: string;
  accentColor: string;
  logoUrl: string | null;
  videos: CollectionVideo[];
  link: LinkInfo | null;
}
