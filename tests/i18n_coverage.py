#!/usr/bin/env python3
"""
Lists interface text still in English when a page is shown in Pashto (developer tool, run against STAGING):
  python3 tests/i18n_coverage.py BASE_URL EMAIL PASSWORD [--max 120] [--lang ps] [--seeds file]
Crawls the pages that person can reach (menu, classes and their sections, first items of lists) and prints each distinct English text
node / attribute left on the pages, with how many pages it appears on. People's names and class titles written by users show up too — ignore those.
"""
import re, sys, html, urllib.request, urllib.parse, http.cookiejar
base, email, password = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
limit = int(sys.argv[sys.argv.index('--max') + 1]) if '--max' in sys.argv else 120
lang = sys.argv[sys.argv.index('--lang') + 1] if '--lang' in sys.argv else 'ps'
jar = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def get(u): return op.open(u, timeout=30).read().decode('utf8', 'replace')
tok = re.search(r'name="csrf_token" value="([^"]+)"', get(base + '/pages/auth/login.php')).group(1)
op.open(urllib.request.Request(base + '/pages/auth/login.php', urllib.parse.urlencode({'csrf_token': tok, 'email': email, 'password': password}).encode()), timeout=30)
SKIP = re.compile(r'(open\.php|logout|delete|remove|download|export|print|image\.php|photo|\.pdf|\.csv|\.xlsx|/auth/|javascript:|#|mailto:|tel:|reset|restore|backup\.php|customers|lang=)', re.I)
def links(h):
    out = []
    for m in re.finditer(r'href="([^"]+)"', h):
        l = html.unescape(m.group(1))
        if l.startswith('/'): l = urllib.parse.urljoin(base + '/', l.lstrip('/'))
        elif not l.startswith('http'): continue
        if l.startswith(base + '/pages/') and not SKIP.search(l): out.append(l)
    return out
def page(u): return get(u + ('&' if '?' in u else '?') + 'lang=' + lang)
seen, queue, found = [], [], {}
def add(u):
    if u not in seen and len(seen) < limit: seen.append(u); queue.append(u)
add(base + '/pages/dashboard.php')
if '--seeds' in sys.argv:   # extra start pages, one path per line in the given file
    for line in open(sys.argv[sys.argv.index('--seeds') + 1]).read().split():
        add(base + '/' + line.lstrip('/'))
shape = {}
while queue:
    u = queue.pop(0)
    try: h = page(u)
    except Exception as e: print('ERR', u, e); continue
    body = re.sub(r'(?is)<(script|style|textarea|pre|code)\b.*?</\1>', '', h)
    body = re.sub(r'(?s)<!--.*?-->', '', body)
    texts = [m for m in re.findall(r'>([^<>]+)<', body)]
    texts += re.findall(r'(?:placeholder|title|aria-label|alt)="([^"]+)"', body)
    for t in texts:
        t = html.unescape(t).strip()
        if len(t) < 2 or not re.search(r'[A-Za-z]{2}', t) or re.search(r'[؀-ۿ]', t): continue
        found.setdefault(t, set()).add(u.replace(base, ''))
    for l in links(h):   # keep the crawl varied: at most 3 pages per script
        key = re.sub(r'\?.*', '', l)
        shape[key] = shape.get(key, 0) + 1
        if shape[key] <= 3: add(l)
for t, us in sorted(found.items(), key=lambda x: -len(x[1])): print(f'{len(us):3} | {t[:140]}')
print(f'\n{len(seen)} pages, {len(found)} distinct English texts')
