<?php

require __DIR__ . '/includes/exports.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/gdbase.inc.php';

$groups = [];
foreach (export_catalog() as $export) {
    $count = null;
    $stmt = sqlsrv_query($conn, $export['count_sql']);
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row !== null) {
            $count = (int) $row['c'];
        }
    }
    $export['count'] = $count;
    $groups[$export['group']][] = $export;
}

layout_start(
    'Odoo Export',
    'GP / SalesPad exports for Odoo',
    'These files are formatted for Odoo Enterprise. Import the first group before customers, products, and documents. Columns such as <code>partner_id/id</code>, <code>country_id/code</code>, and <code>order_line/product_uom_qty</code> match the Odoo import wizard. Set every <code>gp_*</code> column to Do not import. Documents load as drafts. Voided sales documents are left out.'
);

foreach ($groups as $group => $exports) {
    echo '<h2 class="h5 mt-4 mb-2">' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '</h2>';
    echo '<div class="table-responsive bg-white border rounded mb-3"><table class="table table-hover export-table mb-0">';
    echo '<thead><tr><th>Export</th><th>Odoo</th><th class="text-end">Rows</th><th></th></tr></thead><tbody>';
    foreach ($exports as $export) {
        $count = $export['count'] === null ? '—' : number_format($export['count']);
        $key = urlencode($export['key']);
        $large = $export['count'] !== null && $export['count'] >= 100000;
        echo '<tr>';
        echo '<td><div class="fw-semibold">' . htmlspecialchars($export['title'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="small text-muted">' . htmlspecialchars($export['detail'], ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="small">' . htmlspecialchars($export['odoo'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end count">' . $count . '</td>';
        echo '<td class="text-end text-nowrap">';
        echo '<a class="btn btn-outline-secondary btn-sm" href="download.php?export=' . $key . '&preview=1">Preview</a> ';
        echo '<a class="btn btn-primary btn-sm" href="download.php?export=' . $key . '">CSV</a>';
        if ($large) {
            echo '<div class="small text-muted mt-1">Large file</div>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

layout_end();
