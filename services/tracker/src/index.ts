import { api, install, pageview } from './api';
import { cfg, d } from './config';
import { href, initCollector } from './collector';
import { initConsent, level } from './consent';
import { initAuto, initDom } from './dom';
import { initSpa } from './spa';

const init = (): void => {
  const replay = install();
  if (!replay) return;
  initCollector(level);
  initConsent();
  initSpa(pageview);
  initDom(api.track);
  initAuto(api.track);
  replay();
  pageview(href(), d.referrer);
};

if (cfg && cfg.k) {
  if ((d as Document & { prerendering?: boolean }).prerendering)
    d.addEventListener('prerenderingchange', init, { once: true });
  else init();
}
