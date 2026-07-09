<?php
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/helpers.php';
require_once __DIR__ . '/../../../core/csrf.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../core/rbac.php';
require_once __DIR__ . '/../../../core/org.php';

requireLogin();
requireRole('sites_geo.access');

$user = currentUser();
// Exemple : $site = getSite($_GET['site_id']); (core/org.php) puis lecture des
// données étendues (plans, extrusion 3D) dans la base dédiée du plugin (mysql_own).
$pageTitle = 'Gestion des Sites géographiques';
include __DIR__ . '/../../../shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <h2>Gestion des Sites géographiques</h2>
        <p class="text-secondary">Plans géo-référencés (2D/3D) — la donnée de base "site" vient du noyau (<code>core.site</code>).</p>
    </div>
</div>
<?php include __DIR__ . '/../../../shared/footer.php'; ?>
