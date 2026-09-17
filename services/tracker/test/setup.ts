import { afterEach } from 'vitest';
import { cleanupDom } from './helpers';

afterEach(() => {
  cleanupDom();
});
