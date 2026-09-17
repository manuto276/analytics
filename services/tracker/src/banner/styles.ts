import type { Theme } from '../config';

const col = (v: string | undefined, def: string): string => (/^#[\da-f]{3,8}$/i.test(v || '') ? (v as string) : def);

export const css = (t: Theme = {}): string => {
  const bg = col(t.bg, '#fff');
  const fg = col(t.fg, '#111');
  const ac = col(t.ac, '#1d4ed8');
  const r = Math.min(Math.max(t.rad == null ? 8 : t.rad | 0, 0), 32) + 'px';
  return (
    '.b,.f{position:fixed;z-index:2147483647;bottom:16px;left:16px;box-sizing:border-box;font:inherit;line-height:1.5;color:' +
    fg +
    ';background:' +
    bg +
    ';border-radius:' +
    r +
    ';box-shadow:0 4px 24px rgba(0,0,0,.25);animation:i .2s}' +
    '.b{right:16px;max-width:36em;margin:auto;padding:16px}.l{right:auto}.r{left:auto;right:16px}' +
    'h2{margin:0 32px 8px 0;font-size:1.1em}p{margin:0 0 12px}a{color:inherit}' +
    '.a{display:flex;flex-wrap:wrap;gap:8px}' +
    '.k{flex:1;font:inherit;padding:8px 16px;cursor:pointer;border:2px solid ' +
    ac +
    ';border-radius:' +
    r +
    ';background:' +
    ac +
    ';color:' +
    col(t.acf, '#fff') +
    '}' +
    '.x{position:absolute;top:8px;right:8px;font:inherit;font-size:1.4em;line-height:1;padding:4px 8px;border:0;background:none;color:inherit;cursor:pointer}' +
    ':focus-visible{outline:2px solid ' +
    ac +
    ';outline-offset:2px}' +
    '@keyframes i{from{opacity:0;transform:translateY(8px)}}' +
    '@media (prefers-reduced-motion:reduce){.b,.f{animation:none}}' +
    '@media (forced-colors:active){.b,.f,.k{border:1px solid CanvasText}}'
  );
};
