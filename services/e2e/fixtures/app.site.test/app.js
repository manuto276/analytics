// Minimal SPA: client-side routing with history.pushState and a form that would
// post to the customer backend. The tracker must report one pageview per route
// change and keep the same visitor id as www.site.test (cookie on .site.test).
(function () {
  var routes = {
    '/': 'Dashboard',
    '/projects': 'Projects',
    '/settings': 'Settings',
    '/checkout': 'Checkout'
  };

  function render() {
    var path = location.pathname;
    document.getElementById('view').textContent = routes[path] || 'Not found: ' + path;
    document.title = 'App — ' + (routes[path] || 'unknown');
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[data-route]');
    if (!link) return;
    event.preventDefault();
    history.pushState({}, '', link.getAttribute('href'));
    render();
  });

  window.addEventListener('popstate', render);

  var form = document.getElementById('order');
  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      // The real conversion is sent server-side by the customer backend
      // (POST /api/v1/server/.../conversions with the an_vid value below).
      // The fixture only records that the form was submitted; see README.md.
      document.getElementById('order-result').textContent =
        'submitted visitor=' + (form.elements.visitor.value || '(none)');
      if (window.analytics && window.analytics.track) {
        window.analytics.track('order_submitted', { plan: 'pro' });
      }
    });
  }

  render();
})();
