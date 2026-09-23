import { describe, expectTypeOf, it } from 'vitest';
import type { AnalyticsGlobal, AnalyticsGlobals, AnalyticsWindow, TrackerApi } from '../src/index';

declare global {
  // A site whose tracker is configured with the global name `stats`.
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface Window extends AnalyticsGlobals<'stats'> {}
}

describe('window typings', () => {
  it('types window.analytics, window.__analytics and custom names', () => {
    expectTypeOf(window.analytics).toEqualTypeOf<AnalyticsGlobal | undefined>();
    expectTypeOf(window.__analytics).toEqualTypeOf<AnalyticsGlobal | undefined>();
    expectTypeOf(window.stats).toEqualTypeOf<AnalyticsGlobal | undefined>();
    expectTypeOf((window as AnalyticsWindow<'myStats'>).myStats).toEqualTypeOf<AnalyticsGlobal | undefined>();
  });

  it('lets callers use the stub or the tracker with optional chaining', () => {
    expectTypeOf(window.analytics?.track).toEqualTypeOf<TrackerApi['track'] | undefined>();
    expectTypeOf<Parameters<TrackerApi['consent']['set']>[0]>().toEqualTypeOf<'unknown' | 'accepted' | 'rejected'>();
  });
});
