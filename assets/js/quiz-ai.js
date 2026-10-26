/**
 * SmartClass - AI on the quiz page (pages/quizzes/view.php)
 *   #explainMistakes   student: POST explain.php → one explanation under each wrong question
 *   #insightBtn        teacher: POST insights.php → class summary (also shows a saved one on load)
 * Everything the AI wrote is put in with textContent (never as HTML).
 */
(function () {
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
  function post(url, data) {
    return fetch(url, { method: "POST", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(data) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.error || "The AI could not answer."); return d; });
  }
  function status(el, msg, isError) {
    el.hidden = !msg; el.textContent = msg || "";
    el.classList.toggle("is-error", !!isError);
  }

  const ex = document.getElementById("explainMistakes");
  if (ex) {
    const st = document.getElementById("explainStatus");
    ex.addEventListener("click", function () {
      const label = ex.innerHTML;
      ex.disabled = true; ex.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Explaining…';
      status(st, "The AI is looking at the questions you missed…");
      post("explain.php", { quiz_id: ex.dataset.quiz })
        .then(function (d) {
          let shown = 0;
          Object.keys(d.items).forEach(function (qid) {
            const block = document.querySelector('.question-block[data-qid="' + qid + '"] .mistake-explain');
            if (!block) return;
            block.innerHTML = "";
            const h = document.createElement("div"); h.className = "mistake-head";
            h.innerHTML = '<i class="fas fa-robot"></i> Why';
            const p = document.createElement("p"); p.textContent = d.items[qid];
            block.append(h, p); block.hidden = false; shown++;
          });
          status(st, shown ? "AI can make mistakes — ask your teacher if something does not make sense." : "Nothing to explain — you got everything right.");
          ex.innerHTML = '<i class="fas fa-check"></i> Explained';
        })
        .catch(function (e) { status(st, e.message, true); ex.disabled = false; ex.innerHTML = label; });
    });
  }

  const box = document.getElementById("insightBox");
  if (box) {
    function list(title, items) {
      if (!items || !items.length) return null;
      const wrap = document.createElement("div");
      const h = document.createElement("strong"); h.textContent = title;
      const ul = document.createElement("ul");
      items.forEach(function (t) { const li = document.createElement("li"); li.textContent = t; ul.appendChild(li); });
      wrap.append(h, ul); return wrap;
    }
    function render(ins) {
      box.innerHTML = "";
      const p = document.createElement("p"); p.className = "insight-summary"; p.textContent = ins.summary;
      box.append(p);
      [list("Weak points", ins.weak_points), list("What to do next", ins.suggestions)].forEach(function (x) { if (x) box.append(x); });
      const n = document.createElement("small"); n.className = "form-hint";
      n.textContent = "AI summary from " + ins.attempts + " finished attempts. AI can make mistakes.";
      box.append(n); box.hidden = false;
    }
    if (box.dataset.saved) { try { render(JSON.parse(box.dataset.saved)); } catch (e) {} }
    const btn = document.getElementById("insightBtn");
    const st = document.getElementById("insightStatus");
    if (btn) btn.addEventListener("click", function () {
      const label = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Thinking…';
      status(st, "");
      post("insights.php", { quiz_id: document.getElementById("insights").dataset.quiz, refresh: box.dataset.saved || !box.hidden ? "1" : "" })
        .then(function (d) { render(d.insights); box.dataset.saved = "1"; btn.innerHTML = '<i class="fas fa-redo"></i> Refresh AI summary'; })
        .catch(function (e) { status(st, e.message, true); btn.innerHTML = label; })
        .finally(function () { btn.disabled = false; });
    });
  }
})();
