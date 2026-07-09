<?php
/**
 * core/helpers.php — fonctions utilitaires transverses.
 */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function slugify($text)
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = preg_replace('/[^a-zA-Z0-9]+/', '_', $text);
    $text = trim($text, '_');
    return strtolower($text);
}

function jsonResponse($success, $data = null, $error = null)
{
    $payload = array('success' => $success);
    if ($success) {
        $payload['data'] = $data;
    } else {
        $payload['error'] = $error;
    }
    $json = json_encode($payload);

    // Nettoie tout output parasite (warnings, BOM, echo oublié) avant d'émettre le JSON.
    if (ob_get_length() !== false) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

function nowIso()
{
    return date('Y-m-d H:i:s');
}
