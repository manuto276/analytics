import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { generate } from '../scripts/openapi-types.mjs';

describe('generated API types', () => {
  it('src/generated/openapi.ts matches docs/api/openapi.yaml (run `pnpm openapi-types`)', async () => {
    const committed = readFileSync(new URL('../src/generated/openapi.ts', import.meta.url), 'utf8');
    expect(await generate()).toBe(committed);
  });
});
