// ========== Utils: Resizable & Draggable ==========
function makeResizableDraggable(el) {
  if (el.dataset.resizable) return;
  interact(el).resizable({
    edges: { left: true, right: true, bottom: true, top: true },
    modifiers: [
      interact.modifiers.restrictEdges({ outer: "parent" }),
      interact.modifiers.restrictSize({ min: { width: 50, height: 50 } }),
    ],
    listeners: {
      move(event) {
        let x = parseFloat(event.target.dataset.x) || 0;
        let y = parseFloat(event.target.dataset.y) || 0;
        x += event.deltaRect.left;
        y += event.deltaRect.top;
        Object.assign(event.target.style, {
          width: event.rect.width + "px",
          height: event.rect.height + "px",
          transform: `translate(${x}px,${y}px)`,
        });
        event.target.dataset.x = x;
        event.target.dataset.y = y;
      },
    },
    inertia: true,
  });
  interact(el).draggable({
    listeners: {
      move(event) {
        let x = parseFloat(el.dataset.x) || 0;
        let y = parseFloat(el.dataset.y) || 0;
        x += event.dx;
        y += event.dy;
        el.style.transform = `translate(${x}px,${y}px)`;
        el.dataset.x = x;
        el.dataset.y = y;
      },
    },
  });
  el.dataset.resizable = "true";
}

function observeBlocks(editorHolder) {
  const observer = new MutationObserver(() => {
    editorHolder.querySelectorAll("img, pre").forEach(makeResizableDraggable);
  });
  observer.observe(editorHolder, { childList: true, subtree: true });
}

// ========== Toolbar ==========
function createToolbar(toolbarId) {
  const toolbar = document.getElementById(toolbarId);

  // Font size
  const fontSize = document.createElement("select");
  ["12px", "14px", "16px", "18px", "20px", "24px"].forEach((sz) => {
    const o = document.createElement("option");
    o.value = o.text = sz;
    fontSize.appendChild(o);
  });
  fontSize.onchange = () => {
    const el = document.getSelection().focusNode?.parentElement;
    if (el) el.style.fontSize = fontSize.value;
  };
  toolbar.appendChild(fontSize);

  // Font color
  const fontColor = document.createElement("input");
  fontColor.type = "color";
  fontColor.value = "#000000";
  fontColor.onchange = () => {
    const el = document.getSelection().focusNode?.parentElement;
    if (el) el.style.color = fontColor.value;
  };
  toolbar.appendChild(fontColor);

  // Highlight color
  const highlight = document.createElement("input");
  highlight.type = "color";
  highlight.value = "#ffff00";
  highlight.onchange = () =>
    document.execCommand("backColor", false, highlight.value);
  toolbar.appendChild(highlight);
}

// ========== Editor.js ==========
function createEditor(holderId) {
  const holder = document.getElementById(holderId);
  const editor = new EditorJS({
    holder: holder,
    autofocus: true,
    placeholder: "Start writing...",
    inlineToolbar: [
      "bold",
      "italic",
      "underline",
      "link",
      "marker",
      "inlineCode",
    ],
    tools: {
      header: { class: window.Header, inlineToolbar: true },
      paragraph: { class: window.Paragraph, inlineToolbar: true },
      code: {
        class: window.CodeTool,
        shortcut: "CMD+SHIFT+C",
        config: {
          mode: "javascript",
          theme: "dracula",
          onInitialize: (cm) => {
            cm.setSize("100%", 200);
            makeResizableDraggable(cm.getWrapperElement());
          },
        },
      },
      image: {
        class: window.ImageTool,
        config: {
          inlineToolbar: true,
          uploader: {
            uploadByFile: (file) =>
              new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = (e) => {
                  resolve({ success: 1, file: { url: e.target.result } });
                  setTimeout(() => {
                    const imgs = holder.querySelectorAll("img");
                    makeResizableDraggable(imgs[imgs.length - 1]);
                  }, 50);
                };
                reader.readAsDataURL(file);
              }),
          },
        },
      },
      table: { class: window.Table, inlineToolbar: true },
      embed: { class: window.Embed, inlineToolbar: true },
      inlineCode: { class: window.InlineCode },
    },
  });
  observeBlocks(holder);
  return editor;
}

// ========== Drag & Drop Reordering ==========
function makeBlocksDraggable(editorHolder) {
  interact(editorHolder).on("dragstart", (e) => {
    e.target.classList.add("dragging");
  });
  interact(editorHolder).dropzone({
    overlap: 0.1,
    ondrop: (e) => {
      const dragging = document.querySelector(".dragging");
      if (dragging) {
        const target = e.target.closest(".ce-block");
        if (target && target !== dragging) {
          target.before(dragging);
        }
        dragging.classList.remove("dragging");
      }
    },
  });
}

// ========== Initialize Editors ==========
const headingEditor = createEditor("headingEditor");
const contentEditor = createEditor("contentEditor");
const notesEditor = createEditor("notesEditor");

createToolbar("headingToolbar");
createToolbar("contentToolbar");
createToolbar("notesToolbar");

["headingEditor", "contentEditor", "notesEditor"].forEach((id) => {
  makeBlocksDraggable(document.getElementById(id));
});

// ========== Save ==========
document.getElementById("saveBtn").addEventListener("click", async () => {
  const heading = await headingEditor.save();
  const content = await contentEditor.save();
  const notes = await notesEditor.save();
  console.log({ heading, content, notes });
  alert("Lecture saved! Check console for JSON output.");
});
