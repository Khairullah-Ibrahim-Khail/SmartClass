#!/usr/bin/env python3
"""
Browser scan of the app (developer tool, needs chromium + `pip install websockets`):
  python3 tests/browser_scan.py BASE_URL EMAIL PASSWORD [--width 390] [--max 80] [--theme light|dark]
Signs in as one person, finds the pages that person can reach (menu, classes and their sections, first items of every list), opens each in a
real browser at the given width and reports, per page: sideways scroll (content wider than the screen), tap targets smaller than 36 px,
JavaScript errors and broken images. Run it against the STAGING copy (sh bin/staging.sh), never against live data you care about.
"""
import asyncio, json, re, subprocess, sys, tempfile, time, urllib.request, urllib.parse, http.cookiejar
import websockets

base, email, password = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
width = int(sys.argv[sys.argv.index('--width') + 1]) if '--width' in sys.argv else 390
limit = int(sys.argv[sys.argv.index('--max') + 1]) if '--max' in sys.argv else 80
theme = sys.argv[sys.argv.index('--theme') + 1] if '--theme' in sys.argv else 'light'
PORT = 9411

jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def get(u):
    return op.open(u, timeout=30).read().decode('utf8', 'replace')
html = get(base + '/pages/auth/login.php')
tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
op.open(urllib.request.Request(base + '/pages/auth/login.php', urllib.parse.urlencode({'csrf_token': tok, 'email': email, 'password': password}).encode()), timeout=30)
dash = get(base + '/pages/dashboard.php')
if 'Login' in dash[:3000] and 'csrf_token' in dash: sys.exit('sign-in failed')

SKIP = re.compile(r'(open\.php|logout|delete|remove|download|export|print|image\.php|photo|\.pdf|\.csv|\.xlsx|/auth/|javascript:|#|mailto:|tel:|reset|restore|backup\.php|customers)', re.I)
def links(page_html):
    out = []
    for m in re.finditer(r'href="([^"]+)"', page_html):
        h = m.group(1).replace('&amp;', '&')
        if h.startswith('/'): h = urllib.parse.urljoin(base + '/', h.lstrip('/'))
        if not h.startswith(base + '/pages/') or SKIP.search(h): continue
        out.append(h)
    return out
seen, queue = [], []
def add(u):
    if u not in seen and len(seen) < limit: seen.append(u); queue.append(u)
for l in links(dash): add(l)
i = 0
while queue and len(seen) < limit and i < 400:
    u = queue.pop(0); i += 1
    try: page = get(u)
    except Exception: continue
    for l in links(page):
        if re.search(r'(view|my-classes|stream|classwork|people|grades|attendance|calendar|insights|messages|index)\.php', l): add(l)
        elif re.search(r'(thread|lecture|assignment|quiz|exam|research)\w*\.php\?', l) and sum(1 for s in seen if s.split('?')[0] == l.split('?')[0]) < 2: add(l)
pages = seen
print(f'{email}: {len(pages)} pages to scan at {width}px ({theme})')

JS = r"""(() => {
  const out = {over: [], small: [], broken: []};
  const vw = document.documentElement.clientWidth;
  if (document.documentElement.scrollWidth > vw + 1) {
    const els = [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > vw + 1 && getComputedStyle(e).position !== 'fixed' && !e.closest('.table-wrap,.rd-scroll,pre,.gradebook-wrap,[style*="overflow"]'); });
    out.over = els.slice(0, 4).map(e => e.tagName.toLowerCase() + '.' + String(e.className).trim().split(/\s+/).slice(0, 2).join('.') + ' right=' + Math.round(e.getBoundingClientRect().right));
    out.sw = document.documentElement.scrollWidth;
  }
  document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button]').forEach(e => {
    const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
    if (!r.width || !r.height || s.visibility === 'hidden' || s.display === 'none' || e.disabled) return;
    if (e.closest('.sidebar') && !document.querySelector('.sidebar.active, body.sidebar-open')) return;
    if (e.tagName === 'A' && e.closest('p, li, .form-hint, small, td, .notif-meta, footer') && !e.className) return;   // inline text links
    if (e.type === 'checkbox' || e.type === 'radio') return;
    if (r.height < 34 || (r.width < 34 && r.height < 40)) out.small.push((e.tagName.toLowerCase() + '.' + String(e.className).trim().split(/\s+/).slice(0, 2).join('.') + ' ' + Math.round(r.width) + 'x' + Math.round(r.height) + ' "' + (e.textContent || e.getAttribute('aria-label') || e.title || '').trim().slice(0, 18) + '"'));
  });
  document.querySelectorAll('img').forEach(i => { if (i.complete && i.naturalWidth === 0 && i.src && !i.src.startsWith('data:')) out.broken.push(i.src.slice(-50)); });
  return JSON.stringify(out);
})()"""

async def main():
    prof = tempfile.mkdtemp()
    pr = subprocess.Popen(['chromium', '--headless=new', f'--remote-debugging-port={PORT}', f'--user-data-dir={prof}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(60):
            try: ws_url = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json')) if t['type'] == 'page'][0]['webSocketDebuggerUrl']; break
            except Exception: time.sleep(0.2)
        async with websockets.connect(ws_url, max_size=100_000_000) as ws:
            n = 0; errors = []
            async def cmd(m, **p):
                nonlocal n; n += 1
                await ws.send(json.dumps({'id': n, 'method': m, 'params': p}))
                while True:
                    r = json.loads(await ws.recv())
                    if 'method' in r:
                        if r['method'] == 'Runtime.exceptionThrown': errors.append(r['params']['exceptionDetails'].get('exception', {}).get('description', r['params']['exceptionDetails'].get('text', ''))[:140])
                        continue
                    if r.get('id') == n: return r.get('result', {})
            await cmd('Page.enable'); await cmd('Runtime.enable')
            host = urllib.parse.urlparse(base).hostname
            for c in jar: await cmd('Network.setCookie', name=c.name, value=c.value, domain=host, path='/')
            await cmd('Emulation.setDeviceMetricsOverride', width=width, height=800, deviceScaleFactor=2, mobile=width < 700)
            bad = 0
            for u in pages:
                errors.clear()
                await cmd('Page.navigate', url=u); await asyncio.sleep(1.6)
                await cmd('Runtime.evaluate', expression=f"document.documentElement.setAttribute('data-theme','{theme}')"); await asyncio.sleep(0.3)
                r = await cmd('Runtime.evaluate', expression=JS, returnByValue=True)
                try: d = json.loads(r['result']['value'])
                except Exception: print('  ?', u); continue
                short = u.replace(base + '/pages/', '')
                notes = []
                if d['over']: notes.append(f"SIDEWAYS SCROLL (content {d.get('sw')}px > {width}px): " + '; '.join(d['over']))
                if len(d['small']) > 0: notes.append(f"{len(d['small'])} small tap targets: " + '; '.join(d['small'][:5]))
                if d['broken']: notes.append('broken images: ' + ', '.join(d['broken'][:3]))
                if errors: notes.append('JS errors: ' + ' | '.join(sorted(set(errors))[:3]))
                if notes: bad += 1; print('✗', short); [print('    -', x) for x in notes]
            print(f'{len(pages) - bad} of {len(pages)} pages clean')
    finally: pr.terminate()
asyncio.run(main())
