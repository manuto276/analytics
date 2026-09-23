import { act, render, renderHook, screen } from '@testing-library/react';
import { StrictMode, type ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { load } from '../src/index';
import { AnalyticsProvider, useAnalytics, useConsent } from '../src/react';
import { holdScripts, runFakeTracker } from './fake-tracker';

const PK = 'pk_ABCDEFGHIJKLMNOPQRSTU';
const OPTS = { serviceUrl: 'https://stats.example.net', publicKey: PK };

let net: ReturnType<typeof holdScripts>;
beforeEach(() => {
  net = holdScripts();
});
afterEach(() => {
  net.release();
});

const wrapper = ({ children }: { children?: ReactNode }) => (
  <StrictMode>
    <AnalyticsProvider {...OPTS}>{children}</AnalyticsProvider>
  </StrictMode>
);

function ConsentStatus() {
  const consent = useConsent();
  return (
    <p>
      <span data-testid="status">{consent.status}</span>
      <button onClick={() => consent.set('accepted')}>accept</button>
      <button onClick={consent.open}>settings</button>
    </p>
  );
}

describe('AnalyticsProvider and useAnalytics', () => {
  it('loads the tracker once and hands out the same client', () => {
    const { result, rerender } = renderHook(() => useAnalytics(), { wrapper });
    const first = result.current;
    rerender();
    expect(result.current).toBe(first);
    expect(result.current).toBe(load(OPTS));
    expect(document.querySelectorAll('script')).toHaveLength(1);
  });

  it('accepts a client made elsewhere', () => {
    const client = load({ ...OPTS, globalName: 'stats' });
    const { result } = renderHook(() => useAnalytics(), {
      wrapper: ({ children }) => <AnalyticsProvider client={client}>{children}</AnalyticsProvider>,
    });
    expect(result.current).toBe(client);
  });

  it('throws outside a provider', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    expect(() => renderHook(() => useAnalytics())).toThrow(/AnalyticsProvider/);
  });
});

describe('useConsent', () => {
  it('is unknown until the tracker loads, then follows every change', async () => {
    render(<ConsentStatus />, { wrapper });
    expect(screen.getByTestId('status').textContent).toBe('unknown');

    let fake!: ReturnType<typeof runFakeTracker>;
    await act(async () => {
      net.respond(() => (fake = runFakeTracker({ status: 'rejected' })));
      await load(OPTS).ready;
    });
    expect(screen.getByTestId('status').textContent).toBe('rejected');

    act(() => screen.getByText('accept').click());
    expect(screen.getByTestId('status').textContent).toBe('accepted');
    expect(fake.calls).toContainEqual(['consent.set', 'accepted']);

    act(() => fake.api.consent.forget()); // e.g. from the banner or another component
    expect(screen.getByTestId('status').textContent).toBe('rejected');

    act(() => screen.getByText('settings').click());
    expect(fake.calls).toContainEqual(['consent.open']);
  });

  it('queues actions taken before the tracker loads', async () => {
    const { result } = renderHook(() => useConsent(), { wrapper });
    act(() => result.current.set('accepted'));
    expect(result.current.status).toBe('unknown');
    await act(async () => {
      net.respond(() => runFakeTracker());
      await load(OPTS).ready;
    });
    expect(result.current.status).toBe('accepted');
    expect(result.current.version).toBe(3);
  });

  it('keeps the same object while nothing changes', async () => {
    const { result, rerender } = renderHook(() => useConsent(), { wrapper });
    const first = result.current;
    rerender();
    expect(result.current).toBe(first);
  });

  it('stops listening when unmounted', async () => {
    const { result, unmount } = renderHook(() => useConsent(), { wrapper });
    const forget = result.current.forget;
    unmount();
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await load(OPTS).ready;
    forget();
    expect(fake.calls).toContainEqual(['consent.forget']);
  });

  it('renders unknown on the server, without touching the document', () => {
    vi.stubGlobal('window', undefined);
    try {
      const html = renderToString(
        <AnalyticsProvider {...OPTS}>
          <ConsentStatus />
        </AnalyticsProvider>,
      );
      expect(html).toContain('unknown');
    } finally {
      vi.unstubAllGlobals();
    }
    expect(document.querySelectorAll('script')).toHaveLength(0);
  });
});
