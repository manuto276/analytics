import { mount } from '@vue/test-utils';
import { createSSRApp, defineComponent, effectScope, h, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { load } from '../src/index';
import { analyticsKey, createAnalytics, useAnalytics, useConsent } from '../src/vue';
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

const Status = defineComponent({
  setup() {
    const consent = useConsent();
    const client = useAnalytics();
    return () =>
      h('p', [
        h('span', { 'data-status': '' }, consent.status.value),
        h('span', { 'data-version': '' }, String(consent.state.value.version)),
        h('button', { onClick: () => consent.set('accepted') }, 'accept'),
        h('button', { onClick: () => client.track('clicked') }, 'track'),
      ]);
  },
});

describe('createAnalytics', () => {
  it('provides the client to components and as $analytics', () => {
    const plugin = createAnalytics(OPTS);
    expect(plugin.client).toBe(load(OPTS));
    const wrapper = mount(
      defineComponent({
        setup: () => ({ injected: useAnalytics() }),
        render: () => h('div'),
      }),
      { global: { plugins: [plugin] } },
    );
    expect(wrapper.vm.injected).toBe(plugin.client);
    expect(wrapper.vm.$analytics).toBe(plugin.client);
    expect(document.querySelectorAll('script')).toHaveLength(1);
  });

  it('accepts a client made elsewhere', () => {
    const client = load({ ...OPTS, globalName: 'stats' });
    expect(createAnalytics(client).client).toBe(client);
  });

  it('explains a missing plugin', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    expect(() => mount(Status)).toThrow(/createAnalytics/);
  });
});

describe('useConsent', () => {
  it('starts unknown, then follows the tracker', async () => {
    const plugin = createAnalytics(OPTS);
    const wrapper = mount(Status, { global: { plugins: [plugin] } });
    expect(wrapper.find('[data-status]').text()).toBe('unknown');

    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker({ status: 'rejected' })));
    await plugin.client.ready;
    await nextTick();
    expect(wrapper.find('[data-status]').text()).toBe('rejected');

    await wrapper.findAll('button')[0].trigger('click');
    expect(wrapper.find('[data-status]').text()).toBe('accepted');
    expect(wrapper.find('[data-version]').text()).toBe('3');
    await wrapper.findAll('button')[1].trigger('click');
    expect(fake.calls).toContainEqual(['track', 'clicked']);

    fake.api.consent.set('accepted'); // same state again: nothing to update
    fake.api.consent.forget();
    await nextTick();
    expect(wrapper.find('[data-status]').text()).toBe('rejected');
  });

  it('reads the current state on mount when the tracker is already there', async () => {
    runFakeTracker({ status: 'accepted' });
    const wrapper = mount(Status, { global: { plugins: [createAnalytics(OPTS)] } });
    await nextTick();
    expect(wrapper.find('[data-status]').text()).toBe('accepted');
  });

  it('works outside components with an explicit client, and stops with its scope', async () => {
    const client = load(OPTS);
    const scope = effectScope();
    const consent = scope.run(() => useConsent(client))!;
    expect(consent.status.value).toBe('unknown');
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await client.ready;
    consent.set('accepted');
    expect(consent.status.value).toBe('accepted');
    scope.stop();
    fake.api.consent.set('rejected');
    expect(consent.status.value).toBe('accepted');
    consent.open();
    consent.forget();
    expect(fake.calls).toContainEqual(['consent.open']);
    expect(fake.calls).toContainEqual(['consent.forget']);
  });

  it('ignores the tracker loading after the scope is gone', async () => {
    const client = load(OPTS);
    const scope = effectScope();
    const consent = scope.run(() => useConsent(client))!;
    scope.stop();
    net.respond(() => runFakeTracker({ status: 'accepted' }));
    await client.ready;
    expect(consent.status.value).toBe('unknown');
  });

  it('works without any scope', () => {
    runFakeTracker({ status: 'accepted' });
    expect(useConsent(load(OPTS)).status.value).toBe('accepted');
  });

  it('renders unknown on the server (Nuxt SSR)', async () => {
    vi.stubGlobal('window', undefined);
    try {
      const app = createSSRApp(Status);
      const plugin = createAnalytics(OPTS);
      app.use(plugin);
      expect(app._context.provides[analyticsKey as symbol]).toBe(plugin.client);
      const html = await renderToString(app);
      expect(html).toContain('unknown');
    } finally {
      vi.unstubAllGlobals();
    }
    expect(document.querySelectorAll('script')).toHaveLength(0);
  });
});
