/** Random access to the bytes of a master: a local File, or a server file through HTTP Range. */

export interface ByteSource {
  name: string;
  size: number;
  read(offset: number, length: number): Promise<ArrayBuffer>;
}

export function fileSource(file: File): ByteSource {
  return {
    name: file.name,
    size: file.size,
    read: (offset, length) => file.slice(offset, offset + length).arrayBuffer(),
  };
}

export function httpSource(url: string, name: string, size: number): ByteSource {
  return {
    name,
    size,
    async read(offset, length) {
      const response = await fetch(url, {
        headers: { Range: `bytes=${offset}-${offset + length - 1}` },
        credentials: 'same-origin',
      });
      if (response.status !== 206 && response.status !== 200) {
        throw new Error(`HTTP ${response.status}`);
      }
      const buffer = await response.arrayBuffer();
      // A server ignoring Range would send everything: keep the requested slice.
      return response.status === 200 ? buffer.slice(offset, offset + length) : buffer;
    },
  };
}

/** Byte array source (tests). */
export function bufferSource(bytes: Uint8Array, name: string): ByteSource {
  return {
    name,
    size: bytes.byteLength,
    read: async (offset, length) => bytes.slice(offset, offset + length).buffer,
  };
}
