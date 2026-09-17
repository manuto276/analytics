import { cfg, d } from './config';
import { open, visitorId } from './consent';

/** Fill `input[data-analytics-visitor]` with the visitor id (empty without consent). */
export const fill = (): void => {
  const v = visitorId() || '';
  d.querySelectorAll<HTMLInputElement>('input[data-analytics-visitor]').forEach((i) => {
    i.value = v;
  });
};

type Track = (name: string, props: Record<string, string>) => void;

export const initDom = (track: Track): void => {
  d.addEventListener('click', (e) => {
    const t = e.target as Element;
    if (!t.closest) return;
    const c = t.closest('[data-analytics-consent],a[href="#analytics-consent"]');
    const el = t.closest('[data-analytics-event]');
    if (c) {
      e.preventDefault();
      open();
    }
    if (el) {
      const p: Record<string, string> = {};
      for (const a of Array.from(el.attributes))
        if (a.name.indexOf('data-analytics-prop-') == 0) p[a.name.slice(20)] = a.value;
      track(el.getAttribute('data-analytics-event') as string, p);
    }
  });
};

const EXT =
  /\.(pdf|docx?|xlsx?|pptx?|csv|zip|rar|7z|gz|tar|dmg|pkg|exe|msi|apk|mp3|mp4|mov|avi|wav|txt|rtf|key|numbers|pages)$/;

/** Own host: the same host, or one of them is a subdomain of the other. */
const own = (h: string): boolean => {
  const o = location.hostname;
  return h == o || o.endsWith('.' + h) || h.endsWith('.' + o);
};

/** Optional automatic events: outbound link clicks, file downloads, form submits. */
export const initAuto = (track: Track): void => {
  const a = cfg.auto || {};
  if (a.outbound || a.downloads)
    d.addEventListener(
      'click',
      (e) => {
        const t = e.target as Element;
        const el = t.closest && t.closest<HTMLAnchorElement>('a[href]');
        if (!el) return;
        const u = new URL(el.href, location.href);
        if (!/^https?:$/.test(u.protocol) || u.href.split('#')[0] == location.href.split('#')[0]) return;
        const m = EXT.exec(u.pathname.toLowerCase());
        if (m && a.downloads) track('file_download', { url: u.href, ext: m[1] });
        else if (a.outbound && !own(u.hostname)) track('outbound_link', { url: u.href, host: u.hostname });
      },
      true,
    );
  if (a.forms)
    d.addEventListener('submit', (e) => {
      const f = e.target as HTMLFormElement;
      if (f.tagName == 'FORM')
        track('form_submit', { id: f.id || f.name || '', action: new URL(f.action, location.href).pathname });
    });
};
