// Loads the tracker for the fixture site. No inline script anywhere, so the
// same snippet also works on the strict-CSP page.
//
// The public key comes from /site-key.js (written per run by the e2e setup) or
// from `?an_key=pk_…`, which lets one test point the fixtures at the site it
// created without touching global state.
(function () {
  var params = new URLSearchParams(location.search);
  var key = params.get('an_key') || window.ANALYTICS_PUBLIC_KEY;
  var url = params.get('an_url') || window.ANALYTICS_SERVICE_URL || 'https://analytics.test';
  if (!key) {
    document.documentElement.setAttribute('data-analytics-missing-key', '1');
    return;
  }
  // Stub so pages can call analytics.track() before the bundle arrives.
  window.analytics = window.analytics || {
    q: [],
    track: function () {
      this.q.push(['track'].concat([].slice.call(arguments)));
    }
  };
  var s = document.createElement('script');
  s.defer = true;
  // `an_cb` busts the browser cache of the tracker bundle, so a test can see a
  // freshly published consent configuration without waiting for max-age.
  var cb = params.get('an_cb');
  s.src = url + '/t/' + key + '.js' + (cb ? '?cb=' + encodeURIComponent(cb) : '');
  document.head.appendChild(s);
  document.documentElement.setAttribute('data-analytics-key', key);
})();
