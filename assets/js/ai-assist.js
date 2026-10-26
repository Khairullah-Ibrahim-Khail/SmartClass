/**
 * SmartClass - AI writing helpers for teachers (buttons with data-ai-task)
 *   <button type="button" data-ai-task="announcement|assignment|lecture|feedback|translate|reply"
 *           data-target="#css selector"           the textarea / input that receives the draft
 *           data-scope="form" data-field="input[name=feedback]"   (instead of data-target: look inside the nearest scope)
 *           data-class="12" | data-class-source="#class_id"
 *           data-title-source="#title" data-summary-source="#summary" data-ctx-sources='{"kind":"#kind"}'  (other fields sent as context)
 *           data-prompt="What should it say?"     ask for an idea first (a dialog)
 *           data-use-target="1"                   send what is already in the field (feedback, translate)
 *           data-student="5" data-ctx="class" data-cid="0"   (reply)>
 * The answer is a draft put into the field with .value; the previous text is kept for "Undo".
 * Nothing is saved or sent until the teacher saves / sends the form themselves.
 */
(function () {
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
  const LANGS = { en: "English", ps: "Pashto", fa: "Dari", ar: "Arabic", ur: "Urdu" };
  const val = function (sel) { const e = sel && document.querySelector(sel); return e ? e.value : ""; };

  function field(btn) {
    if (btn.dataset.target) return document.querySelector(btn.dataset.target);
    const scope = btn.closest(btn.dataset.scope || "form");
    return scope ? scope.querySelector(btn.dataset.field) : null;
  }
  function note(btn, msg, isError, undo) {
    let n = btn.parentElement.querySelector(".ai-assist-note");
    if (!n) { n = document.createElement("span"); n.className = "ai-assist-note"; n.setAttribute("role", "status"); btn.parentElement.appendChild(n); }
    n.classList.toggle("is-error", !!isError);
    n.textContent = msg;
    if (undo) {
      const u = document.createElement("button"); u.type = "button"; u.className = "link-btn"; u.textContent = "Undo";
      u.addEventListener("click", function () { undo(); n.textContent = ""; });
      n.append(" ", u);
    }
  }
  function ask(btn, current) {
    if (btn.dataset.task === "translate" || btn.dataset.aiTask === "translate") {
      return Swal.fire({ title: "Translate into…", input: "select", inputOptions: LANGS, inputValue: "ps", showCancelButton: true, confirmButtonText: "Translate", confirmButtonColor: "#4361ee" })
        .then(function (r) { return r.isConfirmed ? { lang: r.value } : null; });
    }
    if (btn.dataset.prompt) {
      return Swal.fire({ title: btn.dataset.prompt, input: "textarea", inputValue: current || "", inputAttributes: { maxlength: 1500, "aria-label": btn.dataset.prompt },
                         showCancelButton: true, confirmButtonText: "Write it", confirmButtonColor: "#4361ee" })
        .then(function (r) { return r.isConfirmed ? { text: r.value || "" } : null; });
    }
    return Promise.resolve({});
  }

  // one listener at a time (the page can be re-entered without a reload)
  if (window.__aiAssistClick) document.removeEventListener("click", window.__aiAssistClick);
  window.__aiAssistClick = function (e) {
    const btn = e.target.closest("[data-ai-task]");
    if (!btn || btn.disabled) return;
    const target = field(btn);
    if (!target) return;
    const task = btn.dataset.aiTask;
    const before = target.value;
    ask(btn, btn.dataset.useTarget ? "" : (btn.dataset.titleSource ? "" : before)).then(function (extra) {
      if (!extra) return;
      const body = new URLSearchParams({ task: task, class_id: btn.dataset.class || val(btn.dataset.classSource) || "0" });
      body.set("text", extra.text !== undefined ? extra.text : (btn.dataset.useTarget ? before : ""));
      if (extra.lang) body.set("lang", extra.lang);
      if (btn.dataset.titleSource) body.set("title", val(btn.dataset.titleSource));
      if (btn.dataset.summarySource) body.set("summary", val(btn.dataset.summarySource));
      if (btn.dataset.ctxSources) { try { const m = JSON.parse(btn.dataset.ctxSources); Object.keys(m).forEach(function (k) { body.set("ctx[" + k + "]", val(m[k])); }); } catch (e) {} }   // other fields of the form the draft should fit (exam: kind, date, duration …)
      if (btn.dataset.student) { body.set("student_id", btn.dataset.student); body.set("c", btn.dataset.ctx || "class"); body.set("cid", btn.dataset.cid || "0"); }
      const label = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Writing…';
      note(btn, "", false);
      // live: the text appears while the AI writes it. A lecture is laid out as it arrives (headings, bold, colours) in the editor's Preview.
      const live = task === "lecture" && document.getElementById("lecPreview") && document.querySelector('.lec-tabs [data-tab="preview"]');
      const prevTab = live ? document.querySelector('.lec-tabs [data-tab="preview"]') : null;
      if (live) { prevTab.click(); document.getElementById("lecPreview").innerHTML = '<p class="form-hint"><i class="fas fa-spinner fa-spin"></i> The AI is writing the lecture…</p>'; }
      window.scAiStream(APP_AI_URL, body, {
        onText: function (t) {
          if (live) {
            const pv = document.getElementById("lecPreview"), atEnd = window.innerHeight + window.scrollY >= document.body.scrollHeight - 160;
            pv.innerHTML = window.scRenderAi(t, { lecture: true });
            if (atEnd) window.scrollTo(0, document.body.scrollHeight);
          } else { target.value = t; }
        },
        onDone: function (d) {
          target.value = d.text;
          target.dispatchEvent(new Event("input", { bubbles: true }));
          if (live) prevTab.click();   // the editor's own preview, exactly as students will see it
          note(btn, "AI draft — read and edit it before you save.", false, function () { target.value = before; target.dispatchEvent(new Event("input", { bubbles: true })); if (live) prevTab.click(); });
          btn.disabled = false; btn.innerHTML = label;
        },
        onError: function (m) {
          if (!live) target.value = before;
          note(btn, m || "The AI could not write that.", true);
          btn.disabled = false; btn.innerHTML = label;
        }
      });
    });
  };
  document.addEventListener("click", window.__aiAssistClick);
  const self = document.querySelector("script[src*='ai-assist.js']");
  const APP_AI_URL = self ? self.src.replace(/assets\/js\/ai-assist\.js.*$/, "pages/ai/assist.php") : "pages/ai/assist.php";
})();
