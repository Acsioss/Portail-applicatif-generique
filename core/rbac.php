<?php
/**
 * core/rbac.php — moteur d'habilitation.
 * Décision retenue : RBAC GLOBAL PAR PLUGIN (pas de scope org_unit/site ici ;
 * la granularité fine est portée par le module de confidentialité, cf. confidentiality.php).
 */

/**
 * Vérifie si un utilisateur possède une permission donnée (via ses rôles directs
 * ou hérités d'un mapping groupe AD).
 */
function can($userId, $permissionCode)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM user_roles ur
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE ur.user_id = :uid AND p.code = :perm'
    );
    $stmt->execute(array(':uid' => $userId, ':perm' => $permissionCode));
    return (int)$stmt->fetchColumn() > 0;
}

function requireRole($permissionCode)
{
    $user = currentUser();
    if (!$user || !can($user['id'], $permissionCode)) {
        http_response_code(403);
        die('Accès refusé : permission requise (' . h($permissionCode) . ').');
    }
}

function getUserRoles($userId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid'
    );
    $stmt->execute(array(':uid' => $userId));
    return $stmt->fetchAll();
}

function assignRole($userId, $roleId, $source = 'direct')
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO user_roles (user_id, role_id, source) VALUES (:uid, :rid, :src)'
    );
    $stmt->execute(array(':uid' => $userId, ':rid' => $roleId, ':src' => $source));
}

/**
 * Recalcule les rôles "ad_group" de l'utilisateur à partir de ses groupes AD
 * courants, sans toucher aux rôles attribués directement ('source' = 'direct').
 */
function syncUserRolesFromAdGroups($userId, array $adGroupDns)
{
    $pdo = getPortailPdo();

    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :uid AND source = "ad_group"')
        ->execute(array(':uid' => $userId));

    if (empty($adGroupDns)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($adGroupDns), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT role_id FROM ad_group_role_mapping WHERE ad_group_dn IN ($placeholders)"
    );
    $stmt->execute($adGroupDns);
    $roleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($roleIds as $roleId) {
        assignRole($userId, $roleId, 'ad_group');
    }
}

/** Enregistre un rôle et ses permissions par défaut, appelé à l'activation d'un plugin. */
function registerRole($code, $label, $pluginCode, array $permissionCodes = array())
{
    $pdo = getPortailPdo();
    $pdo->prepare('INSERT OR IGNORE INTO roles (code, label, plugin_code, is_system) VALUES (:c, :l, :p, 0)')
        ->execute(array(':c' => $code, ':l' => $label, ':p' => $pluginCode));

    $roleId = $pdo->query('SELECT id FROM roles WHERE code = ' . $pdo->quote($code))->fetchColumn();

    foreach ($permissionCodes as $permCode) {
        $permId = $pdo->query('SELECT id FROM permissions WHERE code = ' . $pdo->quote($permCode))->fetchColumn();
        if ($permId) {
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (:r, :p)')
                ->execute(array(':r' => $roleId, ':p' => $permId));
        }
    }
    return $roleId;
}

function registerPermission($code, $label, $pluginCode)
{
    $pdo = getPortailPdo();
    $pdo->prepare('INSERT OR IGNORE INTO permissions (code, label, plugin_code) VALUES (:c, :l, :p)')
        ->execute(array(':c' => $code, ':l' => $label, ':p' => $pluginCode));
}
