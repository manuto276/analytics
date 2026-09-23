import { afterEach } from 'vitest';
import { __reset } from '../src/client';

afterEach(() => {
  __reset();
  if (typeof window == 'undefined') return;
  for (const k of ['analytics', '__analytics', 'stats']) Reflect.deleteProperty(window, k);
  document.head.innerHTML = '';
  document.body.innerHTML = '';
});
