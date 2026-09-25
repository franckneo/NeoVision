<?php
if (session_status() === PHP_SESSION_NONE) {
    date_default_timezone_set('Europe/Brussels');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieDomain = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
    session_set_cookie_params([
        'lifetime' => 28800,
        'path' => '/',
        'domain' => $cookieDomain,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure
    ]);
    session_start();
}
$isDark = ($_COOKIE['darkMode'] ?? '') === 'enabled';
$allowedLang = ['fr', 'en', 'it', 'es', 'de', 'nl', 'pt'];
$defaultLang = 'fr';
if (isset($_GET['lang'])) {
    $candidate = (string)$_GET['lang'];
    if (in_array($candidate, $allowedLang, true)) {
        $_SESSION['lang'] = $candidate;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $cookieDomain = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
        setcookie('lang', $candidate, time() + 30*24*3600, '/', $cookieDomain, $secure, true);
    }
}
if (empty($_SESSION['lang']) && !empty($_COOKIE['lang'])) {
    $cookieLang = (string)$_COOKIE['lang'];
    if (in_array($cookieLang, $allowedLang, true)) {
        $_SESSION['lang'] = $cookieLang;
    }
}
$currentLang = $_SESSION['lang'] ?? $defaultLang;
$LANG = [];
$langFile = __DIR__ . "/lang/{$currentLang}.json";
if (file_exists($langFile)) {
    $decoded = json_decode(file_get_contents($langFile), true);
    if (is_array($decoded)) $LANG = $decoded;
}
function __($key, $default = '') {
    global $LANG;
    return $LANG[$key] ?? ($default !== '' ? $default : $key);
}
$_currentLang = $currentLang;
