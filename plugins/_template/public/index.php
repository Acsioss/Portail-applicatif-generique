<?php
/**
 * public/index.php — point d'entrée du plugin (déclaré dans manifest.entry_point).
 */
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/helpers.php';
require_once __DIR__ . '/../../../core/csrf.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../core/rbac.php';

requireLogin();
requireRole('plugin_template.access');

$user = currentUser();
$pageTitle = 'Nom du plugin';
include __DIR__ . '/../../../shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <h2>Nom du plugin</h2>
        <p class="text-secondary">Gabarit de départ — remplacer par le contenu métier du plugin.</p>
    </div>
</div>
<?php include __DIR__ . '/../../../shared/footer.php'; ?>
