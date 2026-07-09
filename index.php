<?php
/**
 * index.php — page d'accueil : cartes des plugins disponibles pour l'utilisateur.
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/plugins.php';

requireLogin();
$user = currentUser();
$cards = getVisiblePluginCards($user);
$isAdmin = can($user['id'], 'core.admin');

$pageTitle = 'Accueil';
include __DIR__ . '/shared/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row row-cards">
            <?php if (empty($cards)): ?>
                <div class="col-12">
                    <div class="alert alert-info">Aucune application disponible pour votre profil.</div>
                </div>
            <?php endif; ?>
            <?php foreach ($cards as $plugin): ?>
            <div class="col-md-4">
                <div class="card plugin-card">
                    <img src="/plugins/<?php echo h($plugin['id']); ?>/<?php echo h($plugin['illustration']); ?>"
                         class="card-img-top" alt="<?php echo h($plugin['name']); ?>">
                    <div class="card-body">
                        <h3 class="card-title"><?php echo h($plugin['name']); ?></h3>
                        <p class="text-secondary"><?php echo h($plugin['description']); ?></p>
                        <a href="/plugins/<?php echo h($plugin['id']); ?>/<?php echo h($plugin['entry_point']); ?>"
                           class="btn btn-primary">Ouvrir</a>
                        <?php if ($isAdmin): ?>
                        <div class="mt-2 small">
                            <?php if (!empty($plugin['repo_url'])): ?><a href="<?php echo h($plugin['repo_url']); ?>" target="_blank">Dépôt</a> · <?php endif; ?>
                            <?php if (!empty($plugin['editor_url'])): ?><a href="<?php echo h($plugin['editor_url']); ?>" target="_blank">Éditeur</a> · <?php endif; ?>
                            <?php if (!empty($plugin['doc_url'])): ?><a href="<?php echo h($plugin['doc_url']); ?>" target="_blank">Documentation</a><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/shared/footer.php'; ?>
