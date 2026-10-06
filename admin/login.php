<?php
declare(strict_types=1);
// Без _guard: страница входа. Если уже авторизован — на дашборд.
require_once __DIR__ . '/../api/helpers.php';
if (current_user()) { header('Location: index.php'); exit; }
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход · Завод Редукторов CRM</title>
<?php $cv = @filemtime(__DIR__ . '/assets/admin.css') ?: '1'; ?><link rel="stylesheet" href="assets/admin.css?v=<?= $cv ?>">
<style>
  .login-card .input{color:#1e293b!important;background:#fff!important}
  .pw-wrap{position:relative}
  .pw-wrap .input{padding-right:46px}
  .pw-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;cursor:pointer;font-size:18px;line-height:1;opacity:.55;padding:6px;border-radius:8px}
  .pw-eye:hover{opacity:1;background:#f1f5f9}
</style>
</head>
<body class="login-body">
<form class="login-card" id="login-form" autocomplete="on">
  <div class="login-card__brand"><span class="sidebar__logo">ЗР</span></div>
  <h1 class="login-card__title">Завод Редукторов</h1>
  <p class="login-card__subtitle">Панель управления CRM</p>

  <label class="field">
    <span class="field__label">Логин</span>
    <input class="input" type="text" name="login" id="login" required autofocus autocomplete="username">
  </label>

  <label class="field">
    <span class="field__label">Пароль</span>
    <div class="pw-wrap">
      <input class="input" type="password" name="password" id="password" required autocomplete="current-password">
      <button type="button" class="pw-eye" id="pw-eye" aria-label="Показать пароль" title="Показать пароль">👁</button>
    </div>
  </label>

  <div class="login-card__error" id="login-error" hidden></div>

  <button class="btn btn--primary btn--block" type="submit" id="login-btn">Войти</button>
</form>

<script>
(function () {
  var form = document.getElementById('login-form');
  var err  = document.getElementById('login-error');
  var btn  = document.getElementById('login-btn');

  // показать/скрыть пароль
  var eye = document.getElementById('pw-eye');
  var pw  = document.getElementById('password');
  if (eye && pw) {
    eye.addEventListener('click', function () {
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      eye.textContent = show ? '🙈' : '👁';
      eye.title = show ? 'Скрыть пароль' : 'Показать пароль';
      pw.focus();
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true;
    btn.disabled = true;
    btn.textContent = 'Вход…';

    var data = new FormData();
    data.append('action', 'login');
    data.append('login', document.getElementById('login').value.trim());
    data.append('password', document.getElementById('password').value);

    fetch('../api/auth.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Ошибка сервера' }; }); })
      .then(function (res) {
        if (res && res.ok) {
          window.location.href = 'index.php';
          return;
        }
        err.textContent = (res && res.error) ? res.error : 'Не удалось войти';
        err.hidden = false;
        btn.disabled = false;
        btn.textContent = 'Войти';
      })
      .catch(function () {
        err.textContent = 'Сеть недоступна';
        err.hidden = false;
        btn.disabled = false;
        btn.textContent = 'Войти';
      });
  });
})();
</script>
</body>
</html>
