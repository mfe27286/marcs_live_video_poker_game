// Shared UI helpers: toasts, icons, dialogs
function toast(message, type = "default", duration = 3500) {
  const box = document.getElementById("toasts");
  const el = document.createElement("div");
  el.className = `toast glass ${type}`;
  el.textContent = message;
  el.setAttribute("data-testid", `toast-${type}`);
  box.appendChild(el);
  setTimeout(() => { el.style.opacity = "0"; el.style.transition = "opacity .3s"; setTimeout(() => el.remove(), 300); }, duration);
}

function refreshIcons(root) {
  if (window.lucide) window.lucide.createIcons({ attrs: { class: "lucide" } });
}

function fmt(n) { return Number(n || 0).toLocaleString(); }

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

function initials(name) {
  return (name || "?").split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();
}

document.addEventListener("DOMContentLoaded", () => {
  refreshIcons();
  document.querySelectorAll("dialog [data-close]").forEach((b) => b.addEventListener("click", () => b.closest("dialog").close()));
  document.querySelectorAll("dialog").forEach((d) => d.addEventListener("click", (e) => { if (e.target === d) d.close(); }));
});
