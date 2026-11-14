/*
 * HIMS small interface helpers
 * - Phone / tablet menu: the sidebar slides in from the left (☰ button in the top bar).
 * - Upload boxes (.upload-drop): show the chosen file name, accept drag and drop, check the size.
 */
(function () {
  "use strict";

  // ---------------------------------------------------------------- phone menu
  const toggle = document.getElementById("navToggle");
  const backdrop = document.querySelector("[data-nav-close]");
  function setOpen(open) {
    document.body.classList.toggle("nav-open", open);
    if (toggle) {
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    }
    if (backdrop) backdrop.hidden = !open;
  }
  if (toggle) toggle.addEventListener("click", () => setOpen(!document.body.classList.contains("nav-open")));
  if (backdrop) backdrop.addEventListener("click", () => setOpen(false));
  document.addEventListener("keydown", (e) => { if (e.key === "Escape" && document.body.classList.contains("nav-open")) setOpen(false); });
  // Choosing a page closes the menu.
  document.addEventListener("click", (e) => {
    if (e.target.closest && e.target.closest(".sidebar .nav-item") && document.body.classList.contains("nav-open")) setOpen(false);
  });
  window.matchMedia("(min-width: 901px)").addEventListener("change", (m) => { if (m.matches) setOpen(false); });

  // ---------------------------------------------------------------- scrollbars
  // Show the (green) scrollbar while a list is being scrolled, then hide it again.
  const timers = new WeakMap();
  document.addEventListener("scroll", (e) => {
    const el = e.target;
    if (!el || !el.classList) return;
    el.classList.add("is-scrolling");
    clearTimeout(timers.get(el));
    timers.set(el, setTimeout(() => el.classList.remove("is-scrolling"), 900));
  }, true);

  // ---------------------------------------------------------------- upload boxes
  const MAX = 5 * 1024 * 1024;
  function describe(box) {
    const input = box.querySelector('input[type="file"]');
    const label = box.querySelector("[data-upload-label]");
    if (!input || !label) return;
    if (!label.dataset.empty) label.dataset.empty = label.textContent;
    const file = input.files && input.files[0];
    if (file && file.size > MAX) {
      (window.showWarningToast || alert)("The file is larger than 5 MB. Choose a smaller file.");
      input.value = "";
    }
    const chosen = input.files && input.files[0];
    label.textContent = chosen ? `${chosen.name} · ${(chosen.size / 1024 / 1024).toFixed(2)} MB` : label.dataset.empty;
    box.classList.toggle("has-file", !!chosen);
  }
  document.querySelectorAll(".upload-drop").forEach((box) => {
    const input = box.querySelector('input[type="file"]');
    if (!input) return;
    input.addEventListener("change", () => describe(box));
    // When a form is cleared after saving (input.value = ""), reset the text too.
    document.addEventListener("click", (e) => { if (e.target.closest && e.target.closest(".form-actions")) setTimeout(() => describe(box), 500); });
    ["dragenter", "dragover"].forEach((t) => box.addEventListener(t, (e) => { e.preventDefault(); box.classList.add("is-over"); }));
    ["dragleave", "drop"].forEach((t) => box.addEventListener(t, (e) => { e.preventDefault(); box.classList.remove("is-over"); }));
    box.addEventListener("drop", (e) => {
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      input.files = e.dataTransfer.files;
      describe(box);
    });
  });
})();
