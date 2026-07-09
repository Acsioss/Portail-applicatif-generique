<?php
/**
 * core/ldap.php — bind LDAP/AD, résolution des groupes, synchronisation légère
 * de l'utilisateur et de son unité organisationnelle dans le référentiel noyau.
 */

function ldapConnect()
{
    $config = require __DIR__ . '/../config/config.php';
    $conn = ldap_connect($config['ldap']['host'], $config['ldap']['port']);
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    return $conn;
}

/**
 * Authentifie via bind LDAP direct. Retourne le DN résolu si succès, false sinon.
 */
function authenticateLdap($login, $password)
{
    if ($password === '') {
        return false; // évite un bind anonyme accidentel
    }
    $config = require __DIR__ . '/../config/config.php';
    $conn = ldapConnect();

    // Résolution du DN via un compte de service en lecture seule.
    if (!@ldap_bind($conn, $config['ldap']['bind_dn'], $config['ldap']['bind_password'])) {
        return false;
    }
    $filter = '(sAMAccountName=' . ldap_escape($login, '', LDAP_ESCAPE_FILTER) . ')';
    $search = ldap_search($conn, $config['ldap']['base_dn'], $filter, array('dn', 'displayName', 'mail', 'memberOf', 'department'));
    if (!$search) {
        return false;
    }
    $entries = ldap_get_entries($conn, $search);
    if ($entries['count'] < 1) {
        return false;
    }
    $userDn = $entries[0]['dn'];

    // Bind avec les identifiants de l'utilisateur pour vérifier le mot de passe.
    if (!@ldap_bind($conn, $userDn, $password)) {
        return false;
    }

    return array(
        'dn'           => $userDn,
        'display_name' => isset($entries[0]['displayname'][0]) ? $entries[0]['displayname'][0] : $login,
        'email'        => isset($entries[0]['mail'][0]) ? $entries[0]['mail'][0] : null,
        'groups'       => isset($entries[0]['memberof']) ? array_slice($entries[0]['memberof'], 0, -1) : array(),
    );
}

/**
 * Recherche LDAP générique ponctuelle (hors référentiel synchronisé), réservée
 * aux besoins non couverts par le cache — les plugins ne doivent pas en abuser.
 */
function ldapSearch($filter, $attributes = array())
{
    $config = require __DIR__ . '/../config/config.php';
    $conn = ldapConnect();
    if (!@ldap_bind($conn, $config['ldap']['bind_dn'], $config['ldap']['bind_password'])) {
        return array();
    }
    $search = ldap_search($conn, $config['ldap']['base_dn'], $filter, $attributes);
    if (!$search) {
        return array();
    }
    return ldap_get_entries($conn, $search);
}

/**
 * Synchronise (upsert) l'utilisateur courant dans la table users à partir des
 * données LDAP renvoyées par authenticateLdap().
 */
function syncUserFromLdap($login, array $ldapData)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE login = :login');
    $stmt->execute(array(':login' => $login));
    $existing = $stmt->fetch();

    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE users SET ldap_dn = :dn, display_name = :name, email = :email,
             last_login = :now, active = 1 WHERE id = :id'
        );
        $upd->execute(array(
            ':dn' => $ldapData['dn'], ':name' => $ldapData['display_name'],
            ':email' => $ldapData['email'], ':now' => nowIso(), ':id' => $existing['id'],
        ));
        $userId = $existing['id'];
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO users (login, ldap_dn, display_name, email, source, active, last_login)
             VALUES (:login, :dn, :name, :email, "ldap", 1, :now)'
        );
        $ins->execute(array(
            ':login' => $login, ':dn' => $ldapData['dn'], ':name' => $ldapData['display_name'],
            ':email' => $ldapData['email'], ':now' => nowIso(),
        ));
        $userId = $pdo->lastInsertId();
    }

    if (function_exists('syncUserRolesFromAdGroups')) {
        syncUserRolesFromAdGroups($userId, $ldapData['groups']);
    }

    return $userId;
}
