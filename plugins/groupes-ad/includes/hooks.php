<?php
function onActivate() {}
function onUserSync($user, $ldapGroups) {}
function onCron() {}

function resolveGroupOrgUnit($entityId)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT org_unit_id FROM plg_groupes_ad_groups WHERE id = :id');
    $stmt->execute(array(':id' => $entityId));
    return $stmt->fetchColumn();
}
