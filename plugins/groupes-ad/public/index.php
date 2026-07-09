<?php
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/helpers.php';
require_once __DIR__ . '/../../../core/csrf.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../core/rbac.php';

requireLogin();
requireRole('groupes_ad.access');

$user = currentUser();
$canManageMapping = can($user['id'], 'groupes_ad.manage_mapping');

$pdo = getPortailPdo();
$mappings = $pdo->query(
    'SELECT m.id, m.ad_group_dn, r.code AS role_code, r.label
     FROM ad_group_role_mapping m JOIN roles r ON r.id = m.role_id ORDER BY m.ad_group_dn'
)->fetchAll();

$pageTitle = 'Gestion des Groupes AD';
include __DIR__ . '/../../../shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <h2>Gestion des Groupes AD</h2>
        <p class="text-secondary">Organisationnel, Localisation, Missions, Accès, Confidentialité.</p>
        <p class="text-muted">
            Ce plugin pilote le moteur RBAC du noyau (<code>core/rbac.php</code>,
            table <code>ad_group_role_mapping</code>) — il n'implémente pas son propre
            système de permissions.
        </p>
        <table class="table table-vcenter card-table">
            <thead><tr><th>Groupe AD</th><th>Rôle attribué</th></tr></thead>
            <tbody>
                <?php foreach ($mappings as $m): ?>
                <tr><td><?php echo h($m['ad_group_dn']); ?></td><td><?php echo h($m['label']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../../shared/footer.php'; ?>
