<?php
function onActivate() {}
function onUserSync($user, $ldapGroups) {}
function onCron() {}

/** Résolveur de portée pour l'entité "bâtiment" — hérite de l'org_unit du site parent. */
function resolveBuildingOrgUnit($entityId)
{
    // Ce plugin est en mode mysql_own : requêter sa propre base pour retrouver
    // le site_id du bâtiment, puis core/org.php::siteOrgUnitResolver($site_id).
    return null;
}
