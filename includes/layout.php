<?php
/**
 * Shared page layout helpers.
 */

function renderPageStart(string $title, bool $withNavbar = true): void {
    $pageTitle = $title;
    require dirname(__DIR__) . '/includes/header.php';

    if ($withNavbar) {
        require dirname(__DIR__) . '/includes/navbar.php';
    }
}

function renderPageEnd(): void {
    require dirname(__DIR__) . '/includes/footer.php';
}

