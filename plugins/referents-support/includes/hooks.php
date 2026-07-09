<?php
function onActivate() {}
function onUserSync($user, $ldapGroups) {}
function onCron() {}

function resolveContactOrgUnit($entityId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT org_unit_id FROM plg_referents_support_contacts WHERE id = :id');
    $stmt->execute(array(':id' => $entityId));
    return $stmt->fetchColumn();
}
