<?php
/**
 * includes/hooks.php — points d'entrée appelés par le noyau (core/plugins.php).
 */

function onActivate()
{
    // Initialisation ponctuelle à l'activation (au-delà des migrations SQL).
}

function onUserSync($user, $ldapGroups)
{
    // Recalcul de données propres au plugin, dépendant du profil AD de l'utilisateur.
}

function onCron()
{
    // Tâche planifiée du plugin (appelée par le cron noyau).
}

/**
 * Exemple de résolveur de portée org pour un type d'entité déclaré par ce
 * plugin (référencé dans manifest.entity_types[].org_scope_resolver).
 */
function resolveTemplateEntityOrgUnit($entityId)
{
    // return (int) org_unit_id propriétaire de l'entité $entityId ;
    return null;
}
