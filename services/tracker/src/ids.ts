/** base64url of `n` random bytes (16 -> 22 chars, 12 -> 16 chars). */
export const rid = (n: number): string =>
  btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(n))))
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');

export const isId = (v: string | undefined): v is string => /^[\w-]{22}$/.test(v || '');
