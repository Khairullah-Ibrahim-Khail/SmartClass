/**
 * SmartClass - Customers page: pause / support access / delete ask for what the server needs (a reason, or the domain
 * typed again), then send the hidden form #custActionForm. The server checks everything again.
 */
(function () {
  const form = document.getElementById("custActionForm");
  if (!form) return;
  function send(act, id, extra) {
    form.querySelector("[name=action]").value = act;
    form.querySelector("[name=customer_id]").value = id;
    form.querySelector("[name=reason]").value = extra.reason || "";
    form.querySelector("[name=confirm]").value = extra.confirm || "";
    form.submit();
  }
  document.querySelectorAll(".cust-act").forEach(function (b) {
    b.addEventListener("click", function () {
      const act = b.dataset.act, name = b.dataset.name, id = b.dataset.id;
      if (act === "suspend") {
        Swal.fire({ title: "Pause " + name + "?", text: "Their site shows \"service paused\" (their admin can still sign in and download a backup). Tell them why:",
          input: "text", inputPlaceholder: "Reason (shown to them)", showCancelButton: true, confirmButtonText: "Pause", confirmButtonColor: "#ef476f" })
          .then(function (r) { if (r.isConfirmed) send("suspend", id, { reason: r.value || "" }); });
      } else if (act === "support") {
        Swal.fire({ title: "Support access to " + name, text: "You get a one-time link into their admin side. The reason is written to their activity log and to yours.",
          input: "text", inputPlaceholder: "Why do you need to open it?", showCancelButton: true, confirmButtonText: "Create link", confirmButtonColor: "#4361ee",
          inputValidator: function (v) { return v && v.trim() ? null : "Write the reason"; } })
          .then(function (r) { if (r.isConfirmed) send("support", id, { reason: r.value }); });
      } else if (act === "delete") {
        Swal.fire({ icon: "warning", title: "Delete " + name + "?", html: "A final backup is saved first, then the database and files are removed.<br>Type <strong>" + b.dataset.domain.replace(/</g, "&lt;") + "</strong> to confirm.",
          input: "text", showCancelButton: true, confirmButtonText: "Delete", confirmButtonColor: "#ef476f",
          inputValidator: function (v) { return v === b.dataset.domain ? null : "Type the domain exactly"; } })
          .then(function (r) { if (r.isConfirmed) send("delete", id, { confirm: r.value }); });
      }
    });
  });
})();
