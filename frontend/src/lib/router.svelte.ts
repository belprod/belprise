/** Tiny History API router: the current URL as reactive state. */

class Location {
  path = $state(window.location.pathname);
  search = $state(window.location.search);
  /** True once the user has navigated inside the app (focus management). */
  navigated = false;
}

export const location = new Location();

function sync(): void {
  location.path = window.location.pathname;
  location.search = window.location.search;
}

window.addEventListener('popstate', () => {
  location.navigated = true;
  sync();
});

export function navigate(to: string, { replace = false } = {}): void {
  if (to === location.path + location.search) {
    return;
  }
  if (replace) {
    history.replaceState(null, '', to);
  } else {
    history.pushState(null, '', to);
  }
  location.navigated = true;
  sync();
  window.scrollTo(0, 0);
}

/** onclick handler for internal links: keeps new-tab and modified clicks native. */
export function followLink(event: MouseEvent): void {
  const anchor = event.currentTarget as HTMLAnchorElement;
  if (
    event.defaultPrevented ||
    event.button !== 0 ||
    event.metaKey ||
    event.ctrlKey ||
    event.shiftKey ||
    event.altKey ||
    anchor.target ||
    anchor.origin !== window.location.origin
  ) {
    return;
  }
  event.preventDefault();
  navigate(anchor.pathname + anchor.search);
}

/** Integer query parameter, or null. */
export function queryId(name: string): number | null {
  const value = new URLSearchParams(location.search).get(name);
  return value !== null && /^[1-9][0-9]*$/.test(value) ? Number(value) : null;
}
