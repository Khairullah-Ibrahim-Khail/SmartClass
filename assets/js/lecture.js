/**
 * SmartClass - lecture page (pages/lectures/view.php)
 *   .code-copy           copy a code block
 *   .code-explain-btn    "Explain this code" — POST explain.php (AI, cached per block), shows the
 *                        explanation (server-rendered, escaped HTML) under the block; click again to hide
 *   #study               AI chat card in the right sidebar (students): Summarize / Key terms / Practice quiz /
 *                        questions (the AI Tutor chat of this lecture) — all answers appear inside the card
 *   .lec-toc             highlight the section being read
 */
(function () {
  const article = document.querySelector(".lec-main");
  if (!article) return;
  const lectureId = article.dataset.lecture;
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";

  document.querySelectorAll(".code-block").forEach(function (block) {
    const code = block.querySelector("pre code");
    const copy = block.querySelector(".code-copy");
    if (copy) copy.addEventListener("click", function () {
      navigator.clipboard.writeText(code.textContent).then(function () {
        copy.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function () { copy.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 1500);
      });
    });

    const btn = block.querySelector(".code-explain-btn");
    const box = block.querySelector(".code-explain");
    if (!btn || !box) return;
    btn.addEventListener("click", function () {
      if (box.dataset.loaded === "1") {   // toggle an explanation already shown
        box.hidden = !box.hidden;
        btn.setAttribute("aria-expanded", box.hidden ? "false" : "true");
        return;
      }
      btn.disabled = true;
      const label = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Explaining…';
      box.hidden = false;
      box.innerHTML = '<p class="code-explain-wait">The AI is reading this code…</p>';
      const head = '<div class="code-explain-head"><i class="fas fa-robot"></i> AI explanation</div>';
      const finish = function () { btn.disabled = false; btn.innerHTML = label; };
      window.scAiStream("explain.php", { lecture_id: lectureId, hash: block.dataset.hash }, {
        onText: function (t) { box.innerHTML = head + '<div class="ai-rich">' + window.scRenderAi(t) + "</div>"; },   // written live, laid out as it arrives
        onDone: function (d) {
          box.innerHTML = head + '<div class="ai-rich">' + d.html + "</div>"
            + '<p class="code-explain-note">AI can make mistakes — ask your teacher if something does not make sense.</p>';   // d.html = the same text laid out by the server
          box.dataset.loaded = "1";
          btn.setAttribute("aria-expanded", "true");
          if (window.scAiReport) window.scAiReport(box.querySelector(".code-explain-note") || box, "explain_code", (document.getElementById("study") || {dataset: {}}).dataset.class || "");
          finish();
        },
        onError: function (m) {
          box.innerHTML = "";
          const p = document.createElement("p");
          p.className = "code-explain-error";
          p.textContent = m || "Could not explain this code.";
          box.appendChild(p);
          finish();
        }
      });
    });
  });

  // Contents: highlight the heading in view
  const links = document.querySelectorAll(".lec-toc a");
  if (links.length && "IntersectionObserver" in window) {
    const map = {};
    links.forEach(function (a) { map[a.getAttribute("href").slice(1)] = a; });
    const obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          links.forEach(function (a) { a.classList.remove("active"); });
          const a = map[en.target.id];
          if (a) a.classList.add("active");
        }
      });
    }, { rootMargin: "-80px 0px -70% 0px" });
    document.querySelectorAll(".lec-body h2[id], .lec-body h3[id], .lec-body h4[id]").forEach(function (h) { obs.observe(h); });
  }

  // ---------- AI chat card (right sidebar) ----------
  const card = document.getElementById("study");
  if (card) {
    const log = document.getElementById("studyLog"), form = document.getElementById("studyForm"), text = document.getElementById("studyText");
    const classId = card.dataset.class, tutorUrl = card.dataset.tutor, key = "aiChat_" + lectureId;
    let chatId = 0, busy = false;
    try { chatId = +sessionStorage.getItem(key) || 0; } catch (e) {}
    const post = function (url, data) {
      return fetch(url, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf, "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(data) })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: "Something went wrong. Try again." }; }); });
    };
    function bubble(kind, content) {
      const d = document.createElement("div"); d.className = "st-b " + kind;
      if (typeof content === "string") d.textContent = content; else if (content) d.appendChild(content);
      log.appendChild(d); log.scrollTop = log.scrollHeight;   // only the card's own box scrolls, never the page
      return d;
    }
    const fail = function (wait, msg) { wait.className = "st-b ai error"; wait.textContent = msg; };
    function lock(on) { busy = on; card.querySelectorAll("[data-study], #studySend").forEach(function (b) { b.disabled = on; }); }

    function study(mode, label) {
      if (busy) return;
      bubble("me", label);
      const wait = bubble("ai pending", mode === "practice" ? "The AI is writing practice questions…" : "The AI is reading the lecture…");
      lock(true);
      if (mode === "practice") {   // a quiz arrives whole (it is data, not text)
        post("study.php", { lecture_id: lectureId, mode: mode }).then(function (d) {
          if (!d.ok) return fail(wait, d.error || "The AI could not answer.");
          practice(wait, d.questions);
        }).catch(function () { fail(wait, "No connection. Try again."); }).then(function () { lock(false); log.scrollTop = log.scrollHeight; });
        return;
      }
      live(wait, "study.php", { lecture_id: lectureId, mode: mode }, "study", function (d) { return d.text; });
    }
    // A text answer written live into its bubble and laid out (bold, colours, lists, code) as it arrives
    function live(wait, url, params, reportKind, pick, after) {
      window.scAiStream(url, params, {
        onText: function (t) { wait.className = "st-b ai ai-rich"; wait.innerHTML = window.scRenderAi(t); log.scrollTop = log.scrollHeight; },
        onDone: function (d) {
          wait.className = "st-b ai ai-rich"; wait.innerHTML = window.scRenderAi(pick(d));
          if (after) after(d);
          if (window.scAiReport && !d.safeguarded) window.scAiReport(wait, reportKind, classId);
          lock(false); log.scrollTop = log.scrollHeight;
        },
        onError: function (m) { if (params.text) text.value = params.text; fail(wait, m || "The AI could not answer."); lock(false); }
      });
    }
    // Practice quiz: questions shown with textContent, answers checked in the browser, nothing is saved
    function practice(wait, questions) {
      wait.className = "st-b ai"; wait.textContent = "";
      const h = document.createElement("strong"); h.textContent = "Practice quiz — tap an answer"; wait.appendChild(h);
      let right = 0, done = 0;
      const score = document.createElement("div"); score.className = "practice-score";
      questions.forEach(function (q, qi) {
        const box = document.createElement("div"); box.className = "practice-q";
        const t = document.createElement("div"); t.className = "practice-text"; t.textContent = (qi + 1) + ". " + q.text; box.appendChild(t);
        q.options.forEach(function (o, oi) {
          const ob = document.createElement("button"); ob.type = "button"; ob.className = "practice-opt"; ob.textContent = o;
          ob.addEventListener("click", function () {
            if (box.dataset.done) return;
            box.dataset.done = "1"; done++;
            box.querySelectorAll(".practice-opt").forEach(function (x, xi) { x.disabled = true; if (xi === q.correct) x.classList.add("is-correct"); });
            if (oi === q.correct) right++; else ob.classList.add("is-wrong");
            if (done === questions.length) score.textContent = "You got " + right + " of " + questions.length + " right.";
          });
          box.appendChild(ob);
        });
        wait.appendChild(box);
      });
      wait.appendChild(score);
    }
    function ask(q) {
      q = (q || "").trim(); if (!q || busy) return;
      bubble("me", q); text.value = ""; grow();
      const wait = bubble("ai pending", "Thinking…"); lock(true);
      live(wait, tutorUrl, { ajax: "ask", class_id: classId, chat_id: chatId, lecture_id: lectureId, text: q }, "tutor", function (d) { return d.reply; },
        function (d) { chatId = d.chat_id; try { sessionStorage.setItem(key, String(chatId)); } catch (e) {} });
    }
    function grow() { text.style.height = "auto"; text.style.height = Math.min(text.scrollHeight + 2, 120) + "px"; }

    card.querySelectorAll("[data-study]").forEach(function (b) { b.addEventListener("click", function () { study(b.dataset.study, b.textContent.trim()); }); });
    form.addEventListener("submit", function (e) { e.preventDefault(); ask(text.value); });
    text.addEventListener("input", grow);
    text.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(text.value); } });
  }
})();
