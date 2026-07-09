<?php
/**
 * core/mailer.php — envoi mail, y compris consolidation par manager
 * (pattern déjà standardisé côté script d'activation de comptes AD).
 */

function sendMail($to, $subject, $htmlBody)
{
    $config = require __DIR__ . '/../config/config.php';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . $config['mail']['from'] . "\r\n";
    return mail($to, $subject, $htmlBody, $headers);
}

/**
 * Regroupe une liste d'items par manager et envoie un mail consolidé par
 * destinataire, plutôt qu'un mail par item.
 */
function sendConsolidatedMails(array $itemsByManagerEmail, $subject, $renderCallback)
{
    $sent = 0;
    foreach ($itemsByManagerEmail as $managerEmail => $items) {
        $body = call_user_func($renderCallback, $items);
        if (sendMail($managerEmail, $subject, $body)) {
            $sent++;
        }
    }
    return $sent;
}
