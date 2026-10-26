/**
 * SmartClass - "Run" on lecture code blocks. No AI. Four kinds (the Run button's data-lang):
 *   python / javascript   run inside the reader's own browser (below); the real output is shown under the block
 *   html / css            a live page in a sandboxed frame under the block (CSS is applied to the HTML block above it; JavaScript that uses the page
 *                         — document, window, querySelector … — runs on the HTML block above it, with that block's CSS)
 *   remote                C, C++, C#, Java, PHP, Go, Rust, SQL … are sent to the school's code runner (pages/lectures/run.php, Settings › Code runner)
 *   Python      Pyodide (CPython compiled to WebAssembly). Served from assets/vendor/pyodide/ on this server when it is there
 *               (works offline, nothing leaves the school); otherwise from cdn.jsdelivr.net. Loaded on the first Run.
 *   JavaScript  a plain worker
 * Safety: every run happens in a throw-away <iframe sandbox="allow-scripts"> (no cookies, no access to this site or its
 * pages) around a web worker; a run that takes too long is stopped by removing the iframe. Output is shown with textContent.
 * Code blocks are edited directly (click and type; Tab indents, Ctrl+Enter runs); changes stay in the page and are never saved.
 * Each run starts fresh — code does not see variables of other blocks. input() cannot be answered here.
 */
