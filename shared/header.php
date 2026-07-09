<?php
/**
 * shared/header.php — en-tête commun : logo, profil, notifications, paramétrage.
 * Inclus par index.php et par chaque plugin en tout début de page.
 */
$__user = currentUser();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($pageTitle) ? h($pageTitle) . ' — ' : ''; ?>Portail applicatif</title>
    <link rel="stylesheet" href="/shared/vendor/tabler/css/tabler.min.css">
    <link rel="stylesheet" href="/shared/css/portail.css">
</head>
<body>
<div class="page">
    <header class="navbar navbar-expand-md navbar-light d-print-none">
        <div class="container-xl">
            <a href="/index.php" class="navbar-brand">
                <img src="/shared/img/logo.svg" alt="Logo" height="32">
                Portail applicatif
            </a>
            <?php if ($__user): ?>
            <div class="navbar-nav flex-row order-md-last">
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
                        <span class="avatar avatar-sm"><?php echo h(strtoupper(substr($__user['display_name'], 0, 1))); ?></span>
                        <div class="d-none d-xl-block ps-2">
                            <div><?php echo h($__user['display_name']); ?></div>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                        <?php if (can($__user['id'], 'core.admin')): ?>
                        <a href="/admin/index.php" class="dropdown-item">Paramétrage</a>
                        <?php endif; ?>
                        <a href="/logout.php" class="dropdown-item">Déconnexion</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>
    <div class="page-wrapper">
