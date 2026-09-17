import { describe, expect, it, vi } from 'vitest';
import { advanceTime, api, bannerHost, click, dialogEl, installDom, load } from './helpers';

describe('no storage before a choice', () => {
  it('never touches cookies, Web Storage or IndexedDB until the visitor decides', async () => {
    const cookieWrites: string[] = [];
    const env = installDom({ html: '<button data-analytics-event="cta" data-analytics-prop-plan="pro">x</button><input data-analytics-visitor>' });
    const origWrite = env.jar.write.bind(env.jar);
    env.jar.write = (s: string) => {
      cookieWrites.push(s);
      origWrite(s);
    };
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const getItem = vi.spyOn(Storage.prototype, 'getItem');
    const idbOpen = vi.fn();
    vi.stubGlobal('indexedDB', { open: idbOpen });
    const lsGet = vi.fn(() => window.sessionStorage);
    const ssGet = vi.fn(() => window.sessionStorage);
    const lsDesc = Object.getOwnPropertyDescriptor(window, 'localStorage');
    const ssDesc = Object.getOwnPropertyDescriptor(window, 'sessionStorage');
    Object.defineProperty(window, 'localStorage', { configurable: true, get: lsGet });
    Object.defineProperty(window, 'sessionStorage', { configurable: true, get: ssGet });
    try {
      await load();
      expect(dialogEl()).not.toBeNull();
      const a = api();
      a.track('signup', { plan: 'pro' });
      a.pageview();
      a.setContent('author:1');
      history.pushState({}, '', '/next');
      window.dispatchEvent(new Event('popstate'));
      window.dispatchEvent(new Event('scroll'));
      click(document.querySelector('[data-analytics-event]'));
      expect(a.getVisitorId()).toBeNull();
      a.consent.get();
      a.consent.open();
      advanceTime(2000);
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new Event('pagehide'));

      expect(env.events().length).toBeGreaterThan(3);
      expect(env.events().every((e) => e._l == 'b')).toBe(true);
      expect(env.sent().every((s) => !('vid' in s.body) && !('sid' in s.body))).toBe(true);
      expect(cookieWrites).toEqual([]);
      expect(document.cookie).toBe('');
      expect(setItem).not.toHaveBeenCalled();
      expect(getItem).not.toHaveBeenCalled();
      expect(lsGet).not.toHaveBeenCalled();
      expect(ssGet).not.toHaveBeenCalled();
      expect(idbOpen).not.toHaveBeenCalled();
      expect(bannerHost()).not.toBeNull();

      // the first write happens only once the visitor decides
      click(dialogEl()!.querySelector('[data-a]'));
      expect(cookieWrites.length).toBeGreaterThan(0);
      expect(setItem).not.toHaveBeenCalled();
      expect(idbOpen).not.toHaveBeenCalled();
    } finally {
      if (lsDesc) Object.defineProperty(window, 'localStorage', lsDesc);
      else delete (window as unknown as Record<string, unknown>).localStorage;
      if (ssDesc) Object.defineProperty(window, 'sessionStorage', ssDesc);
      else delete (window as unknown as Record<string, unknown>).sessionStorage;
    }
  });
});
