<?php
function requireLogin($redirect = '/index.php') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['loggedin'])) {
        header("Location: $redirect");
        exit();
    }
}
