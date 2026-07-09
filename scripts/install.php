<?php
/**
 * scripts/install.php — initialise la base SQLite du portail à partir de db/schema.sql.
 * Usage : php scripts/install.php
 */
$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config/config.php introuvable. Copiez config/config.example.php puis adaptez-le.\n");
    exit(1);
}
$config = require $configPath;

$pdo = new PDO('sqlite:' . $config['sqlite_path']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));

echo "Base SQLite initialisée : {$config['sqlite_path']}\n";
