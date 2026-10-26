/* Pashto / Dari: translates what the browser itself puts on the page (SweetAlert pop-ups, inline form errors, toasts, AI-chat labels).
   Whole texts only — a text is changed when it EXACTLY matches a phrase in window.SC_DICT (assets/js/i18n-dict.php). Typed content is left alone. */
(function () {
    var dict = window.SC_DICT;
    if (!dict) return;
    var SKIP = /^(SCRIPT|STYLE|TEXTAREA|CODE|PRE|INPUT)$/;
    function skipNode(n) {
        for (var p = n.parentNode; p && p.nodeType === 1; p = p.parentNode) {
            if (SKIP.test(p.nodeName) || p.hasAttribute('data-no-i18n') || p.isContentEditable) return true;
        }
        return false;
    }
    function fix(text) {
        var t = text.trim();
        if (!t || !Object.prototype.hasOwnProperty.call(dict, t)) return null;
        return text.replace(t, dict[t]);
    }
    function walk(node) {
        if (node.nodeType === 3) {
            var r = fix(node.nodeValue);
            if (r !== null && !skipNode(node)) node.nodeValue = r;
            return;
        }
        if (node.nodeType !== 1 || SKIP.test(node.nodeName)) return;
        ['placeholder', 'title', 'aria-label', 'alt', 'data-tooltip', 'data-confirm', 'data-confirm-text', 'data-confirm-button'].forEach(function (a) {
            if (node.hasAttribute(a)) { var v = fix(node.getAttribute(a)); if (v !== null) node.setAttribute(a, v); }
        });
        for (var c = node.firstChild; c; c = c.nextSibling) walk(c);
    }
    walk(document.body || document.documentElement);   // texts the page's own scripts already changed before this ran
    var busy = false;
    new MutationObserver(function (list) {
        if (busy) return;
        busy = true;
        list.forEach(function (m) {
            if (m.type === 'characterData') { var r = fix(m.target.nodeValue); if (r !== null && !skipNode(m.target)) m.target.nodeValue = r; }
            else m.addedNodes.forEach(walk);
        });
        busy = false;
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
})();
