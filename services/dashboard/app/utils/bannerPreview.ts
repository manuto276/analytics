/**
 * Message protocol between the consent editor and the preview page it frames
 * (public/_preview/banner.html + preview.js, which runs the real tracker banner module).
 *
 * The frame is sandboxed without allow-same-origin, so its origin is opaque ("null"): the dashboard
 * recognises it by `event.source` and posts to it with targetOrigin "*" (the configuration it sends
 * is what every visitor of the site receives anyway — nothing secret). The page only accepts
 * messages from its parent window whose origin is the dashboard's.
 */
import type { ConsentTrackerConfig } from '~/types'

export const PREVIEW_PAGE = '/_preview/banner.html'
export const MSG_READY = 'analytics-banner-preview:ready'
export const MSG_RENDER = 'analytics-banner-preview:render'
export const MSG_ERROR = 'analytics-banner-preview:error'

export type PreviewView = 'banner' | 'reopen'
export type PreviewDevice = 'mobile' | 'desktop'

/** Viewports the frame is rendered at (then scaled down to fit). */
export const PREVIEW_VIEWPORTS: Record<PreviewDevice, { width: number, height: number }> = {
  mobile: { width: 390, height: 844 },
  desktop: { width: 1280, height: 800 }
}

export interface RenderMessage {
  type: typeof MSG_RENDER
  view: PreviewView
  locale: string
  config: ConsentTrackerConfig
}

/** Scale that fits a viewport into the available box (never enlarges). */
export function fitScale(viewport: { width: number, height: number }, box: { width: number, height: number }): number {
  if (box.width <= 0) return 1
  const scale = Math.min(1, box.width / viewport.width, box.height > 0 ? box.height / viewport.height : 1)
  return Math.round(scale * 1000) / 1000
}
