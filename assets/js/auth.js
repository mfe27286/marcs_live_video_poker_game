// Login / signup page
(function () {
  let tab = "login";
  const form = document.getElementById("auth-form");
  const errorEl = document.getElementById("auth-error");
  const submit = document.getElementById("auth-submit");
  const nameField = form.querySelector(".register-only");
  const nameInput = form.querySelector('[name="name"]');

  document.querySelectorAll(".tab").forEach((btn) => {
    btn.addEventListener("click", () => {
      tab = btn.dataset.tab;
      document.querySelectorAll(".tab").forEach((b) => b.classList.toggle("active", b === btn));
      nameField.hidden = tab !== "register";
      nameInput.required = tab === "register";
      submit.textContent = tab === "login" ? "Enter the Room" : "Create Account";
      submit.setAttribute("data-testid", tab === "login" ? "login-submit-button" : "register-submit-button");
      errorEl.hidden = true;
    });
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    errorEl.hidden = true;
    submit.disabled = true;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      await API.post(`/auth.php?action=${tab}`, data);
      toast("Welcome to the table", "success");
      window.location.href = (window.BASE_PATH || "") + "/lobby.php";
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.hidden = false;
    } finally {
      submit.disabled = false;
    }
  });
})();
