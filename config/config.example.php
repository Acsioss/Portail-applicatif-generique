<?php
/**
 * config/config.example.php
 * Copier ce fichier en config/config.php (non versionné) et renseigner les
 * valeurs réelles de l'environnement. Ne jamais commiter config.php.
 */
return array(
    'sqlite_path' => __DIR__ . '/../db/portail.sqlite',

    'ldap' => array(
        'host'          => 'ldap://ad.exemple.local',
        'port'          => 389,
        'base_dn'       => 'DC=exemple,DC=local',
        'bind_dn'       => 'CN=svc-portail,OU=ServiceAccounts,DC=exemple,DC=local',
        'bind_password' => 'CHANGE_ME',
    ),

    'glpi' => array(
        'api_url'       => 'https://glpi.exemple.local/apirest.php',
        'client_id'     => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
    ),

    'mail' => array(
        'from' => 'portail@exemple.local',
    ),

    // Connexions dédiées pour les plugins déclarant manifest.db.mode = "mysql_own"
    'plugin_db' => array(
        // 'sites-geo' => array('host' => 'localhost', 'dbname' => 'sites_geo', 'user' => 'x', 'password' => 'x'),
    ),
);
