/** Reads the JSON data block written by the PHP shell. */
export function readBoot<T>(id = 'belprise-boot'): T {
  const element = document.getElementById(id);
  if (!element?.textContent) {
    throw new Error(`Missing boot data #${id}`);
  }
  return JSON.parse(element.textContent) as T;
}
