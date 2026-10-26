/**
 * SmartClass - live AI answers
 *   scAiStream(url, params, { onText(textSoFar), onDone(data), onError(message) }) → Promise
 *       POSTs the form fields (plus stream=1) and reads the server-sent events: {"t": piece} while the AI writes, then {"done": true, ...} or {"error": "..."}.
 *       An answer that is not an event stream (a refusal, a saved answer) is plain JSON and is handled the same way.
 *   scRenderAi(text, { lecture: true }) → safe HTML of the writing markup the lecture page understands:
 *       ## headings, **bold**, *italic*, `code`, {blue}colours{/blue}, {mark}highlight{/mark}, - bullets, 1. steps, > tip boxes, --- lines, ``` code ``` (also while the
 *       closing ``` has not arrived yet). Everything is escaped first — nothing the AI sends can become HTML.
 */
(function () {
  const COLORS = ["red", "orange", "green", "blue", "purple", "teal", "gray", "mark"];
  const esc = function (s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); };

  function inline(raw) {
    const parts = String(raw).split(/(`[^`\n]+`)/);
    let html = parts.map(function (p, i) {
      if (i % 2 === 1) return "<code>" + esc(p.slice(1, -1)) + "</code>";
      let t = esc(p);
      t = t.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, "<strong>$1</strong>");
      t = t.replace(/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/g, "<em>$1</em>");
      return t;
    }).join("");
    // colours: only when every {colour} on the line is closed (a half-written one stays invisible until its end arrives)
    COLORS.forEach(function (c) {
      const open = new RegExp("\\{" + c + "\\}", "g"), close = new RegExp("\\{/" + c + "\\}", "g");
      const no = (html.match(open) || []).length, nc = (html.match(close) || []).length;
      if (no && no === nc) html = html.replace(open, '<span class="lc-' + c + '">').replace(close, "</span>");
      else html = html.replace(open, "").replace(close, "");
    });
    return html.replace(/\{\/?(?:red|orange|green|blue|purple|teal|gray|mark)\}?$/, "");
  }

  // the drawing language of a fenced block (same rules as lectureChartKind() on the server)
  function chartKind(lang, src) {
    const l = (lang || "").toLowerCase();
    if (l === "mermaid" || l === "mmd") return "mermaid";
    if (["dot", "graphviz", "gv"].indexOf(l) !== -1) return "dot";
    const isJson = function () { try { return JSON.parse(src) && typeof JSON.parse(src) === "object"; } catch (e) { return false; } };
    if (["vega", "vega-lite", "vegalite", "vl"].indexOf(l) !== -1) return isJson() ? "vega" : null;
    if (["chart", "chartjs", "chart.js"].indexOf(l) !== -1) return isJson() ? "chart" : null;
    if (l !== "" && ["text", "txt", "diagram", "plaintext", "flowchart"].indexOf(l) === -1) return null;
    const first = src.trim();
    if (/^(?:graph|flowchart)\s+(?:TD|TB|BT|RL|LR)\b|^(?:sequenceDiagram|classDiagram|stateDiagram|erDiagram|gantt|journey|gitGraph|mindmap|timeline|quadrantChart)\b|^pie\b/.test(first)) return "mermaid";
    if (/^(?:strict\s+)?(?:di)?graph\b[^{]*\{/i.test(first)) return "dot";
    return null;
  }

  function render(text, opts) {
    opts = opts || {};
    const top = opts.lecture ? 2 : 4;   // heading level: a lecture has h2 / h3, a chat answer small h4 / h5
    const lines = String(text).replace(/\r\n?/g, "\n").split("\n");
    let out = "", i = 0;
    while (i < lines.length) {
      const line = lines[i], t = line.trim();
      let m;
      if ((m = t.match(/^```\s*([A-Za-z0-9+#._-]{0,20})\s*$/))) {
        const buf = []; let closed = false;
        for (i++; i < lines.length; i++) { if (/^```\s*$/.test(lines[i].trim())) { closed = true; break; } buf.push(lines[i]); }
        const lang = m[1].toLowerCase(); i++;
        const code = esc(buf.join("\n"));
        const ck = closed ? chartKind(lang, buf.join("\n")) : null;
        if (ck) { out += '<figure class="lec-diagram" data-kind="' + ck + '"><pre class="mermaid-src">' + code + "</pre></figure>"; continue; }   // drawn by diagram.js once the closing fence has arrived
        out += lang === "output"
          ? '<figure class="code-out"><div class="code-head"><span class="code-lang"><i class="fas fa-terminal"></i> Output</span></div><pre>' + code + "</pre></figure>"
          : '<figure class="code-block"><div class="code-head"><span class="code-lang">' + esc(lang || "code") + "</span></div><pre><code>" + code + "</code></pre></figure>";
        continue;
      }
      if (t === "") { i++; continue; }
      if ((m = t.match(/^(#{1,3})\s+(.+)$/))) { const lv = Math.min(top + Math.max(0, m[1].length - 2), 6); out += "<h" + lv + ">" + inline(m[2]) + "</h" + lv + ">"; i++; continue; }
      if (/^(-{3,}|\*{3,})$/.test(t)) { out += "<hr>"; i++; continue; }
      if (/^>\s?/.test(t)) {
        const q = []; for (; i < lines.length && /^>\s?/.test(lines[i].trim()); i++) q.push(inline(lines[i].trim().replace(/^>\s?/, "")));
        out += "<blockquote>" + q.join("<br>") + "</blockquote>"; continue;
      }
      if (/^[-*+]\s+/.test(t) || /^\d{1,3}[.)]\s+/.test(t)) {
        const ordered = /^\d/.test(t), re = ordered ? /^\d{1,3}[.)]\s+(.*)$/ : /^[-*+]\s+(.*)$/, items = [];
        for (; i < lines.length && re.test(lines[i].trim()); i++) items.push("<li>" + inline(lines[i].trim().match(re)[1]) + "</li>");
        out += (ordered ? "<ol>" : "<ul>") + items.join("") + (ordered ? "</ol>" : "</ul>"); continue;
      }
      const para = [];
      for (; i < lines.length && lines[i].trim() !== "" && !/^(#{1,3}\s|```|>\s?|[-*+]\s|\d{1,3}[.)]\s|-{3,}$|\*{3,}$)/.test(lines[i].trim()); i++) para.push(inline(lines[i].trim()));
      out += "<p>" + para.join("<br>") + "</p>";
    }
    return out;
  }
  window.scRenderAi = render;

  // ---------- reading the event stream ----------
  window.scAiStream = function (url, params, h) {
    h = h || {};
    const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
    const body = new URLSearchParams(params); body.set("stream", "1");
    let text = "", finished = false;
    const done = function (d) { if (finished) return; finished = true; if (h.onDone) h.onDone(d, text); };
    const fail = function (m) { if (finished) return; finished = true; if (h.onError) h.onError(m || "The AI could not answer.", text); };
    return fetch(url, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded", Accept: "text/event-stream, application/json" }, body: body })
      .then(function (r) {
        const type = r.headers.get("Content-Type") || "";
        if (type.indexOf("text/event-stream") === -1) {   // not live: a refusal or a saved answer
          return r.json().then(function (d) { if (d.ok === false || d.error) fail(d.error); else done(d); });
        }
        const reader = r.body.getReader(), dec = new TextDecoder("utf-8"); let buf = "";
        const handle = function (payload) {
          let d; try { d = JSON.parse(payload); } catch (e) { return; }
          if (d.t !== undefined) { text += d.t; if (h.onText) h.onText(text); }
          else if (d.error) fail(d.error);
          else if (d.done) done(d);
        };
        const pump = function () {
          return reader.read().then(function (x) {
            if (x.done) { if (!finished) { text ? done({ text: text }) : fail("The answer was cut off. Try again."); } return; }
            buf += dec.decode(x.value, { stream: true });
            let nl;
            while ((nl = buf.indexOf("\n")) !== -1) {
              const line = buf.slice(0, nl).replace(/\r$/, ""); buf = buf.slice(nl + 1);
              if (line.indexOf("data:") === 0) handle(line.slice(5).trim());
            }
            return pump();
          });
        };
        return pump();
      })
      .catch(function () { fail("No connection. Try again."); });
  };
})();
