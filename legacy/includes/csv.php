<?php

function csv_value($value, string $column)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s');
    }
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if ($value === null) {
        return '';
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($column === 'id' || str_ends_with($column, '/id')) {
            $value = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            $value = rtrim(rtrim($value, '0'), '.');
            if ($value === '' || $value === '-' || $value === '-0') {
                $value = '0';
            }
        }
    }
    return $value;
}

function export_query($conn, string $sql)
{
    return sqlsrv_query($conn, $sql, [], [
        'QueryTimeout' => 0,
        'Scrollable' => SQLSRV_CURSOR_FORWARD,
    ]);
}

function stream_csv($conn, array $export): void
{
    @set_time_limit(0);
    $stmt = export_query($conn, $export['sql']);
    if ($stmt === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Export failed.\n";
        print_r(sqlsrv_errors());
        return;
    }

    $headers = array_map(static fn ($field) => $field['Name'], sqlsrv_field_metadata($stmt));
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($export['file'], PATHINFO_FILENAME));
    $filename .= '_' . date('Ymd') . '.csv';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);

    $written = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $line = [];
        foreach ($headers as $name) {
            $line[] = csv_value($row[$name] ?? null, $name);
        }
        fputcsv($out, $line);
        if (++$written % 500 === 0) {
            fflush($out);
            flush();
        }
    }
    fclose($out);
}

function render_preview($conn, array $export): void
{
    $sql = 'SELECT TOP 25 * FROM (' . $export['sql'] . ') AS preview_src';
    $stmt = export_query($conn, $sql);
    require_once __DIR__ . '/layout.php';
    $title = $export['title'] . ' preview';
    layout_start(
        $title,
        $export['title'],
        'First 25 rows. Download the full CSV when the columns look right. ' . $export['detail']
    );
    echo '<p><a class="btn btn-primary btn-sm" href="download.php?export=' . urlencode($export['key']) . '">Download CSV</a> ';
    echo '<a class="btn btn-outline-secondary btn-sm" href="index.php">Back</a></p>';

    if ($stmt === false) {
        echo '<div class="alert alert-danger">Preview failed.</div>';
        echo '<pre class="small">' . htmlspecialchars(print_r(sqlsrv_errors(), true), ENT_QUOTES, 'UTF-8') . '</pre>';
        layout_end();
        return;
    }

    $headers = array_map(static fn ($field) => $field['Name'], sqlsrv_field_metadata($stmt));
    echo '<div class="table-responsive bg-white border rounded"><table class="table table-sm preview-table mb-0">';
    echo '<thead><tr>';
    foreach ($headers as $name) {
        echo '<th>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    $rows = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows++;
        echo '<tr>';
        foreach ($headers as $name) {
            $value = (string) csv_value($row[$name] ?? null, $name);
            echo '<td title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    if ($rows === 0) {
        echo '<tr><td colspan="' . count($headers) . '" class="text-muted">No rows.</td></tr>';
    }
    echo '</tbody></table></div>';
    layout_end();
}
