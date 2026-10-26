/**
 * SmartClass - lecture editor (pages/lectures/add.php)
 *   toolbar [data-md]   formatting on the selected text (Ctrl+B / Ctrl+I / Ctrl+K)
 *   Tab                 indents inside a ``` code block only (elsewhere it moves focus as usual)
 *   Write / Preview     preview.php renders with the same code students see
 *   stats               words · minutes · code blocks
 *   when (radio)        save button label + schedule time field
 *   class select        shows only that class's files; add-link rows
 *   local backup        the text is kept in this browser while writing (in case the tab closes)
 */
function lectureEditorInit(opts) {
  const form = document.getElementById("lectureForm");
  if (!form) return;
  const ta = document.getElementById("content");
  const preview = document.getElementById("lecPreview");
  const stats = document.getElementById("lecStats");
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
  bindLiveClear(form);

  // ---------- formatting ----------
  function wrap(before, after, placeholder) {
    const s = ta.selectionStart, e = ta.selectionEnd;
    const sel = ta.value.slice(s, e) || placeholder;
    ta.setRangeText(before + sel + after, s, e, "end");
    ta.setSelectionRange(s + before.length, s + before.length + sel.length);   // the text stays selected
    ta.focus();
    changed();
  }
  function prefixLines(make) {
    const v = ta.value;
    let s = v.lastIndexOf("\n", ta.selectionStart - 1) + 1;
    let e = v.indexOf("\n", ta.selectionEnd);
    if (e === -1) e = v.length;
    const lines = v.slice(s, e).split("\n").map(function (l, i) { return make(l, i); });
    ta.setRangeText(lines.join("\n"), s, e, "select");
    ta.focus();
    changed();
  }
  function insertBlock(text, selectFrom, selectLen) {
    const s = ta.selectionStart;
    const before = ta.value.slice(0, s);
    const lead = before === "" || before.endsWith("\n\n") ? "" : (before.endsWith("\n") ? "\n" : "\n\n");
    ta.setRangeText(lead + text + "\n", s, ta.selectionEnd, "end");
    if (selectFrom !== undefined) ta.setSelectionRange(s + lead.length + selectFrom, s + lead.length + selectFrom + selectLen);
    ta.focus();
    changed();
  }
  const actions = {
    h2: function () { prefixLines(function (l) { return "## " + l.replace(/^#{1,3}\s+/, ""); }); },
    h3: function () { prefixLines(function (l) { return "### " + l.replace(/^#{1,3}\s+/, ""); }); },
    bold: function () { wrap("**", "**", "bold text"); },
    italic: function () { wrap("*", "*", "italic text"); },
    code: function () { wrap("`", "`", "code"); },
    link: function () {
      const s = ta.selectionStart, e = ta.selectionEnd;
      const text = ta.value.slice(s, e) || "link text";
      ta.setRangeText("[" + text + "](https://)", s, e, "end");
      const urlStart = s + text.length + 3;
      ta.setSelectionRange(urlStart, urlStart + 8);
      ta.focus();
      changed();
    },
    ul: function () { prefixLines(function (l) { return "- " + l.replace(/^([-*+]|\d+[.)])\s+/, ""); }); },
    ol: function () { prefixLines(function (l, i) { return (i + 1) + ". " + l.replace(/^([-*+]|\d+[.)])\s+/, ""); }); },
    quote: function () { prefixLines(function (l) { return "> " + l.replace(/^>\s?/, ""); }); },
    hr: function () { insertBlock("---"); },
    chart: function () { const body = '{\n  "type": "bar",\n  "data": {\n    "labels": ["Mon", "Tue", "Wed"],\n    "datasets": [{ "label": "Students present", "data": [12, 15, 11] }]\n  }\n}'; insertBlock("```chart\n" + body + "\n```", 9, body.length); },
    diagram: function () { const body = "graph TD\n    A[First step] -->|then| B[Second step]\n    B --> C[Result]"; insertBlock("```mermaid\n" + body + "\n```", 11, body.length); },
    codeblock: function () {
      const lang = document.getElementById("codeLang").value;
      const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
      const body = sel || "# your code here";
      insertBlock("```" + lang + "\n" + body + "\n```", 4 + lang.length, body.length);
    },
    outblock: function () {   // the expected output of the code block above, shown to students as a terminal panel
      const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
      const body = sel || "type the output here";
      insertBlock("```output\n" + body + "\n```", 10, body.length);
    },
  };
  // ---------- colours ----------
  const colorBtn = document.querySelector(".lec-color-btn");
  const colorMenu = document.querySelector(".lec-color-menu");
  if (colorBtn && colorMenu) {
    colorBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      colorMenu.hidden = !colorMenu.hidden;
      colorBtn.setAttribute("aria-expanded", colorMenu.hidden ? "false" : "true");
    });
    document.addEventListener("click", function () { colorMenu.hidden = true; colorBtn.setAttribute("aria-expanded", "false"); });
    colorMenu.querySelectorAll("[data-color]").forEach(function (b) {
      b.addEventListener("click", function () {
        const c = b.dataset.color;
        if (c) {
          wrap("{" + c + "}", "{/" + c + "}", c === "mark" ? "highlighted text" : "coloured text");
        } else {
          // No colour: remove colour markers inside the selection
          const s = ta.selectionStart, e = ta.selectionEnd;
          const clean = ta.value.slice(s, e).replace(/\{\/?(red|orange|green|blue|purple|teal|gray|mark)\}/g, "");
          ta.setRangeText(clean, s, e, "select");
          ta.focus();
          changed();
        }
      });
    });
  }

  document.querySelectorAll(".lec-toolbar [data-md]").forEach(function (b) {
    b.addEventListener("click", function () { actions[b.dataset.md](); });
  });
  ta.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey) {
      const k = e.key.toLowerCase();
      if (k === "b" || k === "i" || k === "k") {
        e.preventDefault();
        actions[{ b: "bold", i: "italic", k: "link" }[k]]();
        return;
      }
    }
    if (e.key === "Tab" && !e.ctrlKey && !e.altKey) {
      // inside a ``` block? (odd number of fences before the caret)
      const fences = (ta.value.slice(0, ta.selectionStart).match(/^```/gm) || []).length;
      if (fences % 2 === 1) {
        e.preventDefault();
        if (e.shiftKey) {
          const ls = ta.value.lastIndexOf("\n", ta.selectionStart - 1) + 1;
          if (ta.value.slice(ls, ls + 4) === "    ") { ta.setRangeText("", ls, ls + 4, "preserve"); changed(); }
        } else {
          ta.setRangeText("    ", ta.selectionStart, ta.selectionEnd, "end");
          changed();
        }
      }
    }
  });

  // ---------- stats ----------
  function updateStats() {
    const text = ta.value;
    const blocks = (text.match(/^```/gm) || []).length >> 1;
    const words = text.replace(/```[\s\S]*?```/g, " ").split(/\s+/).filter(Boolean).length;
    const mins = Math.max(1, Math.ceil((words + 30 * blocks) / 200));
    stats.textContent = words + " words · about " + mins + " min read · " + blocks + " code block" + (blocks === 1 ? "" : "s");
  }

  // ---------- write / preview ----------
  const classSel = document.getElementById("class_id");
  const hint = document.getElementById("previewHint");
  const IMG_LINE = /^!\[([^\]\n]{0,200})\]\(img:(\d{1,10})(?:\s+(\d{1,3})%)?(?:\s+(left|center|right))?\)$/;
  let previewing = false;

  function renderPreview(keepScroll) {
    const y = window.scrollY;
    return fetch("preview.php", { method: "POST", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded" },
                                  body: new URLSearchParams({ content: ta.value, class_id: classSel.value }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error);
        preview.innerHTML = d.html || '<p class="form-hint">Nothing written yet.</p>';
        decorateImages();
        if (keepScroll) window.scrollTo(0, y);
      })
      .catch(function (e) { preview.textContent = e.message || "Preview failed."; });
  }
  const tabs = document.querySelectorAll(".lec-tabs button");
  tabs.forEach(function (t) {
    t.addEventListener("click", function () {
      const show = t.dataset.tab === "preview";
      previewing = show;
      tabs.forEach(function (o) { o.classList.toggle("active", o === t); o.setAttribute("aria-selected", o === t ? "true" : "false"); });
      ta.hidden = show;
      document.querySelector(".lec-toolbar").classList.toggle("is-disabled", show);
      preview.hidden = !show;
      hint.hidden = !show || !/^!\[[^\]]*\]\(img:/m.test(ta.value);
      if (!show) { ta.focus(); return; }
      preview.innerHTML = '<p class="form-hint">Loading preview…</p>';
      renderPreview(false);
    });
  });

  // ---------- text blocks (an image line is always a block of its own; ``` blocks stay whole) ----------
  function splitBlocks(text) {
    const lines = text.split("\n");
    const out = [];
    let i = 0;
    while (i < lines.length) {
      if (lines[i].trim() === "") { i++; continue; }
      const start = i;
      if (/^```/.test(lines[i].trim())) {
        for (i++; i < lines.length && !/^```\s*$/.test(lines[i].trim()); i++) {}
        i++;
      } else if (IMG_LINE.test(lines[i].trim())) {
        i++;
      } else {
        for (; i < lines.length && lines[i].trim() !== "" && !IMG_LINE.test(lines[i].trim()) && !/^```/.test(lines[i].trim()); i++) {}
      }
      out.push({ start: start, end: Math.min(i, lines.length) - 1, text: lines.slice(start, Math.min(i, lines.length)).join("\n") });
    }
    return out;
  }
  function blockIndexAt(blocks, line) {
    for (let k = 0; k < blocks.length; k++) if (line >= blocks[k].start && line <= blocks[k].end) return k;
    return -1;
  }
  function setText(text) {
    ta.value = text;
    changed();
    if (previewing) renderPreview(true);
  }
  // Change the image line at source line `line`
  function editImageLine(line, fn) {
    const lines = ta.value.split("\n");
    const m = (lines[line] || "").trim().match(IMG_LINE);
    if (!m) return;
    const img = { caption: m[1], id: m[2], width: Number(m[3] || 100), align: m[4] || "center" };
    if (fn(img) === false) {
      lines.splice(line, 1);
    } else {
      lines[line] = "![" + img.caption.replace(/[\]\n]/g, "") + "](img:" + img.id + " " + img.width + "% " + img.align + ")";
    }
    setText(lines.join("\n"));
  }
  // Move the image block at `line` before / after the block at `targetLine` (or one step up / down)
  function moveImage(line, targetLine, after) {
    const blocks = splitBlocks(ta.value);
    const from = blockIndexAt(blocks, line);
    if (from < 0) return;
    // where it goes, decided on the ORIGINAL block list
    let to;
    if (targetLine === "up") to = Math.max(0, from - 1);
    else if (targetLine === "down") to = Math.min(blocks.length - 1, from + 1);
    else {
      const target = blockIndexAt(blocks, targetLine);
      if (target < 0 || target === from) return;
      to = target + (after ? 1 : 0);
      if (to > from) to -= 1;   // the moving block is taken out first
    }
    if (to === from) return;
    const moving = blocks.splice(from, 1)[0];
    blocks.splice(to, 0, moving);
    setText(blocks.map(function (b) { return b.text; }).join("\n\n"));
  }

  // ---------- image controls in the preview ----------
  function decorateImages() {
    preview.querySelectorAll("figure.lec-img[data-line]").forEach(function (fig) {
      const line = Number(fig.dataset.line);
      const m = (ta.value.split("\n")[line] || "").trim().match(IMG_LINE);
      const width = m ? Number(m[3] || 100) : 100;
      const bar = document.createElement("div");
      bar.className = "lec-img-tools";
      bar.innerHTML =
        '<span class="lec-img-drag" title="Drag to move anywhere"><i class="fas fa-grip-vertical"></i></span>' +
        '<button type="button" data-act="up" title="Move up"><i class="fas fa-arrow-up"></i></button>' +
        '<button type="button" data-act="down" title="Move down"><i class="fas fa-arrow-down"></i></button>' +
        '<span class="sep"></span>' +
        '<button type="button" data-act="left" title="Align left (text flows around)"><i class="fas fa-align-left"></i></button>' +
        '<button type="button" data-act="center" title="Centre"><i class="fas fa-align-center"></i></button>' +
        '<button type="button" data-act="right" title="Align right (text flows around)"><i class="fas fa-align-right"></i></button>' +
        '<span class="sep"></span>' +
        '<label class="lec-img-size" title="Size"><i class="fas fa-expand-alt"></i><input type="range" min="10" max="100" step="5" value="' + width + '" aria-label="Image size"><span>' + width + '%</span></label>' +
        '<button type="button" data-act="caption" title="Edit caption"><i class="fas fa-pen"></i></button>' +
        '<button type="button" data-act="remove" class="danger" title="Remove from the lecture"><i class="fas fa-trash"></i></button>';
      fig.prepend(bar);
      bar.querySelectorAll("button[data-act]").forEach(function (b) {
        b.addEventListener("click", function () {
          const act = b.dataset.act;
          if (act === "up" || act === "down") return moveImage(line, act);
          if (act === "left" || act === "center" || act === "right") return editImageLine(line, function (img) { img.align = act; });
          if (act === "remove") return editImageLine(line, function () { return false; });
          if (act === "caption") {
            const cur = (m && m[1]) || "";
            Swal.fire({ titleText: "Image caption", input: "text", inputValue: cur, inputAttributes: { maxlength: 200 },
                        showCancelButton: true, confirmButtonText: "Save", confirmButtonColor: "#4361ee" })
              .then(function (r) { if (r.isConfirmed) editImageLine(line, function (img) { img.caption = r.value || ""; }); });
          }
        });
      });
      const range = bar.querySelector("input[type=range]");
      const label = bar.querySelector(".lec-img-size span");
      range.addEventListener("input", function () { fig.style.width = range.value + "%"; label.textContent = range.value + "%"; });   // live, smooth
      range.addEventListener("change", function () { editImageLine(line, function (img) { img.width = Number(range.value); }); });
      // corner / side handles: drag with the mouse to resize (the picture keeps its shape)
      ["nw", "ne", "sw", "se", "w", "e"].forEach(function (dir) {
        const h = document.createElement("span");
        h.className = "lec-img-handle h-" + dir; h.dataset.dir = dir; h.setAttribute("aria-hidden", "true");
        h.addEventListener("pointerdown", function (e) { startResize(e, fig, line, dir, h); });
        fig.appendChild(h);
      });
      // drag the picture itself (or the grip) to move it: up / down between blocks, left / centre / right by where you drop it
      const grip = bar.querySelector(".lec-img-drag");
      const press = function (e) {
        if (e.button !== 0 || e.target.closest(".lec-img-tools button, .lec-img-tools input, .lec-img-handle, figcaption")) return;
        if (e.pointerType !== "mouse" && !e.target.closest(".lec-img-drag")) return;   // touch: only the grip, so the page can still scroll
        startMove(e, fig, line);
      };
      fig.addEventListener("pointerdown", press);
      fig.addEventListener("pointerdown", function () {
        preview.querySelectorAll("figure.lec-img.is-selected").forEach(function (f) { if (f !== fig) f.classList.remove("is-selected"); });
        fig.classList.add("is-selected");
      });
      fig.addEventListener("dragstart", function (e) { e.preventDefault(); });   // no browser ghost image; we draw our own
      grip.style.touchAction = "none";
    });
  }
  document.addEventListener("pointerdown", function (e) {
    if (!e.target.closest || e.target.closest("figure.lec-img")) return;
    preview.querySelectorAll("figure.lec-img.is-selected").forEach(function (f) { f.classList.remove("is-selected"); });
  });

  // Resize: width in % of the text column, 10-100
  function startResize(e, fig, line, dir, handle) {
    e.preventDefault(); e.stopPropagation();
    const cs = getComputedStyle(preview);
    const column = preview.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const startW = fig.getBoundingClientRect().width, startX = e.clientX;
    const centered = fig.classList.contains("align-center");
    const grow = (dir.indexOf("w") >= 0 ? -1 : 1) * (centered ? 2 : 1);   // a centred picture grows on both sides, so its edge follows the mouse
    const from = Math.round(startW / column * 100);
    let pct = from;
    try { handle.setPointerCapture(e.pointerId); } catch (err) {}
    fig.classList.add("is-resizing");
    const move = function (ev) {
      pct = Math.max(10, Math.min(100, Math.round((startW + grow * (ev.clientX - startX)) / column * 100 / 5) * 5));
      fig.style.width = pct + "%"; fig.dataset.size = pct + "%";
    };
    const up = function () {
      document.removeEventListener("pointermove", move); document.removeEventListener("pointerup", up); document.removeEventListener("pointercancel", up);
      fig.classList.remove("is-resizing"); delete fig.dataset.size;
      if (pct !== from) editImageLine(line, function (img) { img.width = pct; });
    };
    document.addEventListener("pointermove", move); document.addEventListener("pointerup", up); document.addEventListener("pointercancel", up);
  }

  // Move: pointer drag with a ghost and a drop marker
  function startMove(e, fig, line) {
    const sx = e.clientX, sy = e.clientY;
    let ghost = null, mark = null, drop = null;
    const blocks = function () { return Array.prototype.filter.call(preview.children, function (c) { return c.dataset && c.dataset.line !== undefined && c !== fig; }); };
    const find = function (ev) {
      const pr = preview.getBoundingClientRect();
      const rel = (ev.clientX - pr.left) / pr.width;
      const align = rel < 0.33 ? "left" : (rel > 0.67 ? "right" : "center");
      let best = null, after = false, dist = Infinity;
      blocks().forEach(function (b) {
        const r = b.getBoundingClientRect();
        const top = Math.abs(ev.clientY - r.top), bottom = Math.abs(ev.clientY - r.bottom);
        if (top < dist) { dist = top; best = b; after = false; }
        if (bottom < dist) { dist = bottom; best = b; after = true; }
      });
      return { align: align, target: best, after: after };
    };
    const move = function (ev) {
      if (!ghost) {
        if (Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 6) return;
        ghost = document.createElement("div"); ghost.className = "lec-img-ghost";
        const im = fig.querySelector("img"); const g = document.createElement("img"); g.src = im.src; g.alt = ""; ghost.appendChild(g);
        const w = Math.min(180, fig.getBoundingClientRect().width); ghost.style.width = w + "px";
        document.body.appendChild(ghost);
        mark = document.createElement("div"); mark.className = "lec-drop-mark"; preview.appendChild(mark);
        fig.classList.add("is-dragging"); document.body.classList.add("lec-is-moving");
      }
      ghost.style.left = ev.clientX + 12 + "px"; ghost.style.top = ev.clientY + 12 + "px";
      drop = find(ev);
      const pr = preview.getBoundingClientRect();
      if (drop.target) {
        const r = drop.target.getBoundingClientRect();
        const y = (drop.after ? r.bottom : r.top) - pr.top + preview.scrollTop;
        const wFrac = drop.align === "center" ? 1 : 0.4;
        mark.style.top = y - 2 + "px";
        mark.style.width = pr.width * wFrac + "px";
        mark.style.left = (drop.align === "right" ? pr.width * (1 - wFrac) : (drop.align === "center" ? 0 : 0)) + "px";
        mark.dataset.label = drop.align === "center" ? "centre" : drop.align + " · text flows around";
      }
    };
    const up = function () {
      document.removeEventListener("pointermove", move); document.removeEventListener("pointerup", up); document.removeEventListener("pointercancel", cancel);
      if (ghost) { ghost.remove(); mark.remove(); fig.classList.remove("is-dragging"); document.body.classList.remove("lec-is-moving"); }
      if (!ghost || !drop) return;
      const lines = ta.value.split("\n");
      const m = (lines[line] || "").trim().match(IMG_LINE);
      if (!m) return;
      lines[line] = "![" + m[1] + "](img:" + m[2] + " " + Number(m[3] || 100) + "% " + drop.align + ")";
      ta.value = lines.join("\n");
      const targetLine = drop.target ? Number(drop.target.dataset.line) : -1;
      if (targetLine >= 0 && targetLine !== line) moveImage(line, targetLine, drop.after); else setText(ta.value);
    };
    const cancel = function () { drop = null; up(); };
    document.addEventListener("pointermove", move); document.addEventListener("pointerup", up); document.addEventListener("pointercancel", cancel);
  }

  // ---------- image panel: upload (button / drop / paste) + class library ----------
  const imgToggle = document.getElementById("imgToggle");
  const imgPanel = document.getElementById("imgPanel");
  const imgLib = document.getElementById("imgLib");
  const imgErr = document.getElementById("imgError");
  const imgFile = document.getElementById("imgFile");
  const drop = document.getElementById("imgDrop");
  function imgError(msg) { imgErr.hidden = !msg; imgErr.textContent = msg || ""; }
  function api(data) {
    return fetch("images.php", { method: "POST", headers: { "X-CSRF-Token": csrf }, body: data })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.error || "Something went wrong."); return d; });
  }
  function insertImage(id) {
    imgPanel.hidden = true;
    imgToggle.setAttribute("aria-expanded", "false");
    const cap = "Describe the image";
    insertBlock("![" + cap + "](img:" + id + " 60% center)\n", 2, cap.length);   // caption selected, blank line after
  }
  function loadLib() {
    if (!classSel.value) { imgLib.innerHTML = '<p class="form-hint">Choose the class first.</p>'; return; }
    imgLib.innerHTML = '<p class="form-hint">Loading…</p>';
    const fd = new FormData(); fd.append("action", "list"); fd.append("class_id", classSel.value);
    api(fd).then(function (d) {
      imgLib.innerHTML = "";
      if (!d.images.length) { imgLib.innerHTML = '<p class="form-hint">No images yet — upload the first one above.</p>'; return; }
      d.images.forEach(function (im) {
        const card = document.createElement("div");
        card.className = "lec-imglib-item";
        const pick = document.createElement("button");
        pick.type = "button"; pick.className = "lec-imglib-pick"; pick.title = "Insert this image";
        const img = document.createElement("img");
        img.src = im.url; img.alt = im.name || ("Image " + im.id); img.loading = "lazy";
        pick.appendChild(img);
        pick.addEventListener("click", function () { insertImage(im.id); });
        const del = document.createElement("button");
        del.type = "button"; del.className = "lec-imglib-del"; del.title = "Delete this image"; del.setAttribute("aria-label", "Delete image");
        del.innerHTML = '<i class="fas fa-trash"></i>';
        del.addEventListener("click", function () {
          Swal.fire({ icon: "warning", titleText: "Delete this image?", text: "Lectures that use it will show “Image not available”.",
                      showCancelButton: true, confirmButtonText: "Delete", confirmButtonColor: "#ef476f" })
            .then(function (r) {
              if (!r.isConfirmed) return;
              const fd2 = new FormData(); fd2.append("action", "delete"); fd2.append("class_id", classSel.value); fd2.append("id", im.id);
              api(fd2).then(loadLib).catch(function (e) { imgError(e.message); });
            });
        });
        card.append(pick, del);
        imgLib.appendChild(card);
      });
    }).catch(function (e) { imgLib.innerHTML = ""; imgError(e.message); });
  }
  function upload(file) {
    imgError("");
    if (!classSel.value) { imgPanel.hidden = false; return imgError("Choose the class first — images belong to a class."); }
    if (!/^image\/(png|jpeg|webp|gif)$/.test(file.type)) return imgError("Use a JPG, PNG, WEBP or GIF image.");
    if (file.size > 8 * 1024 * 1024) return imgError("The image is too large (max 8 MB).");
    drop.classList.add("is-busy");
    const fd = new FormData(); fd.append("action", "upload"); fd.append("class_id", classSel.value); fd.append("image", file, file.name || "pasted.png");
    api(fd).then(function (d) { insertImage(d.id); loadLib(); })
      .catch(function (e) { imgPanel.hidden = false; imgError(e.message); })
      .finally(function () { drop.classList.remove("is-busy"); });
  }
  imgToggle.addEventListener("click", function () {
    imgPanel.hidden = !imgPanel.hidden;
    imgToggle.setAttribute("aria-expanded", imgPanel.hidden ? "false" : "true");
    if (!imgPanel.hidden) { imgError(""); loadLib(); }
  });
  document.getElementById("imgPick").addEventListener("click", function () { imgFile.click(); });
  imgFile.addEventListener("change", function () { if (imgFile.files[0]) upload(imgFile.files[0]); imgFile.value = ""; });
  [drop, ta].forEach(function (zone) {
    zone.addEventListener("dragover", function (e) {
      if (e.dataTransfer && Array.prototype.some.call(e.dataTransfer.items || [], function (it) { return it.kind === "file"; })) { e.preventDefault(); drop.classList.add("is-over"); }
    });
    zone.addEventListener("dragleave", function () { drop.classList.remove("is-over"); });
    zone.addEventListener("drop", function (e) {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (!f) return;
      e.preventDefault();
      drop.classList.remove("is-over");
      upload(f);
    });
  });
  ta.addEventListener("paste", function (e) {
    const items = (e.clipboardData && e.clipboardData.items) || [];
    for (let k = 0; k < items.length; k++) {
      if (items[k].kind === "file" && /^image\//.test(items[k].type)) { e.preventDefault(); upload(items[k].getAsFile()); return; }
    }
  });
  classSel.addEventListener("change", function () { if (!imgPanel.hidden) loadLib(); });

  // ---------- when: publish / schedule / draft ----------
  const saveLabel = document.querySelector("#saveBtn span");
  const saveIcon = document.querySelector("#saveBtn i");
  const timeBox = document.getElementById("whenTime");
  function syncWhen() {
    const v = (form.querySelector("[name=when]:checked") || {}).value;
    timeBox.hidden = v !== "schedule";
    saveLabel.textContent = v === "schedule" ? "Schedule" : (v === "draft" ? "Save draft" : "Publish");
    saveIcon.className = "fas " + (v === "schedule" ? "fa-clock" : (v === "draft" ? "fa-save" : "fa-paper-plane"));
  }
  form.querySelectorAll("[name=when]").forEach(function (r) { r.addEventListener("change", syncWhen); });
  syncWhen();

  // ---------- class → its files; add-link rows ----------
  const cls = document.getElementById("class_id");
  function syncResources() {
    let any = false;
    form.querySelectorAll(".material-check").forEach(function (lab) {
      const show = lab.dataset.class === cls.value;
      lab.hidden = !show;
      const box = lab.querySelector("input");
      box.disabled = !show;
      if (!show) box.checked = false;
      if (show) any = true;
    });
    document.getElementById("noClassResources").hidden = any || !cls.value;
  }
  cls.addEventListener("change", syncResources);
  syncResources();
  const addRow = document.getElementById("addLinkRow");
  if (addRow) addRow.addEventListener("click", function () {
    const rows = form.querySelectorAll(".material-link");
    const copy = rows[rows.length - 1].cloneNode(true);
    copy.querySelectorAll("input").forEach(function (i) { i.value = ""; i.classList.remove("is-invalid"); });
    copy.querySelector(".invalid-feedback").textContent = "";
    rows[rows.length - 1].after(copy);
    bindLiveClear(form);
    if (rows.length + 1 >= Number(addRow.dataset.max)) addRow.remove();
  });

  // ---------- local backup of the text ----------
  const note = document.getElementById("lecDraftNote");
  let backupTimer = null;
  function store(obj) { try { localStorage.setItem(opts.draftKey, JSON.stringify(obj)); } catch (e) { /* storage blocked */ } }
  function changed() {
    updateStats();
    clearTimeout(backupTimer);
    backupTimer = setTimeout(function () {
      store({ title: document.getElementById("title").value, content: ta.value, at: Date.now() });
      note.hidden = false;
      note.textContent = "Backed up in this browser " + new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    }, 1500);
  }
  ta.addEventListener("input", changed);
  document.getElementById("title").addEventListener("input", changed);
  let saved = null;
  try { saved = JSON.parse(localStorage.getItem(opts.draftKey) || "null"); } catch (e) { saved = null; }
  if (saved && saved.content && saved.content.trim() !== ta.value.trim()) {
    note.hidden = false;
    note.innerHTML = "";
    const when = new Date(saved.at).toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
    note.append("Unsaved text from " + when + " found. ");
    const restore = document.createElement("button");
    restore.type = "button"; restore.className = "link-btn"; restore.textContent = "Restore it";
    const discard = document.createElement("button");
    discard.type = "button"; discard.className = "link-btn"; discard.textContent = "Discard";
    restore.addEventListener("click", function () {
      ta.value = saved.content;
      if (saved.title && !document.getElementById("title").value) document.getElementById("title").value = saved.title;
      note.textContent = "Restored — remember to save.";
      updateStats();
    });
    discard.addEventListener("click", function () { try { localStorage.removeItem(opts.draftKey); } catch (e) {} note.hidden = true; });
    note.append(restore, " · ", discard);
  }
  form.addEventListener("submit", function (e) {
    // inline checks (server checks again)
    let ok = true;
    const title = document.getElementById("title");
    if (!cls.value) { setFieldError(cls, "Please select one of your classes"); ok = false; }
    if (!title.value.trim()) { setFieldError(title, "Lecture title is required"); ok = false; }
    if (!ta.value.trim()) { ta.hidden = false; preview.hidden = true; setFieldError(ta, "Lecture content is required"); ok = false; }
    const when = (form.querySelector("[name=when]:checked") || {}).value;
    const at = document.getElementById("publish_at");
    if (when === "schedule" && !at.value) { setFieldError(at, "Choose when students should see it"); ok = false; }
    if (!ok) { e.preventDefault(); return; }
    try { localStorage.removeItem(opts.draftKey); } catch (err) { /* storage blocked */ }
  });
  updateStats();
}
