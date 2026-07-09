<?php
/**
 * download.php — point de sortie unique pour les documents non publics :
 * revérifie canView() à chaque téléchargement (pas de lien statique direct).
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/confidentiality.php';
require_once __DIR__ . '/core/documents.php';

requireLogin();
$user = currentUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$doc = getDocumentById($id);
if (!$doc) {
    http_response_code(404);
    die('Document introuvable.');
}

$allowed = canView($doc['confidentiality'], $doc['entity_type'], $doc['entity_id'], $user)
    || hasExplicitGrant('document', $doc['id'], $user);
if (!$allowed) {
    http_response_code(403);
    die('Accès refusé.');
}

$path = UPLOAD_ROOT . '/' . $doc['filename_stored'];
if (!is_file($path)) {
    http_response_code(404);
    die('Fichier manquant sur le disque.');
}

logAction('document_downloaded', (string)$id);
header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: attachment; filename="' . basename($doc['filename_original']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
