<?php
/**
 * core/auth.php — session, connexion, garde d'accès.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';

function startPortailSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function currentUser()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function requireLogin()
{
    startPortailSession();
    if (!currentUser()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Tente une authentification : LDAP en priorité, fallback compte local
 * (comptes techniques stockés dans users.password_hash) si le login LDAP échoue.
 */
function attemptLogin($login, $password)
{
    startPortailSession();

    $ldapData = authenticateLdap($login, $password);
    if ($ldapData !== false) {
        $userId = syncUserFromLdap($login, $ldapData);
        session_regenerate_id(true);
        $_SESSION['user'] = loadUserById($userId);
        logAction('login', $login);
        return true;
    }

    // Fallback local (comptes de service uniquement)
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE login = :login AND source = "local" AND active = 1');
    $stmt->execute(array(':login' => $login));
    $localUser = $stmt->fetch();
    if ($localUser && $localUser['password_hash'] && password_verify($password, $localUser['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = loadUserById($localUser['id']);
        logAction('login_local', $login);
        return true;
    }

    logAction('login_failed', $login);
    return false;
}

function loadUserById($userId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(array(':id' => $userId));
    $user = $stmt->fetch();
    if ($user) {
        $user['roles'] = getUserRoles($userId);
    }
    return $user;
}

function logoutUser()
{
    startPortailSession();
    if (currentUser()) {
        logAction('logout', currentUser()['login']);
    }
    $_SESSION = array();
    session_destroy();
}
