import { sha256Hex } from '../../lib/sha256';
import type { ByteSource } from './source';

/** Bytes hashed at each end of the file. */
export const FINGERPRINT_SAMPLE = 4 * 1024 * 1024;

/**
 * Identifies a file across sessions (resume after the tab was closed).
 * Same formula as UploadsController::fingerprint():
 * SHA-256 of "{name}\n{size}\n{sha256(first 4 MiB)}\n{sha256(last 4 MiB)}".
 */
export async function fingerprint(source: ByteSource): Promise<string> {
  const sample = Math.min(source.size, FINGERPRINT_SAMPLE);
  const head = await sha256Hex(await source.read(0, sample));
  const tail = await sha256Hex(await source.read(source.size - sample, sample));
  return sha256Hex(new TextEncoder().encode(`${source.name}\n${source.size}\n${head}\n${tail}`));
}
