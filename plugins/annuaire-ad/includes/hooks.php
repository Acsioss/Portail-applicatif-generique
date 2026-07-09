<?php
function onActivate() {}
function onUserSync($user, $ldapGroups) {}
function onCron() {}

/** Résolveur de portée pour l'entité "référent" déclarée par ce plugin. */
function resolveReferentOrgUnit($entityId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT org_unit_id FROM plg_annuaire_ad_referents WHERE id = :id');
    $stmt->execute(array(':id' => $entityId));
    return $stmt->fetchColumn();
}
