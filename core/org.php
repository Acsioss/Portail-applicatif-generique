<?php
/**
 * core/org.php — accès en lecture au référentiel organisationnel partagé
 * (Directions, Services, Sites, Utilisateurs). Les plugins consomment ce
 * référentiel, ils ne le dupliquent jamais.
 */

function getOrgUnits(array $filters = array())
{
    $pdo = getPortailPdo();
    $sql = 'SELECT * FROM org_units WHERE 1=1';
    $params = array();
    if (isset($filters['type'])) {
        $sql .= ' AND type = :type';
        $params[':type'] = $filters['type'];
    }
    if (isset($filters['parent_id'])) {
        $sql .= ' AND parent_id = :parent_id';
        $params[':parent_id'] = $filters['parent_id'];
    }
    $sql .= ' ORDER BY name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getOrgUnit($id)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM org_units WHERE id = :id');
    $stmt->execute(array(':id' => $id));
    return $stmt->fetch();
}

function getSite($id)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = :id');
    $stmt->execute(array(':id' => $id));
    return $stmt->fetch();
}

/**
 * Remonte la chaîne de parenté d'une org_unit jusqu'à la racine.
 * Utilisé par confidentiality.php pour la portée "service" héritée.
 */
function getOrgUnitAncestors($orgUnitId)
{
    $pdo = getPortailPdo();
    $ancestors = array();
    $current = $orgUnitId;
    $guard = 0;
    while ($current !== null && $guard < 50) {
        $stmt = $pdo->prepare('SELECT parent_id FROM org_units WHERE id = :id');
        $stmt->execute(array(':id' => $current));
        $parent = $stmt->fetchColumn();
        if ($parent === false || $parent === null) {
            break;
        }
        $ancestors[] = (int)$parent;
        $current = (int)$parent;
        $guard++;
    }
    return $ancestors;
}

/** Résolveurs de portée org pour les entités noyau (référencés dans entity_types.org_scope_resolver). */
function orgUnitSelfResolver($entityId) { return (int)$entityId; }

function siteOrgUnitResolver($entityId)
{
    $site = getSite($entityId);
    return $site ? $site['org_unit_id'] : null;
}

function userOrgUnitResolver($entityId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT org_unit_id FROM users WHERE id = :id');
    $stmt->execute(array(':id' => $entityId));
    return $stmt->fetchColumn();
}
