<?php
/**
 * core/documents.php — gestion documentaire générique (documents/images liés
 * à n'importe quelle entité), avec contrôle de confidentialité systématique.
 */

require_once __DIR__ . '/confidentiality.php';

define('UPLOAD_ROOT', __DIR__ . '/../uploads');

/**
 * Upload sécurisé : vérification MIME réelle (finfo), taille max, nom de
 * fichier généré (jamais le nom original sur le disque). Reprend le pattern
 * handleUpload() déjà standardisé côté AlertMail.
 */
function uploadDocument($entityType, $entityId, array $file, $category, $confidentiality, array $allowedMimes = null, $maxBytes = 10485760)
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Fichier invalide.');
    }
    if ($file['size'] > $maxBytes) {
        throw new InvalidArgumentException('Fichier trop volumineux.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($allowedMimes !== null && !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('Type de fichier non autorisé : ' . $mime);
    }

    $dir = UPLOAD_ROOT . '/' . preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $entityType) . '/' . (int)$entityId;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $storedName = uniqid('doc_', true) . '_' . preg_replace('/[^a-zA-Z0-9_.\-]/', '_', basename($file['name']));
    $destination = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException("Échec de l'écriture du fichier.");
    }

    $user = currentUser();
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO documents
         (entity_type, entity_id, plugin_code, category, filename_original, filename_stored,
          mime_type, size_bytes, confidentiality, uploaded_by, uploaded_at, checksum)
         VALUES (:et, :eid, :plugin, :cat, :orig, :stored, :mime, :size, :conf, :uid, :now, :checksum)'
    );
    $stmt->execute(array(
        ':et' => $entityType, ':eid' => $entityId,
        ':plugin' => isset($file['plugin_code']) ? $file['plugin_code'] : null,
        ':cat' => $category, ':orig' => $file['name'],
        ':stored' => $entityType . '/' . (int)$entityId . '/' . $storedName,
        ':mime' => $mime, ':size' => $file['size'], ':conf' => $confidentiality,
        ':uid' => $user ? $user['id'] : null, ':now' => nowIso(),
        ':checksum' => hash_file('sha256', $destination),
    ));
    $docId = $pdo->lastInsertId();
    logAction('document_uploaded', $entityType . '#' . $entityId . ':' . $docId);
    return $docId;
}

function getEntityDocuments($entityType, $entityId, array $user)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE entity_type = :et AND entity_id = :eid ORDER BY uploaded_at DESC');
    $stmt->execute(array(':et' => $entityType, ':eid' => $entityId));

    $visible = array();
    foreach ($stmt->fetchAll() as $doc) {
        if (canView($doc['confidentiality'], $entityType, $entityId, $user) || hasExplicitGrant('document', $doc['id'], $user)) {
            $visible[] = $doc;
        }
    }
    return $visible;
}

function getDocumentById($id)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
    $stmt->execute(array(':id' => $id));
    return $stmt->fetch();
}

function deleteDocument($id, array $user)
{
    $doc = getDocumentById($id);
    if (!$doc) {
        return false;
    }
    if (!can($user['id'], 'core.admin') && (int)$doc['uploaded_by'] !== (int)$user['id']) {
        throw new RuntimeException('Suppression non autorisée.');
    }
    $path = UPLOAD_ROOT . '/' . $doc['filename_stored'];
    if (is_file($path)) {
        unlink($path);
    }
    $pdo = getPortailPdo();
    $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute(array(':id' => $id));
    logAction('document_deleted', (string)$id);
    return true;
}
