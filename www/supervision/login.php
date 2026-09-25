<?php
require_once '/var/www/common/init.php';
require_once '/var/www/common/login_handler.php';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if (ldapLogin($username, $password)) {
    session_regenerate_id(true);
    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = $username;
    header('Location: dashboard.php');
    exit();
}
$errorMsg = __('login_invalid_credentials', "Nom d'utilisateur ou mot de passe incorrect.");
header('Location: index.php?error=' . urlencode($errorMsg));
exit();
