import { describe, expect, it } from 'vitest';
import { api, installDom, load, makeConfig } from './helpers';

const consentWrite = (writes: string[]): string => writes.find((w) => w.startsWith('an_consent='))!;

describe('cookies and domain probe', () => {
  it('probes longest to shortest and uses the widest writable suffix, deleting the probe', async () => {
    const env = installDom({ url: 'https://shop.www.example.co.uk/' });
    await load();
    api().consent.set('accepted');
    const probes = env.jar.writes.filter((w) => w.startsWith('an_probe='));
    expect(probes).toEqual([
      'an_probe=1; Domain=shop.www.example.co.uk; Path=/; Max-Age=60; SameSite=Lax; Secure',
      'an_probe=; Domain=shop.www.example.co.uk; Path=/; Max-Age=0; SameSite=Lax; Secure',
      'an_probe=1; Domain=www.example.co.uk; Path=/; Max-Age=60; SameSite=Lax; Secure',
      'an_probe=; Domain=www.example.co.uk; Path=/; Max-Age=0; SameSite=Lax; Secure',
      'an_probe=1; Domain=example.co.uk; Path=/; Max-Age=60; SameSite=Lax; Secure',
      'an_probe=; Domain=example.co.uk; Path=/; Max-Age=0; SameSite=Lax; Secure',
      'an_probe=1; Domain=co.uk; Path=/; Max-Age=60; SameSite=Lax; Secure',
    ]);
    expect(env.jar.get('an_probe')).toBeUndefined();
    expect(consentWrite(env.jar.writes)).toContain('; Domain=example.co.uk;');
    // probing happens once
    api().consent.set('rejected');
    expect(env.jar.writes.filter((w) => w.startsWith('an_probe=1'))).toHaveLength(4);
  });

  it('uses the configured cookie domain without probing', async () => {
    const env = installDom({ cfg: makeConfig({ cd: '.example.com' }) });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.some((w) => w.startsWith('an_probe'))).toBe(false);
    expect(consentWrite(env.jar.writes)).toContain('; Domain=.example.com;');
    expect(env.jar.get('an_vid')!.domain).toBe('example.com');
  });

  it('falls back to host-only cookies for single-label hosts and omits Secure on http', async () => {
    const env = installDom({ url: 'http://localhost:8080/', cfg: makeConfig({ loc: true }) });
    await load();
    api().consent.set('accepted');
    expect(consentWrite(env.jar.writes)).toMatch(/^an_consent=1\.3\.a\.[\da-z]+; Path=\/; Max-Age=15552000; SameSite=Lax$/);
    expect(env.jar.get('an_consent')!.hostOnly).toBe(true);
  });

  it('uses host-only cookies on IP addresses', async () => {
    const env = installDom({ url: 'https://192.168.1.20/' });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.some((w) => w.startsWith('an_probe'))).toBe(false);
    expect(consentWrite(env.jar.writes)).not.toContain('Domain=');
  });

  it('falls back to host-only when no suffix is writable', async () => {
    const env = installDom({ url: 'https://www.example.com/', suffixes: ['com', 'www.example.com', 'example.com'] });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.filter((w) => w.startsWith('an_probe'))).toHaveLength(1);
    expect(consentWrite(env.jar.writes)).not.toContain('Domain=');
    expect(env.jar.get('an_consent')).toBeDefined();
  });
});
