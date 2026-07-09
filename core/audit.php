<?php
/**
 * core/audit.php — journal d'audit transverse, appelable par le noyau et les plugins.
 */

function logAction($action, $target = null, $plugin_code = null)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (user_id, plugin_code, action, target, ip, created_at)
         VALUES (:user_id, :plugin_code, :action, :target, :ip, :created_at)'
    );
    $user = function_exists('currentUser') ? currentUser() : null;
    $stmt->execute(array(
        ':user_id'     => $user ? $user['id'] : null,
        ':plugin_code' => $plugin_code,
        ':action'      => $action,
        ':target'      => $target,
        ':ip'          => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        ':created_at'  => nowIso(),
    ));
}
