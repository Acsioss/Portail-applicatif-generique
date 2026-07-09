<?php
/**
 * core/confidentiality.php — moteur de confidentialité transverse
 * (public / service / privé), appliqué aux attributs EAV et aux documents.
 *
 * Décision retenue : portée "service" HÉRITÉE — un membre d'une Direction
 * voit le contenu "service" de tous les Services rattachés à cette Direction.
 */

require_once __DIR__ . '/org.php';

/**
 * Point d'entrée principal : un objet (attribut ou document) portant un niveau
 * de confidentialité est-il visible par $user ?
 */
function canView($confidentiality, $entityType, $entityId, array $user)
{
    if ($confidentiality === 'public') {
        return true;
    }

    if ($confidentiality === 'private') {
        if (can($user['id'], 'core.admin')) {
            return true;
        }
        if (isOwnerOfEntity($entityType, $entityId, $user['id'])) {
            return true;
        }
        return false; // les octrois explicites sont vérifiés par hasExplicitGrant() côté appelant (document/attribut précis)
    }

    // 'service' — portée héritée
    $ownerOrgUnitId = resolveOrgUnit($entityType, $entityId);
    if ($ownerOrgUnitId === null) {
        // Pas de portée résolvable : on retombe sur un accès admin uniquement, par prudence.
        return can($user['id'], 'core.admin');
    }
    return userBelongsToOrgUnitScope($user['org_unit_id'], $ownerOrgUnitId);
}

/**
 * Vrai si l'org_unit de l'utilisateur est celle du propriétaire, ou un ANCÊTRE
 * de celle du propriétaire (portée héritée : une Direction voit ses Services).
 */
function userBelongsToOrgUnitScope($userOrgUnitId, $ownerOrgUnitId)
{
    if ($userOrgUnitId === null || $ownerOrgUnitId === null) {
        return false;
    }
    if ((int)$userOrgUnitId === (int)$ownerOrgUnitId) {
        return true;
    }
    $ancestors = getOrgUnitAncestors($ownerOrgUnitId);
    return in_array((int)$userOrgUnitId, $ancestors, true);
}

/**
 * Résout l'org_unit propriétaire d'une entité via le résolveur déclaré dans
 * entity_types.org_scope_resolver ('core:fn' ou 'plugin_code:fn').
 */
function resolveOrgUnit($entityType, $entityId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT org_scope_resolver FROM entity_types WHERE code = :code');
    $stmt->execute(array(':code' => $entityType));
    $resolver = $stmt->fetchColumn();
    if (!$resolver) {
        return null;
    }
    list($namespace, $fn) = explode(':', $resolver, 2);
    if ($namespace === 'core' && function_exists($fn)) {
        return call_user_func($fn, $entityId);
    }
    // Résolveur de plugin : convention includes/hooks.php doit déjà être chargé par le plugin actif.
    if (function_exists($fn)) {
        return call_user_func($fn, $entityId);
    }
    return null;
}

function isOwnerOfEntity($entityType, $entityId, $userId)
{
    // Cas générique : le propriétaire d'une fiche utilisateur est l'utilisateur lui-même.
    if ($entityType === 'core.user') {
        return (int)$entityId === (int)$userId;
    }
    return false; // les plugins peuvent surcharger via un hook dédié si besoin
}

function hasExplicitGrant($targetType, $targetId, array $user)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        "SELECT grantee_type, grantee_id FROM confidentiality_grants
         WHERE target_type = :tt AND target_id = :tid"
    );
    $stmt->execute(array(':tt' => $targetType, ':tid' => $targetId));
    foreach ($stmt->fetchAll() as $grant) {
        if ($grant['grantee_type'] === 'user' && (int)$grant['grantee_id'] === (int)$user['id']) {
            return true;
        }
        if ($grant['grantee_type'] === 'role' && can($user['id'], $grant['grantee_id'])) {
            return true;
        }
        if ($grant['grantee_type'] === 'org_unit' && (int)$grant['grantee_id'] === (int)$user['org_unit_id']) {
            return true;
        }
    }
    return false;
}
