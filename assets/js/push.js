/* Settings › Notifications › Phone notifications: turn web push on / off on THIS device, and send a test.
 * #pushBox carries data-key (the school's VAPID public key), data-url (pages/notifications/push.php) and data-csrf. */
(function () {
  var box = document.getElementById('pushBox'); if (!box) return;
  var status = box.querySelector('[data-push-status]'), on = box.querySelector('[data-push-on]'), off = box.querySelector('[data-push-off]'), test = box.querySelector('[data-push-test]');
  function say(t, tone) { status.textContent = t; status.className = 'push-status ' + (tone || ''); }
  function show(state) { on.hidden = state !== 'off'; off.hidden = state !== 'on'; test.hidden = state !== 'on'; }
  function key(b64) { var p = '='.repeat((4 - b64.length % 4) % 4), raw = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(raw.length); for (var i = 0; i < raw.length; i++) a[i] = raw.charCodeAt(i); return a; }
  function post(action, endpoint) {
    var body = new URLSearchParams({ action: action, endpoint: endpoint || '' });
    return fetch(box.dataset.url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': box.dataset.csrf }, body: body }).then(function (r) { return r.json(); });
  }
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    say(box.dataset.unsupported, 'warn'); show('none'); return;
  }
  function current() { return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }); }
  function refresh() {
    if (Notification.permission === 'denied') { say(box.dataset.denied, 'warn'); show('none'); return Promise.resolve(); }
    return current().then(function (sub) { if (sub) { say(box.dataset.isOn, 'ok'); show('on'); } else { say(box.dataset.isOff, ''); show('off'); } });
  }
  on.addEventListener('click', function () {
    on.disabled = true;
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { say(box.dataset.denied, 'warn'); return; }
      return navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(box.dataset.key) });
      }).then(function (sub) { return post('subscribe', sub.endpoint); }).then(function (r) { if (!r.ok) throw new Error(r.message); });
    }).then(refresh).catch(function (e) { say((e && e.message) || box.dataset.failed, 'warn'); }).then(function () { on.disabled = false; });
  });
  off.addEventListener('click', function () {
    current().then(function (sub) { if (!sub) return; var ep = sub.endpoint; return sub.unsubscribe().then(function () { return post('unsubscribe', ep); }); }).then(refresh);
  });
  test.addEventListener('click', function () {
    test.disabled = true;
    post('test').then(function (r) { say(r.message, r.ok ? 'ok' : 'warn'); }).catch(function () { say(box.dataset.failed, 'warn'); }).then(function () { test.disabled = false; });
  });
  refresh();
})();
