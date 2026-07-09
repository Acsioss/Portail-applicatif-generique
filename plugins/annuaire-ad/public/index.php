<?php
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/helpers.php';
require_once __DIR__ . '/../../../core/csrf.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../core/rbac.php';
require_once __DIR__ . '/../../../core/org.php';

requireLogin();
requireRole('annuaire_ad.access');

$user = currentUser();
$services = getOrgUnits(array('type' => 'service'));
$pageTitle = 'Annuaire utilisateur AD';
include __DIR__ . '/../../../shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <h2>Annuaire utilisateur AD</h2>
        <p class="text-secondary">Organisation, Directions, Services, Responsables, Sites, Référents, Membres.</p>
        <p class="text-muted">
            Cette page consomme le référentiel du noyau (<code>core/org.php</code>) —
            elle ne recrée jamais sa propre copie des Directions/Services/Sites.
        </p>
    </div>
</div>
<?php include __DIR__ . '/../../../shared/footer.php'; ?>
