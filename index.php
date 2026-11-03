<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';
if (current_user()) { header('Location: lobby.php'); exit; }
page_head('Sign in');
?>
<div class="auth-page">
  <div class="auth-glow auth-glow-gold"></div>
  <div class="auth-glow auth-glow-emerald"></div>
  <div class="auth-card-wrap">
    <div class="auth-brand">
      <div class="suits">
        <i data-lucide="spade" class="gold"></i><i data-lucide="heart" class="red"></i><i data-lucide="diamond" class="red"></i><i data-lucide="club" class="gold"></i>
      </div>
      <h1 class="font-heading">Royal Hold'em</h1>
      <p>Live-video Texas Hold'em with friends</p>
    </div>

    <div class="glass auth-card gold-glow" data-testid="auth-form">
      <div class="tabs">
        <button class="tab active" data-tab="login" data-testid="login-tab">Log In</button>
        <button class="tab" data-tab="register" data-testid="register-tab">Sign Up</button>
      </div>
      <form id="auth-form" class="form">
        <div class="field register-only" hidden>
          <label>Display Name</label>
          <input class="input" name="name" placeholder="Ace McQueen" maxlength="24" data-testid="name-input">
        </div>
        <div class="field">
          <label>Email</label>
          <input class="input" name="email" type="email" placeholder="you@table.com" required data-testid="email-input">
        </div>
        <div class="field">
          <label>Password</label>
          <input class="input" name="password" type="password" placeholder="••••••••" required data-testid="password-input">
        </div>
        <p class="form-error" id="auth-error" hidden data-testid="auth-error"></p>
        <button type="submit" class="btn btn-gold btn-block" id="auth-submit" data-testid="login-submit-button">Enter the Room</button>
      </form>
    </div>
  </div>
</div>
<?php page_foot(['auth.js']); ?>
