/** Boot data of the client pages (see Belprise\Controller\PublicController). */
import type { VersionStatus } from '../lib/status';
import type { SpriteInfo } from '../player/logic';

export interface ClientBrand {
  name: string;
  logoUrl: string | null;
  welcomeText?: string;
}

/** A published version as the client pages receive it. */
export interface PublicVersion {
  id: number;
  number: number;
  fpsNum: number;
  fpsDen: number;
  dropFrame: boolean;
  startFrame: number;
  durationFrames: number;
  videoUrl: string;
  /** HLS playback versions (§5.7 bis), when encoded */
  hlsUrl?: string | null;
  posterUrl: string | null;
  sprite: SpriteInfo | null;
  /** Review page only */
  status?: VersionStatus;
  publishedAt?: string | null;
  width?: number;
  height?: number;
  size?: number;
}

export interface VideoInfo {
  number: number;
  title: string;
  /** Client link only */
  downloadAllowed?: boolean;
}

export interface SpaceVideo {
  number: number;
  title: string;
  /** Review page of the video (client link) */
  href: string;
  posterUrl: string | null;
  duration: string;
  version: number;
  status: VersionStatus;
  publishedAt: string | null;
}

/** Archived video of the client's library (watch and download only). */
export interface LibraryVideo extends SpaceVideo {
  projectName: string;
  archivedAt: string;
}

export interface SpaceProject {
  name: string;
  updatedAt: string | null;
  videos: SpaceVideo[];
}

/** A video of a prospect collection (read-only). */
export interface CollectionItem {
  title: string;
  duration: string;
  thumbUrl: string | null;
  version: PublicVersion;
}

export type ClientBoot =
  | { page: 'space'; client: ClientBrand; projects: SpaceProject[]; library: LibraryVideo[] }
  | { page: 'library'; client: ClientBrand; project: { name: string }; video: VideoInfo; versions: PublicVersion[]; archivedAt: string }
  | { page: 'review'; client: ClientBrand; project: { name: string }; video: VideoInfo; versions: PublicVersion[]; csrf: string }
  | { page: 'view'; client: ClientBrand; video: VideoInfo; versions: PublicVersion[] }
  | { page: 'collection'; client: ClientBrand; collection: { title: string; introduction: string }; videos: CollectionItem[] };
