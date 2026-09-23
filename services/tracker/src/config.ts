export interface Texts {
  title: string;
  body: string;
  accept: string;
  reject: string;
  close: string;
  policy?: string;
  policyUrl?: string;
  reopen?: string;
}

export interface ConsentCfg {
  v: number;
  rev?: number;
  dl: string;
  at: number;
  rt: number;
  fl?: boolean;
  /** The whole banner stylesheet, compiled by the server from the theme (and vetted custom CSS). */
  css?: string;
  /** SVG path (24×24 viewBox, stroked) of the reopen button icon, from the server's allowlist. */
  ri?: string;
  texts: Record<string, Texts>;
}

export type Decision = 'accepted' | 'rejected';

/** The banner module (dist/banner.js), registered as `window.__an_b` before the core runs. */
export interface BannerUi {
  show(focus: boolean): void;
  fab(): void;
  close(): void;
}
export type BannerFactory = (c: ConsentCfg, decide: (s: Decision, kind: string) => void, open: () => void) => BannerUi;

export interface Cfg {
  k: string;
  g?: string;
  b?: boolean;
  c?: boolean;
  cd?: string | null;
  vd?: number;
  hr?: boolean;
  dnt?: 'ignore' | 'no_cookie' | 'no_tracking';
  gpc?: boolean;
  xp?: string[];
  loc?: boolean;
  ep?: string | null;
  auto?: { outbound?: boolean; downloads?: boolean; forms?: boolean };
  consent?: ConsentCfg | null;
}

export type Win = Window & Record<string, unknown>;

export const w = window as unknown as Win;
export const d = document;
export const n = navigator as Navigator & { globalPrivacyControl?: boolean };
export const cfg = w.__an_cfg as Cfg;
/** The executing <script> element, captured while the bundle runs synchronously. */
export const script = d.currentScript as HTMLScriptElement | null;

export const DAY = 864e5;
export const now = (): number => Date.now();
export const today = (): number => Math.floor(now() / DAY);
