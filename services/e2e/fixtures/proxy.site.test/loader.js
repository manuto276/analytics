// Same-origin proxy variant: the tracker is loaded from /stats/<key>.js on the
// tracked site itself (nginx proxies /stats/ to https://analytics.test/t/), so
// the browser never talks to the service domain. The tracker derives its
// endpoint from its own script src, so events go to /stats/e.
(function () {
  var params = new URLSearchParams(location.search);
  var key = params.get('an_key') || window.ANALYTICS_PUBLIC_KEY;
  if (!key) {
    document.documentElement.setAttribute('data-analytics-missing-key', '1');
    return;
  }
  window.analytics = window.analytics || {
    q: [],
    track: function () {
      this.q.push(['track'].concat([].slice.call(arguments)));
    }
  };
  var s = document.createElement('script');
  s.defer = true;
  var cb = params.get('an_cb');
  s.src = '/stats/' + key + '.js' + (cb ? '?cb=' + encodeURIComponent(cb) : '');
  document.head.appendChild(s);
  document.documentElement.setAttribute('data-analytics-key', key);
})();