(function () {
  // ---------- Direct editing: click in the code and type. Changes stay in the page (never saved); Reset brings the lecture's code back ----------
  document.querySelectorAll(".code-block").forEach(function (block) {
    const reset = block.querySelector(".code-reset-btn");
    if (!reset) return;
    const pre = block.querySelector("pre"), code = pre.querySelector("code"), original = code.textContent;
    const ta = document.createElement("textarea");
    ta.className = "code-editor"; ta.value = original; ta.spellcheck = false; ta.rows = 1;
    ta.setAttribute("aria-label", "Code — you can edit it"); ta.setAttribute("autocapitalize", "off"); ta.setAttribute("autocomplete", "off");
    function size() { ta.style.height = "auto"; ta.style.height = Math.min(ta.scrollHeight + 2, 560) + "px"; }
    ta.addEventListener("input", function () { code.textContent = ta.value; reset.hidden = ta.value === original; size(); });   // Copy / Run always use what is shown
    ta.addEventListener("keydown", function (e) {
      if (e.key === "Tab" && !e.shiftKey) { e.preventDefault(); ta.setRangeText("    ", ta.selectionStart, ta.selectionEnd, "end"); ta.dispatchEvent(new Event("input")); }
      if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) { e.preventDefault(); const run = block.querySelector(".code-run-btn"); if (run && !run.disabled) run.click(); }
    });
    reset.addEventListener("click", function () { ta.value = original; code.textContent = original; reset.hidden = true; size(); });
    pre.hidden = true; pre.after(ta); size();
    window.addEventListener("resize", size);
  });


  // ---------- HTML / CSS / page-JavaScript: a live page in a sandboxed frame ----------
  const SAMPLE = '<h1>A heading</h1><h2>A smaller heading</h2><p>A paragraph with a <a href="#">link</a>, some <strong>bold</strong> and <em>italic</em> text.</p>' +
    '<button>A button</button><ul><li>First</li><li>Second</li><li>Third</li></ul><div class="box">A box</div>';
  // the preview frame is sandboxed (no cookies, no access to this site) and ALSO closed to the internet for scripts and data: only the page's own code runs; pictures, styles and fonts from https addresses are allowed
  const CSP = "default-src 'none'; img-src https: data: blob:; media-src https: data: blob:; style-src 'unsafe-inline' https:; font-src https: data:; script-src 'unsafe-inline'; connect-src 'none'; frame-src https://www.youtube.com https://www.youtube-nocookie.com; form-action 'none'";
  const HOOK = '(function(){function f(a){return [].map.call(a,function(x){if(typeof x==="string")return x;try{return JSON.stringify(x)}catch(e){return String(x)}}).join(" ")}' +
    '["log","info","debug"].forEach(function(k){console[k]=function(){parent.postMessage({t:"out",m:f(arguments)},"*")}});' +
    '["warn","error"].forEach(function(k){console[k]=function(){parent.postMessage({t:"err",m:f(arguments)},"*")}});' +
    'window.addEventListener("error",function(e){parent.postMessage({t:"err",m:e.message},"*")});' +
    'function h(){parent.postMessage({t:"height",h:document.documentElement.scrollHeight},"*")}window.addEventListener("load",h);new ResizeObserver(h).observe(document.documentElement)})();';
  function pageDoc(parts) {   // parts: { html, css, js }
    const head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="' + CSP + '">' +
      '<script>' + HOOK + '<\/script>';
    let html = parts.html || "";
    const style = parts.css ? "<style>" + parts.css.replace(/<\/style/gi, "<\\/style") + "</style>" : "";
    const script = parts.js ? "<script>" + parts.js.replace(/<\/script/gi, "<\\/script") + "<\/script>" : "";
    if (/<html[\s>]|<!doctype/i.test(html)) {   // a whole page: put our parts in its head / before its end
      html = /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, function (m) { return m + head; }) : html.replace(/<html[^>]*>/i, function (m) { return m + "<head>" + head + "</head>"; });
      html = html.replace(/<\/head>/i, style + "</head>");
      return script ? (/<\/body>/i.test(html) ? html.replace(/<\/body>/i, script + "</body>") : html + script) : html;
    }
    return "<!doctype html><html><head>" + head + '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:12px;line-height:1.5}</style>' + style + "</head><body>" + html + script + "</body></html>";
  }
  function codeOf(b) { return b.querySelector("pre code").textContent; }
  function blocksBefore(block, kind) {   // earlier code blocks of this lecture whose Run kind is `kind`, nearest first
    const all = Array.prototype.slice.call(document.querySelectorAll(".code-block")), at = all.indexOf(block), out = [];
    for (let i = at - 1; i >= 0; i--) { const r = all[i].querySelector(".code-run-btn"); if (r && r.dataset.lang === kind) out.push(all[i]); }
    return out;
  }
  const DOM_JS = /\bdocument\b|\bwindow\b|querySelector|getElementById|getElementsBy|addEventListener|\balert\s*\(|innerHTML|\.style\./;
  function previewParts(kind, block, code) {   // → { parts, note }
    if (kind === "html") return { parts: { html: code }, note: "" };
    const htmlB = blocksBefore(block, "html")[0];
    if (kind === "css") return htmlB ? { parts: { html: codeOf(htmlB), css: code }, note: "Applied to the HTML block above." } : { parts: { html: SAMPLE, css: code }, note: "There is no HTML block above, so the CSS is shown on a sample page." };
    const cssB = blocksBefore(block, "css")[0], parts = { html: htmlB ? codeOf(htmlB) : '<div id="app"></div>', css: cssB ? codeOf(cssB) : "", js: code };
    return { parts: parts, note: htmlB ? "Run on the HTML block above." : "There is no HTML block above — the code runs on an empty page." };
  }

  const buttons = document.querySelectorAll(".code-run-btn");
  if (!buttons.length) return;
  const CDN = "https://cdn.jsdelivr.net/pyodide/v0.26.4/full/";
  const meta = document.querySelector('meta[name="app-url"]');
  const LOCAL = meta ? new URL(meta.content.replace(/\/$/, "") + "/assets/vendor/pyodide.php/", location.href).href : "";
  let PYODIDE = CDN;                       // replaced by the copy on this server when it is there (works offline, no third party)
  const localCheck = LOCAL ? fetch(LOCAL + "pyodide.js", { method: "HEAD", credentials: "same-origin" }).then(function (r) { if (r.ok) PYODIDE = LOCAL; }, function () {}) : Promise.resolve();
  const LIMIT_MS = { python: 25000, javascript: 6000 };   // Python includes the first-time download

  const workerPython = function () { return 'importScripts(' + JSON.stringify(PYODIDE + "pyodide.js") + ');' +
    'onmessage=async function(e){try{postMessage({t:"status",m:"Loading Python (first run only)…"});' +
    'const py=await loadPyodide({indexURL:' + JSON.stringify(PYODIDE) + '});' +
    'py.setStdout({batched:function(s){postMessage({t:"out",m:s})}});py.setStderr({batched:function(s){postMessage({t:"err",m:s})}});' +
    'py.setStdin({stdin:function(){return undefined}});postMessage({t:"status",m:"Running…"});' +
    'await py.runPythonAsync(e.data.code);postMessage({t:"done"})}catch(err){postMessage({t:"error",m:String(err&&err.message||err)})}};'; };
  const workerJs = 'function fmt(a){return a.map(function(x){if(typeof x==="string")return x;try{return JSON.stringify(x)}catch(e){return String(x)}}).join(" ")}' +
    '["log","info","debug"].forEach(function(k){console[k]=function(){postMessage({t:"out",m:fmt([].slice.call(arguments))})}});' +
    '["warn","error"].forEach(function(k){console[k]=function(){postMessage({t:"err",m:fmt([].slice.call(arguments))})}});' +
    'onmessage=async function(e){try{await (0,eval)(e.data.code);postMessage({t:"done"})}catch(err){postMessage({t:"error",m:String(err&&err.stack||err)})}};';

  function frameHtml() {
    const relay = 'window.addEventListener("message",function(e){if(!e.data||e.data.t!=="run")return;' +
      'var src=e.data.lang==="python"?' + JSON.stringify(workerPython()) + ':' + JSON.stringify(workerJs) + ';' +
      'var w;try{w=new Worker(URL.createObjectURL(new Blob([src],{type:"text/javascript"})))}catch(err){parent.postMessage({t:"error",m:"This browser cannot run code here."},"*");return}' +
      'w.onmessage=function(x){parent.postMessage(x.data,"*")};w.onerror=function(x){parent.postMessage({t:"error",m:x.message||"The code could not be started."},"*")};' +
      'w.postMessage({code:e.data.code})});parent.postMessage({t:"ready"},"*");';
    return "<!doctype html><meta charset=utf-8><script>" + relay.replace(/<\/script/gi, "<\\/script") + "<\/script>";
  }

  buttons.forEach(function (btn) {
    const block = btn.closest(".code-block");
    const out = block.querySelector(".code-output");
    let lang = btn.dataset.lang;
    let frame = null, timer = null;
    const isPage = function (code) { return lang === "html" || lang === "css" || (lang === "javascript" && DOM_JS.test(code)); };

    // code pasted from a list or a document keeps its left indent: remove the part every line shares, like textwrap.dedent
    function dedent(src) {
      const ls = src.replace(/\t/g, "    ").split("\n");
      let min = Infinity;
      ls.forEach(function (l) { if (l.trim()) min = Math.min(min, l.match(/^ */)[0].length); });
      return min === Infinity || min === 0 ? src : ls.map(function (l) { return l.slice(Math.min(min, l.match(/^ */)[0].length)); }).join("\n");
    }
    function stop() { if (timer) { clearTimeout(timer); timer = null; } if (frame) { frame.remove(); frame = null; } window.removeEventListener("message", onMsg); }
    function idle() { stop(); btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Run'; }
    const started = [0];

    function render() {
      out.textContent = "";
      const head = document.createElement("div"); head.className = "code-output-head";
      const t = document.createElement("span"); t.innerHTML = '<i class="fas fa-terminal"></i> Output';
      const close = document.createElement("button"); close.type = "button"; close.className = "code-output-close"; close.setAttribute("aria-label", "Hide output"); close.innerHTML = '<i class="fas fa-times"></i>';
      close.addEventListener("click", function () { idle(); out.hidden = true; });
      head.append(t, close);
      const pre = document.createElement("pre"); const status = document.createElement("div"); status.className = "code-output-status";
      out.append(head, pre, status);
      return { pre: pre, status: status };
    }
    let ui = null;
    function line(kind, text) {
      const s = document.createElement("span"); if (kind === "err") s.className = "co-err"; s.textContent = text + "\n"; ui.pre.appendChild(s);
    }
    function trimTrace(m) { const ls = String(m).trim().split("\n"); return ls.length > 6 ? ls.slice(-4).join("\n") : ls.join("\n"); }
    function finish(note) { ui.status.textContent = note; idle(); }

    function onMsg(e) {
      if (!frame || e.source !== frame.contentWindow) return;
      const d = e.data || {};
      if (d.t === "height") { frame.style.height = Math.max(120, Math.min(640, d.h + 4)) + "px"; return; }
      if (frame.classList.contains("code-preview")) { if (d.t === "out") line("out", d.m); else if (d.t === "err") line("err", d.m); return; }
      if (d.t === "ready") { frame.contentWindow.postMessage({ t: "run", lang: lang, code: dedent(block.querySelector("pre code").textContent) }, "*"); return; }
      if (d.t === "status") ui.status.textContent = d.m;
      else if (d.t === "out") line("out", d.m);
      else if (d.t === "err") line("err", d.m);
      else if (d.t === "error") {
        let m = trimTrace(d.m);
        if (/EOF when reading a line/i.test(d.m)) m = "This code waits for typed input (input()), which cannot be answered here.";
        line("err", m); finish("Stopped with an error");
      } else if (d.t === "done") {
        if (!ui.pre.textContent) line("out", "(the code ran and printed nothing)");
        finish("Finished in " + ((Date.now() - started[0]) / 1000).toFixed(1) + " s");
      }
    }
    function runPage(code) {   // html / css / page-JavaScript
      const kind = lang === "javascript" ? "dom" : lang;
      const pv = previewParts(kind === "dom" ? "js" : kind, block, code);
      ui = render(); ui.status.textContent = pv.note;
      const head = out.querySelector(".code-output-head span"); head.innerHTML = '<i class="fas fa-eye"></i> ' + (lang === "javascript" ? "Result" : "Preview");
      const f = document.createElement("iframe");
      f.className = "code-preview"; f.setAttribute("sandbox", "allow-scripts"); f.setAttribute("title", "Live result of the code above"); f.srcdoc = pageDoc(pv.parts);
      ui.pre.before(f); frame = f;
      window.addEventListener("message", onMsg);
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate-right"></i> Run again';
    }
    function runRemote(code) {   // C, C++, Java, PHP, SQL … on the school's code runner
      const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
      const lid = (block.closest("[data-lecture]") || {}).dataset ? block.closest("[data-lecture]").dataset.lecture : "";
      ui = render(); ui.status.textContent = "Running on the code runner…";
      fetch("run.php", { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
                         body: new URLSearchParams({ lecture_id: lid, lang: btn.dataset.tag || "", code: code }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { line("err", d.error || "The code could not be run."); return finish("Not run"); }
          if (d.compile) line("err", d.compile.trim());
          if (d.stdout) line("out", d.stdout.replace(/\n$/, ""));
          if (d.stderr) line("err", d.stderr.replace(/\n$/, ""));
          if (!d.stdout && !d.stderr && !d.compile) line("out", "(the code ran and printed nothing)");
          finish(d.code ? "Finished with exit code " + d.code : "Finished in " + ((Date.now() - started[0]) / 1000).toFixed(1) + " s");
        })
        .catch(function () { line("err", "No connection. Try again."); finish("Not run"); });
    }
    btn.addEventListener("click", function () {
      stop();
      const src = dedent(codeOf(block));
      out.hidden = false; started[0] = Date.now();
      if (lang === "html" || lang === "css" || (lang === "javascript" && DOM_JS.test(src))) { runPage(src); return; }
      if (lang === "remote") { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running…'; runRemote(src); return; }
      btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running…';
      out.hidden = false; ui = render(); ui.status.textContent = lang === "python" ? "Starting Python…" : "Running…";
      started[0] = Date.now();
      const mine = frame = document.createElement("iframe");
      frame.setAttribute("sandbox", "allow-scripts"); frame.setAttribute("aria-hidden", "true"); frame.tabIndex = -1; frame.style.display = "none";
      window.addEventListener("message", onMsg);
      localCheck.then(function () {   // know first whether the local copy of Python exists
        if (frame !== mine) return;     // stopped meanwhile
        frame.srcdoc = frameHtml();
        document.body.appendChild(frame);
      });
      timer = setTimeout(function () { line("err", "Stopped: the code ran for too long (" + LIMIT_MS[lang] / 1000 + " s) — check for a loop that never ends."); finish("Stopped"); }, LIMIT_MS[lang]);
    });
  });
})();
