// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { generate } from '../scripts/tracker-types.mjs';

describe('types shared with the tracker', () => {
  it('src/generated/tracker.ts is what the tracker source generates (run `pnpm tracker-types`)', () => {
    const committed = readFileSync(new URL('../src/generated/tracker.ts', import.meta.url), 'utf8');
    expect(generate()).toBe(committed);
  });
});
