<?php
/**
 * core/csrf.php — protection CSRF standard sur tout formulaire POST.
 */

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrf()
{
    $sent = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $expected = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if ($expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(403);
        die('Jeton CSRF invalide.');
    }
}
