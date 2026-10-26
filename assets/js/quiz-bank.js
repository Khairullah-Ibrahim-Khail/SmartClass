/**
 * SmartClass - quiz builder: "My question bank" (pages/quizzes/bank.php).
 * Browse / search the teacher's kept questions, tick some, and they are added to the builder as normal editable questions.
 * Needs window.quizRenumber (set by the page) and the builder's <template> blocks.
 */
(function () {
  const panel = document.getElementById("bankPanel");
  if (!panel) return;
  const url = panel.dataset.url, box = document.getElementById("bankBox"), listEl = document.getElementById("bankList");
  const search = document.getElementById("bankSearch"), tagSel = document.getElementById("bankTagFilter");
  const addBtn = document.getElementById("bankAdd"), count = document.getElementById("bankCount");
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
  let items = [], timer = null;
  const T = function (t) { return (window.SC_DICT && window.SC_DICT[t]) || t; };   // Pashto / Dari wording when the page is not English

  function esc(s) { const d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; }
  function refresh() {
    const n = listEl.querySelectorAll("input[type=checkbox]:checked").length;
    count.textContent = n ? "(" + n + ")" : ""; addBtn.disabled = n === 0;
  }
  function render() {
    if (!items.length) { listEl.innerHTML = '<p class="form-hint" style="margin:0;">' + esc(T("No questions in your bank yet. Tick \"Keep these questions in my question bank\" when you publish a quiz.")) + '</p>'; refresh(); return; }
    listEl.innerHTML = items.map(function (q) {
      return '<div class="bank-item" role="listitem" data-id="' + q.id + '" style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line,#e4e8f1);">'
        + '<input type="checkbox" value="' + q.id + '" aria-label="Select this question" style="margin-top:4px;">'
        + '<span style="flex:1;min-width:0;"><strong>' + esc(q.text) + '</strong><br><small class="form-hint" style="margin:0;">'
        + (q.type === "short" ? T("Short answer") : q.options.length + " " + T("options")) + " · " + q.marks + " " + T(q.marks === 1 ? "mark" : "marks") + (q.tag ? " · " + esc(q.tag) : "") + "</small></span>"
        + '<button type="button" class="icon-btn danger bank-del" title="' + esc(T("Remove from my bank")) + '" aria-label="' + esc(T("Remove from my bank")) + '"><i class="fas fa-trash"></i></button></div>';
    }).join("");
    refresh();
  }
  function load() {
    const qs = "?q=" + encodeURIComponent(search.value.trim()) + "&tag=" + encodeURIComponent(tagSel.value);
    fetch(url + qs, { headers: { Accept: "application/json" }, credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) throw new Error(d.error);
      items = d.questions;
      if (!tagSel.dataset.filled) {
        Object.keys(d.tags).forEach(function (t) { const o = document.createElement("option"); o.value = t; o.textContent = t + " (" + d.tags[t] + ")"; tagSel.appendChild(o); });
        tagSel.dataset.filled = "1";
      }
      render();
    }).catch(function (e) { listEl.innerHTML = '<p class="form-hint" style="color:#e5484d;">' + esc(e.message || T("Could not load the bank.")) + "</p>"; });
  }
  function addToBuilder(q) {
    const list = document.getElementById("questionList");
    const first = list.children.length === 1 ? list.children[0] : null;
    if (first && !first.querySelector(".q-text").value.trim() && Array.from(first.querySelectorAll(".o-text")).every(function (o) { return !o.value.trim(); })) first.remove();
    const block = document.getElementById("questionTemplate").content.cloneNode(true).firstElementChild;
    block.querySelector(".q-type").value = q.type;
    block.querySelector(".q-text").value = q.text;
    block.querySelector(".q-marks").value = q.marks;
    block.querySelector(".q-model").value = q.model || "";
    const opts = block.querySelector(".option-list");
    if (q.type === "choice") q.options.forEach(function (t) {
      const row = document.getElementById("optionTemplate").content.cloneNode(true).firstElementChild;
      row.querySelector(".o-text").value = t; opts.appendChild(row);
    });
    list.appendChild(block);
    window.quizRenumber();
    if (q.type === "choice") { const r = block.querySelectorAll(".o-correct")[q.correct]; if (r) r.checked = true; }
    if (window.bindLiveClear) window.bindLiveClear(block);
    return block;
  }

  document.getElementById("bankOpen").addEventListener("click", function () { box.hidden = false; load(); });
  search.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(load, 250); });
  tagSel.addEventListener("change", load);
  listEl.addEventListener("change", refresh);
  listEl.addEventListener("click", function (e) {
    const del = e.target.closest(".bank-del"); if (!del) return;
    const id = del.closest(".bank-item").dataset.id;
    const go = function () {
      fetch(url, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded" }, body: "action=delete&id=" + encodeURIComponent(id) })
        .then(function (r) { return r.json(); }).then(function (d) { if (d.ok) load(); });
    };
    if (window.Swal) Swal.fire({ icon: "question", titleText: T("Remove this question from your bank?"), text: T("Quizzes you already published keep it."), showCancelButton: true, confirmButtonText: T("Remove"), confirmButtonColor: "#e5484d" }).then(function (r) { if (r.isConfirmed) go(); });
    else if (confirm("Remove this question from your bank?")) go();
  });
  addBtn.addEventListener("click", function () {
    const ids = Array.from(listEl.querySelectorAll("input:checked")).map(function (b) { return +b.value; });
    let last = null;
    items.filter(function (q) { return ids.indexOf(q.id) !== -1; }).forEach(function (q) { if (document.getElementById("questionList").children.length < 100) last = addToBuilder(q); });
    listEl.querySelectorAll("input:checked").forEach(function (b) { b.checked = false; }); refresh();
    if (last) last.scrollIntoView({ behavior: "smooth", block: "start" });
  });
})();
