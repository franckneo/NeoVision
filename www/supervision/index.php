<?php
require_once '/var/www/common/init.php';
if (!empty($_SESSION['loggedin'])) {
    header('Location: dashboard.php');
    exit();
}
$error = $_GET['error'] ?? '';
$lang = $currentLang ?? 'fr';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<title><?= __('login_page_title', 'Connexion Supervision') ?></title>
<link rel="icon" href="favicon.ico">
<link rel="stylesheet" href="/assets/style.css?v=3">
</head>
<body class="login-page <?= $isDark ? 'dark-mode' : '' ?>">
<div class="header">
  <div></div>
  <div class="header-buttons">
    <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
  </div>
</div>
<div class="login-container">
<h2><?= __('login_heading', 'Connexion') ?></h2>
<img class="logo" src="<?= $isDark ? 'logonuit.png' : 'neovision.png' ?>" alt="Logo">
<form action="login.php" method="post">
<input type="text" name="username" placeholder="<?= __('placeholder_username', 'Identifiant') ?>" required>
<input type="password" name="password" placeholder="<?= __('placeholder_password', 'Mot de passe') ?>" required>
<button type="submit"><?= __('btn_login', 'Se connecter') ?></button>
</form>
<p class="error-message"><?= htmlspecialchars($error) ?></p>
</div>
<script src="/assets/darkmode.js"></script>
</body>
</html>
