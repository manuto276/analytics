/** The format of the `an_vid` cookie: 22 characters of base64url (16 random bytes). */
export const VISITOR_ID = /^[A-Za-z0-9_-]{22}$/;

export const isVisitorId = (v: unknown): v is string => typeof v == 'string' && VISITOR_ID.test(v);

/**
 * The visitor id from a `Cookie` header (`an_vid`, written by the tracker after consent), or `null`
 * when it is missing or malformed. The cookie is attacker-controlled, so the format is checked.
 */
export function visitorIdFromCookie(cookieHeader: string | readonly string[] | null | undefined): string | null {
  const header = Array.isArray(cookieHeader) ? cookieHeader.join('; ') : (cookieHeader as string | null | undefined);
  if (!header) return null;
  for (const part of header.split(';')) {
    const eq = part.indexOf('=');
    if (eq < 0 || part.slice(0, eq).trim() !== 'an_vid') continue;
    let value = part.slice(eq + 1).trim();
    if (value.length > 1 && value.startsWith('"') && value.endsWith('"')) value = value.slice(1, -1);
    try {
      value = decodeURIComponent(value);
    } catch {
      continue;
    }
    if (isVisitorId(value)) return value;
  }
  return null;
}

/** A Fetch API `Request` (Next.js route handlers, Hono, Remix, workers…). */
export interface FetchRequestLike {
  headers: { get(name: string): string | null };
}

/** A Node `IncomingMessage`, or an Express/Koa/Fastify request (with `cookies` from a cookie parser). */
export interface NodeRequestLike {
  headers: Record<string, string | string[] | undefined>;
  cookies?: Record<string, unknown> | null;
}

export type RequestLike = FetchRequestLike | NodeRequestLike;

/** The visitor id of the request's `an_vid` cookie, or `null`. */
export function visitorIdFromRequest(req: RequestLike | null | undefined): string | null {
  if (!req) return null;
  const parsed = (req as NodeRequestLike).cookies;
  if (parsed && typeof parsed == 'object' && isVisitorId(parsed.an_vid)) return parsed.an_vid;
  const headers = req.headers as FetchRequestLike['headers'] | NodeRequestLike['headers'] | undefined;
  if (!headers) return null;
  if (typeof headers.get == 'function') return visitorIdFromCookie((headers as FetchRequestLike['headers']).get('cookie'));
  return visitorIdFromCookie((headers as NodeRequestLike['headers']).cookie);
}
