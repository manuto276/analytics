import { d } from './config';
import { open, visitorId } from './consent';

/** Fill `input[data-analytics-visitor]` with the visitor id (empty without consent). */
export const fill = (): void => {
  const v = visitorId() || '';
  d.querySelectorAll<HTMLInputElement>('input[data-analytics-visitor]').forEach((i) => {
    i.value = v;
  });
};

export const initDom = (track: (name: string, props: Record<string, string>) => void): void => {
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
