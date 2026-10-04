<?php

function layout_start(string $title, string $heading, string $lead): void
{
    require_once __DIR__ . '/settings.php';
    $company = htmlspecialchars(setting('company_label', 'BLUE'), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $title ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <style>
            body { background: #f3f5f7; color: #1b2430; }
            .site-header { background: #14324f; color: #fff; }
            .site-header a { color: #d7e6f5; text-decoration: none; }
            .site-header a:hover { color: #fff; }
            .lead-copy { max-width: 46rem; color: #4b5968; }
            .export-table td, .export-table th { vertical-align: middle; }
            .count { font-variant-numeric: tabular-nums; font-weight: 650; }
            .preview-table { font-size: .875rem; }
            .preview-table td { max-width: 16rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        </style>
    </head>
    <body>
    <header class="site-header py-3 mb-4">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="index.php"><strong>Odoo Export</strong></a>
            <div class="d-flex align-items-center gap-3">
                <span class="small">GP / SalesPad company <?= $company ?></span>
                <a class="small" href="settings.php">Settings</a>
            </div>
        </div>
    </header>
    <main class="container pb-5">
        <h1 class="h3 mb-2"><?= $heading ?></h1>
        <p class="lead-copy"><?= $lead ?></p>
    <?php
}

function layout_end(): void
{
    echo '</main></body></html>';
}
