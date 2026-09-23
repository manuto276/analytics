/*
 * Consent banner preview (framed by the dashboard, see banner.html and
 * services/dashboard/app/utils/bannerPreview.ts for the protocol).
 *
 * Receives { type: "analytics-banner-preview:render", view, locale, config } from the parent
 * window, checks where it comes from and what it looks like, and renders it with the real banner
 * factory (window.__an_b, registered by banner.js). decide/open are no-ops: nothing is tracked,
 * stored or sent. Plain ES2019, no dependencies, no network.
 */
(function (win) {
  'use strict'

  var READY = 'analytics-banner-preview:ready'
  var RENDER = 'analytics-banner-preview:render'
  var ERROR = 'analytics-banner-preview:error'
  var MAX_CSS = 262144
  var doc = win.document
  // The frame is sandboxed (opaque origin), but its URL keeps the dashboard's origin: that is the
  // only sender accepted, and only the parent window.
  var parentOrigin = win.location.origin

  function noop() {}

  function isObject(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value)
  }

  function isString(value, max) {
    return typeof value === 'string' && value.length <= max
  }

  var TEXT_KEYS = { title: 200, body: 2000, accept: 80, reject: 80, close: 80, policy: 120, policyUrl: 600, reopen: 120 }
  var REQUIRED = ['title', 'body', 'accept', 'reject', 'close']

  function validTexts(texts) {
    if (!isObject(texts)) return false
    var locales = Object.keys(texts)
    if (locales.length === 0 || locales.length > 50) return false
    return locales.every(function (locale) {
      var values = texts[locale]
      if (!/^[a-z]{2}(-[A-Z]{2})?$/.test(locale) || !isObject(values)) return false
      return REQUIRED.every(function (key) { return typeof values[key] === 'string' })
        && Object.keys(values).every(function (key) {
          return Object.prototype.hasOwnProperty.call(TEXT_KEYS, key) && isString(values[key], TEXT_KEYS[key])
        })
    })
  }

  /** The render message, or null when anything about it is off. */
  function parse(data) {
    if (!isObject(data) || data.type !== RENDER) return null
    if (data.view !== 'banner' && data.view !== 'reopen') return null
    if (!isString(data.locale, 10) || !/^[a-z]{2}(-[A-Z]{2})?$/.test(data.locale)) return null
    var c = data.config
    if (!isObject(c) || !isString(c.css, MAX_CSS) || !validTexts(c.texts)) return null
    if (typeof c.ri !== 'string' || !/^[0-9A-Za-z .,-]{0,2000}$/.test(c.ri)) return null
    if (!isString(c.dl, 10) || typeof c.fl !== 'boolean') return null
    if (typeof c.v !== 'number' || typeof c.at !== 'number' || typeof c.rt !== 'number') return null
    return {
      view: data.view,
      locale: data.locale,
      // Only the fields the banner module reads; nothing else is passed on.
      config: { v: c.v, rev: c.rev, dl: c.dl, at: c.at, rt: c.rt, fl: c.fl, css: c.css, ri: c.ri, texts: c.texts },
    }
  }

  function clear() {
    var hosts = doc.querySelectorAll('[data-analytics-banner]')
    for (var i = 0; i < hosts.length; i++) hosts[i].remove()
  }

  function render(message) {
    var factory = win.__an_b
    if (typeof factory !== 'function') {
      win.parent.postMessage({ type: ERROR, reason: 'banner-module-missing' }, parentOrigin)
      return false
    }
    clear()
    doc.documentElement.lang = message.locale
    var ui = factory(message.config, noop, noop)
    if (message.view === 'banner') ui.show(false)
    else ui.fab()
    return true
  }

  function onMessage(event) {
    if (event.source !== win.parent || event.origin !== parentOrigin) return
    var message = parse(event.data)
    if (message) render(message)
  }

  win.addEventListener('message', onMessage)
  win.__anPreview = { parse: parse, render: render, onMessage: onMessage }
  if (win.parent !== win) {
    win.parent.postMessage(typeof win.__an_b === 'function' ? { type: READY } : { type: ERROR, reason: 'banner-module-missing' }, parentOrigin)
  }
})(window)
