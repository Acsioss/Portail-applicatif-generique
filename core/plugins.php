<?php
/**
 * core/plugins.php — découverte, activation, désactivation et construction
 * du menu/de la page d'accueil à partir des manifest.json des plugins.
 */

define('PLUGINS_ROOT', __DIR__ . '/../plugins');

function discoverPlugins()
{
    $found = array();
    foreach (glob(PLUGINS_ROOT . '/*/manifest.json') as $manifestPath) {
        $dir = dirname($manifestPath);
        if (basename($dir) === '_template') {
            continue;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest) {
            $manifest['_dir'] = $dir;
            $found[$manifest['id']] = $manifest;
        }
    }
    return $found;
}

function activatePlugin($code)
{
    $manifests = discoverPlugins();
    if (!isset($manifests[$code])) {
        throw new InvalidArgumentException("Plugin inconnu : $code");
    }
    $manifest = $manifests[$code];
    $pdo = getPortailPdo();

    // Migrations SQL du plugin (mode sqlite_shared).
    if (isset($manifest['db']['mode']) && $manifest['db']['mode'] === 'sqlite_shared') {
        $migrationsDir = $manifest['_dir'] . '/' . $manifest['db']['migrations_dir'];
        foreach (glob($migrationsDir . '/*.sql') as $sqlFile) {
            $pdo->exec(file_get_contents($sqlFile));
        }
    }

    // Permissions et rôles par défaut.
    if (!empty($manifest['permissions'])) {
        foreach ($manifest['permissions'] as $perm) {
            registerPermission($perm['code'], $perm['label'], $code);
        }
    }
    if (!empty($manifest['default_role_permissions'])) {
        foreach ($manifest['default_role_permissions'] as $roleCode => $permCodes) {
            registerRole($roleCode, $roleCode, $code, $permCodes);
        }
    }

    // Types d'entités déclarés (pour attributs/documents).
    if (!empty($manifest['entity_types'])) {
        foreach ($manifest['entity_types'] as $et) {
            $resolver = isset($et['org_scope_resolver']) ? $code . ':' . basename($et['org_scope_resolver']) : null;
            registerEntityType($et['code'], $et['label'], $code, $resolver);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO plugins (code, name, version, status, manifest_json, installed_at, activated_at)
         VALUES (:code, :name, :version, "active", :manifest, :now, :now)
         ON CONFLICT(code) DO UPDATE SET status = "active", activated_at = :now, manifest_json = :manifest'
    );
    $stmt->execute(array(
        ':code' => $code, ':name' => $manifest['name'], ':version' => $manifest['version'],
        ':manifest' => json_encode($manifest), ':now' => nowIso(),
    ));

    if (isset($manifest['hooks']['onActivate'])) {
        callPluginHook($manifest, 'onActivate');
    }
    logAction('plugin_activated', $code);
}

function deactivatePlugin($code)
{
    $pdo = getPortailPdo();
    $pdo->prepare('UPDATE plugins SET status = "inactive" WHERE code = :code')->execute(array(':code' => $code));
    logAction('plugin_deactivated', $code);
}

function getActivePlugins()
{
    $pdo = getPortailPdo();
    $stmt = $pdo->query('SELECT * FROM plugins WHERE status = "active" ORDER BY name');
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['manifest'] = json_decode($row['manifest_json'], true);
    }
    return $rows;
}

/** Cartes de la page d'accueil, filtrées par habilitation de l'utilisateur. */
function getVisiblePluginCards(array $user)
{
    $cards = array();
    foreach (getActivePlugins() as $plugin) {
        $manifest = $plugin['manifest'];
        $accessPerm = $plugin['code'] . '.access';
        if (!can($user['id'], $accessPerm) && !can($user['id'], 'core.admin')) {
            continue;
        }
        $cards[] = $manifest;
    }
    return $cards;
}

function callPluginHook($manifest, $hookName)
{
    if (empty($manifest['hooks'][$hookName])) {
        return null;
    }
    list($file, $fn) = explode('::', $manifest['hooks'][$hookName]);
    $fullPath = $manifest['_dir'] . '/' . $file;
    if (is_file($fullPath)) {
        require_once $fullPath;
        if (function_exists($fn)) {
            return call_user_func($fn);
        }
    }
    return null;
}
