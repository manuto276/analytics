import { cfg, n, script } from './config';

let base: string | undefined;

/** `cfg.ep`, or the script src without its file name. */
export const endpoint = (): string =>
  (base ??= cfg.ep || ((script && script.src) || '').replace(/[^/]*$/, ''));

const post = (u: string, j: string, retry: boolean): void => {
  const again = (): void => {
    if (retry) post(u, j, false);
  };
  fetch(u, {
    method: 'POST',
    body: j,
    keepalive: true,
    credentials: 'omit',
    headers: { 'Content-Type': 'text/plain' },
  }).then((r) => {
    if (r.status > 499) again();
  }, again);
};

export const send = (body: object, path = 'e'): void => {
  const u = endpoint() + path;
  const j = JSON.stringify(body);
  if (!(n.sendBeacon && n.sendBeacon(u, new Blob([j], { type: 'text/plain' })))) post(u, j, true);
};
