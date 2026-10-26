/**
 * SmartClass - student AI Tutor chat (pages/ai/tutor.php).
 * Tutor answers are laid out (bold, colours, lists, code) by scRenderAi, which escapes everything first; your own messages are plain text. The answer appears
 * live while it is written (scAiStream). No page reloads: sending, switching class,
 * opening or deleting a conversation all use fetch against the same page (?ajax=...).
 */
(function () {
  const root = document.getElementById("tutor");
  if (!root) return;
  const url = root.dataset.url, csrf = root.dataset.csrf;
  let classId = +root.dataset.class, chatId = +root.dataset.chat || 0, lectureId = +root.dataset.lecture || 0, busy = false;
  const aiOn = root.dataset.ai === "1";
  const scroller = document.getElementById("tutorScroll"), welcome = document.getElementById("tutorWelcome");
  const list = document.getElementById("tutorChats"), form = document.getElementById("tutorForm");
  const text = document.getElementById("tutorText"), send = document.getElementById("tutorSend"), err = document.getElementById("tutorError");

  function bubble(role, body, pending) {
    welcome.hidden = true;
    const d = document.createElement("div");
    d.className = "tb " + (role === "student" ? "me" : "ai") + (pending ? " pending" : "");
    if (role === "student" || pending || !window.scRenderAi) d.textContent = body;
    else { d.classList.add("ai-rich"); d.innerHTML = window.scRenderAi(body); }
    scroller.appendChild(d);
    if (role !== "student" && !pending && window.scAiReport) window.scAiReport(d, "tutor", classId);
    scroller.scrollTo({ top: scroller.scrollHeight, behavior: "smooth" });
    return d;
  }
  function clearThread() { scroller.querySelectorAll(".tb").forEach(function (b) { b.remove(); }); welcome.hidden = false; }
  function showError(m) { err.textContent = m || ""; err.hidden = !m; }
  function post(data) {
    const fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append("csrf_token", csrf);
    return fetch(url, { method: "POST", body: fd, credentials: "same-origin", headers: { "X-Requested-With": "fetch", "Accept": "application/json" } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: "Something went wrong. Try again." }; }); });
  }
  function renderChats(chats) {
    list.textContent = "";
    if (!chats.length) { const p = document.createElement("p"); p.className = "form-hint"; p.textContent = "No conversations yet in this class."; list.appendChild(p); return; }
    chats.forEach(function (c) {
      const row = document.createElement("div"); row.className = "tc-row" + (c.id === chatId ? " on" : "");
      const open = document.createElement("button"); open.type = "button"; open.className = "tc-open";
      const t = document.createElement("span"); t.textContent = c.title; const w = document.createElement("small"); w.textContent = c.when;
      open.append(t, w); open.addEventListener("click", function () { openChat(c.id); });
      const del = document.createElement("button"); del.type = "button"; del.className = "tc-del"; del.title = "Delete this conversation"; del.innerHTML = '<i class="fas fa-trash"></i>';
      del.addEventListener("click", function () { removeChat(c); });
      row.append(open, del); list.appendChild(row);
    });
  }
  function loadChats() {
    return fetch(url + "?class_id=" + classId + "&ajax=list", { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) renderChats(d.chats); });
  }
  function openChat(id) {
    fetch(url + "?ajax=chat&id=" + id, { credentials: "same-origin", headers: { "Accept": "application/json" } }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { showError(d.error); return; }
      showError(""); chatId = id; clearThread();
      d.messages.forEach(function (m) { bubble(m.role, m.body); });
      loadChats();
    });
  }
  function removeChat(c) {
    const go = function () { post({ ajax: "delete", class_id: classId, chat_id: c.id }).then(function (d) { if (!d.ok) return showError(d.error); if (c.id === chatId) { chatId = 0; clearThread(); } renderChats(d.chats); }); };
    if (window.Swal) Swal.fire({ icon: "warning", title: "Delete this conversation?", text: "It cannot be restored.", showCancelButton: true, confirmButtonText: "Delete", confirmButtonColor: "#dc3545" }).then(function (r) { if (r.isConfirmed) go(); });
    else if (confirm("Delete this conversation?")) go();
  }
  function ask(q) {
    q = q.trim(); if (!q || busy || !aiOn) return;
    busy = true; send.disabled = true; showError("");
    bubble("student", q); text.value = ""; grow();
    const wait = bubble("tutor", "Thinking…", true);
    const stop = function () { busy = false; send.disabled = false; text.focus(); };
    const keep = function () { scroller.scrollTop = scroller.scrollHeight; };
    window.scAiStream(url, { ajax: "ask", class_id: classId, chat_id: chatId, lecture_id: lectureId, text: q, csrf_token: csrf }, {
      onText: function (t) {   // the answer is written into the bubble as it arrives
        wait.classList.remove("pending"); wait.classList.add("ai-rich"); wait.innerHTML = window.scRenderAi(t); keep();
      },
      onDone: function (d) {
        wait.remove();
        chatId = d.chat_id; bubble("tutor", d.reply); renderChats(d.chats);
        stop();
      },
      onError: function (m) { wait.remove(); showError(m); text.value = q; stop(); }
    });
  }
  function grow() { text.style.height = "auto"; text.style.height = Math.min(text.scrollHeight + 2, 160) + "px"; }

  form.addEventListener("submit", function (e) { e.preventDefault(); ask(text.value); });
  text.addEventListener("input", grow);
  text.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(text.value); } });
  document.querySelectorAll(".tutor-start").forEach(function (b) { b.addEventListener("click", function () { ask(b.textContent); }); });
  document.getElementById("tutorNew").addEventListener("click", function () { chatId = 0; clearThread(); renderChats([]); loadChats(); text.focus(); });
  document.getElementById("tutorClass").addEventListener("change", function () { location.href = url + "?class_id=" + this.value; });
  document.getElementById("tutorLectures").addEventListener("click", function (e) {
    const b = e.target.closest(".tl-item"); if (!b) return;
    lectureId = +b.dataset.id; document.getElementById("tutorLecSel").value = String(lectureId); this.querySelectorAll(".tl-item").forEach(function (x) { x.classList.toggle("on", x === b); });
  });
  document.getElementById("tutorLecSel").addEventListener("change", function () {
    lectureId = +this.value;
    document.querySelectorAll("#tutorLectures .tl-item").forEach(function (x) { x.classList.toggle("on", +x.dataset.id === lectureId); });
  });
  loadChats();
  if (chatId) openChat(chatId);
})();
