<?php

require __DIR__ . '/includes/exports.php';

$key = isset($_GET['export']) ? (string) $_GET['export'] : '';
$export = export_by_key($key);
if ($export === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Unknown export.\n";
    exit;
}

require __DIR__ . '/gdbase.inc.php';
require __DIR__ . '/includes/csv.php';

if (isset($_GET['preview'])) {
    render_preview($conn, $export);
    exit;
}

stream_csv($conn, $export);
