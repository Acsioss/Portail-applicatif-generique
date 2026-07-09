<?php
/**
 * core/glpi_connector.php — wrapper mutualisé pour l'API REST GLPI (OAuth2),
 * réutilisé par les plugins (ex. Référents Support pour les liens Formcreator).
 */

function glpiGetSessionToken()
{
    if (!empty($_SESSION['glpi_token']) && !empty($_SESSION['glpi_token_expires']) && $_SESSION['glpi_token_expires'] > time()) {
        return $_SESSION['glpi_token'];
    }
    $config = require __DIR__ . '/../config/config.php';
    $ch = curl_init($config['glpi']['api_url'] . '/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'grant_type'    => 'client_credentials',
        'client_id'     => $config['glpi']['client_id'],
        'client_secret' => $config['glpi']['client_secret'],
        'scope'         => 'api',
    )));
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('Échec authentification GLPI.');
    }
    $_SESSION['glpi_token'] = $data['access_token'];
    $_SESSION['glpi_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] - 30 : 300);
    return $_SESSION['glpi_token'];
}

function glpiRequest($method, $endpoint, $payload = null)
{
    $config = require __DIR__ . '/../config/config.php';
    $token = glpiGetSessionToken();
    $ch = curl_init($config['glpi']['api_url'] . $endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ));
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $httpCode, 'data' => json_decode($response, true));
}
