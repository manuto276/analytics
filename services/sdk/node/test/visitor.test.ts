import { IncomingMessage } from 'node:http';
import { Socket } from 'node:net';
import { describe, expect, it } from 'vitest';
import { visitorIdFromCookie, visitorIdFromRequest } from '../src/index';
import { VID } from './helpers';

describe('visitorIdFromCookie', () => {
  it.each([
    [`an_vid=${VID}`, VID],
    [`theme=dark; an_vid=${VID}; an_sid=xyz`, VID],
    [`  an_vid = ${VID} `, VID],
    [`an_vid="${VID}"`, VID],
    [`an_vid=${encodeURIComponent(VID)}`, VID],
    [`an_vid=short; an_vid=${VID}`, VID],
    [`xan_vid=${VID}`, null],
    [`an_vid=${VID}x`, null],
    ['an_vid=%E0%A4%A', null],
    ['an_vid', null],
    ['', null],
    [null, null],
    [undefined, null],
    [[`a=1`, `an_vid=${VID}`], VID],
  ])('%j → %s', (header, expected) => {
    expect(visitorIdFromCookie(header)).toBe(expected);
  });
});

describe('visitorIdFromRequest', () => {
  it('reads a Node IncomingMessage', () => {
    const req = new IncomingMessage(new Socket());
    req.headers.cookie = `an_vid=${VID}`;
    expect(visitorIdFromRequest(req)).toBe(VID);
    req.headers.cookie = undefined;
    expect(visitorIdFromRequest(req)).toBeNull();
  });

  it('reads an Express request with cookie-parser, and without', () => {
    expect(visitorIdFromRequest({ headers: {}, cookies: { an_vid: VID } })).toBe(VID);
    expect(visitorIdFromRequest({ headers: { cookie: `an_vid=${VID}` }, cookies: { an_vid: 'forged!' } })).toBe(VID);
    expect(visitorIdFromRequest({ headers: { cookie: `an_vid=${VID}` }, cookies: null })).toBe(VID);
    expect(visitorIdFromRequest({ headers: {}, cookies: { an_vid: 'forged!' } })).toBeNull();
  });

  it('reads a Fetch API Request', () => {
    const req = new Request('https://shop.example.com/checkout', { headers: { cookie: `a=b; an_vid=${VID}` } });
    expect(visitorIdFromRequest(req)).toBe(VID);
    expect(visitorIdFromRequest(new Request('https://shop.example.com/'))).toBeNull();
  });

  it('copes with nothing', () => {
    expect(visitorIdFromRequest(null)).toBeNull();
    expect(visitorIdFromRequest({} as never)).toBeNull();
  });
});
