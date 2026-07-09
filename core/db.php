<?php
/**
 * core/db.php
 * Connexion SQLite (config/référentiel/RBAC du portail) + factory PDO pour les
 * plugins qui utilisent une base MySQL/MariaDB dédiée (manifest.db.mode = "mysql_own").
 * Compatible PHP 5.5+ : pas de types scalaires, pas de null coalescing.
 */

function getPortailPdo()
{
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/../config/config.php';
        $dsn = 'sqlite:' . $config['sqlite_path'];
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/**
 * Connexion dédiée pour un plugin déclarant manifest.db.mode = "mysql_own".
 * Les paramètres de connexion vivent dans config/config.php (jamais en dur
 * dans le plugin), sous config['plugin_db'][$plugin_code].
 */
function getPluginPdo($plugin_code)
{
    static $connections = array();
    if (!isset($connections[$plugin_code])) {
        $config = require __DIR__ . '/../config/config.php';
        if (!isset($config['plugin_db'][$plugin_code])) {
            throw new RuntimeException("Aucune connexion BDD déclarée pour le plugin '$plugin_code'");
        }
        $c = $config['plugin_db'][$plugin_code];
        $dsn = "mysql:host={$c['host']};dbname={$c['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connections[$plugin_code] = $pdo;
    }
    return $connections[$plugin_code];
}
