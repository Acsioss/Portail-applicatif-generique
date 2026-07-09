<?php
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/helpers.php';
require_once __DIR__ . '/../../../core/csrf.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../core/rbac.php';

requireLogin();
requireRole('referents_support.access');

$user = currentUser();
$domaines = array('DGS', 'Prévention', 'RH', 'Informatique', 'Bâtiments');

$pageTitle = 'Référents Support';
include __DIR__ . '/../../../shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <h2>Gestion des Référents Support</h2>
        <p class="text-secondary">DGS, Prévention, RH, Informatique, Bâtiments.</p>
        <div class="row row-cards">
            <?php foreach ($domaines as $d): ?>
            <div class="col-md-4">
                <div class="card"><div class="card-body"><h3><?php echo h($d); ?></h3></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../../shared/footer.php'; ?>
