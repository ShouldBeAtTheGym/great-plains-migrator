<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SalespersonLink;
use RuntimeException;

class ExportCatalog
{
    public const PART_ROWS = 30000;

    private bool $booted = false;

    public function __construct(private OdooClient $odoo) {}

    public function all(): array
    {
        $this->boot();

        return export_catalog();
    }

    public function find(string $key): ?array
    {
        $this->boot();

        return export_by_key($key);
    }

    public function prepare(Company $company, array $export): array
    {
        if (($export['key'] ?? '') === 'customers' && class_exists(\App\Models\SalespadUserField::class)) {
            $chosen = \App\Models\SalespadUserField::query()
                ->where('company_id', $company->id)
                ->where('model_key', 'customers')
                ->where('selected', true)
                ->whereNotNull('odoo_field')
                ->get();
            foreach ($chosen as $field) {
                $export['fields'][$field->odoo_field] = $field->label;
            }

        }

        $importCompanyName = trim((string) $company->odoo_import_company_name);
        if (in_array(($export['key'] ?? ''), ['customers', 'customer_price_lists'], true)
            && (int) $company->odoo_import_company_id > 0
            && $importCompanyName !== '') {
            $export['extra']['company_id'] = $importCompanyName;
            $export['fields']['company_id'] = 'Company';
        }

        $key = (string) ($export['key'] ?? '');
        $export = $this->applySalesperson($company, $export, $key);
        if (str_ends_with($key, '_headers') && (str_contains($key, 'sales_orders') || str_contains($key, 'quotes'))) {
            $warehouse = $this->warehouseStock($company);
            $export['extra']['warehouse_id'] = $warehouse['name'];
            $export['fields']['warehouse_id'] = 'Warehouse';
        }

        if ($key !== 'stock_locations') {
            return $export;
        }

        $parent = $this->warehouseStock($company);
        $export['extra'] = [
            'company_id/id' => $this->externalId($company, 'res.company', $parent['company_id'], 'company_'.$parent['company_id']),
        ];

        return $export;
    }

    public function columns($connection, array $export): array
    {
        $statement = sqlsrv_query(
            $connection,
            'EXEC sp_describe_first_result_set @tsql = ?',
            [$export['sql']]
        );

        if ($statement === false) {
            return [];
        }

        $columns = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        foreach (array_keys($export['extra'] ?? []) as $name) {
            $columns[] = $name;
        }

        return $columns;
    }

    public function sample($connection, array $export, int $limit = 3): array
    {
        $this->boot();
        $sql = 'SELECT TOP '.$limit.' * FROM ('.$export['sql'].') AS preview_src';
        $statement = export_query($connection, $sql);
        if ($statement === false) {
            return [];
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $line = [];
            foreach ($row as $column => $value) {
                $line[$column] = (string) csv_value($value, (string) $column);
            }
            foreach ($export['extra'] ?? [] as $column => $value) {
                $line[$column] = (string) csv_value($value, (string) $column);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    public function externalIds($connection, array $export, int $limit = 5): array
    {
        $this->boot();
        $sql = 'SELECT TOP '.$limit.' id FROM ('.$export['sql'].') AS id_src';
        $statement = export_query($connection, $sql);
        if ($statement === false) {
            return [];
        }

        $ids = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $id = (string) csv_value($row['id'] ?? '', 'id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function stream($connection, array $export, ?callable $keep = null): void
    {
        $this->boot();
        $statement = export_query($connection, $export['sql']);
        if ($statement === false) {
            throw new \RuntimeException('The export query failed.');
        }

        $headers = array_values(array_filter(
            array_map(
                static fn ($field) => $field['Name'],
                sqlsrv_field_metadata($statement) ?: []
            ),
            static fn ($name) => $name !== 'export_part_row'
        ));
        foreach (array_keys($export['extra'] ?? []) as $name) {
            $headers[] = $name;
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);

        $written = 0;
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            if ($keep !== null && $keep($row) !== true) {
                continue;
            }
            $line = [];
            foreach ($headers as $name) {
                $line[] = array_key_exists($name, $export['extra'] ?? [])
                    ? csv_value($export['extra'][$name], $name)
                    : csv_value($row[$name] ?? null, $name);
            }
            fputcsv($out, $line);
            if (++$written % 500 === 0) {
                fflush($out);
                flush();
            }
        }
        fclose($out);
    }

    public function userExternalId(Company $company, int $userId): string
    {
        return $this->externalId($company, 'res.users', $userId, 'user_'.$userId);
    }

    public function partCount(?int $count): int
    {
        if ($count === null || $count <= self::PART_ROWS) {
            return 1;
        }

        return (int) ceil($count / self::PART_ROWS);
    }

    public function partSql(string $sql, int $part): string
    {
        $part = max(1, $part);
        $start = ($part - 1) * self::PART_ROWS;
        $end = $start + self::PART_ROWS;
        $order = 'src.id';
        if (preg_match('/AS \[([^\]]+sequence)\]/', $sql, $match)) {
            $order .= ', src.['.$match[1].']';
        }

        return "SELECT * FROM (
    SELECT src.*, ROW_NUMBER() OVER (ORDER BY {$order}) AS export_part_row
    FROM (
{$sql}
    ) AS src
) AS export_part
WHERE export_part.export_part_row > {$start}
  AND export_part.export_part_row <= {$end}";
    }

    public function filename(array $export, ?int $part = null): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($export['file'], PATHINFO_FILENAME));
        $suffix = $part === null ? '' : '_part'.str_pad((string) $part, 2, '0', STR_PAD_LEFT);

        return $name.$suffix.'_'.date('Ymd').'.csv';
    }

    private function applySalesperson(Company $company, array $export, string $key): array
    {
        $sql = (string) ($export['sql'] ?? '');
        if (! preg_match('/RTRIM\((c\.Sales_Person_ID|h\.SLPRSNID)\) AS gp_salesperson/', $sql)) {
            return $export;
        }

        $column = str_contains($key, 'invoices') || str_contains($key, 'returns')
            ? 'invoice_user_id/id'
            : 'user_id/id';
        $export['sql'] = preg_replace_callback(
            '/RTRIM\((c\.Sales_Person_ID|h\.SLPRSNID)\) AS gp_salesperson/',
            fn (array $match) => $this->salespersonCase($company, $match[1]).' AS ['.$column.']',
            $sql
        );
        $export['fields'][$column] = 'Salesperson / External ID';

        return $export;
    }

    private function salespersonCase(Company $company, string $expr): string
    {
        if (! $company->exists) {
            return "''";
        }

        $links = SalespersonLink::query()
            ->where('company_id', $company->id)
            ->where('external_id', '!=', '')
            ->get();
        if ($links->isEmpty()) {
            return "''";
        }

        $sql = 'CASE RTRIM('.$expr.')';
        foreach ($links as $link) {
            $gpId = str_replace("'", "''", $link->gp_id);
            $externalId = str_replace("'", "''", $link->external_id);
            $sql .= " WHEN '{$gpId}' THEN '{$externalId}'";
        }

        return $sql." ELSE '' END";
    }

    private function warehouseStock(Company $company): array
    {
        $gpName = trim((string) $company->gp_company_name);
        if ($gpName === '') {
            throw new RuntimeException('Great Plains has no company name, so the warehouse locations file cannot pick an Odoo company.');
        }

        $companies = $this->odoo->execute($company, 'res.company', 'search_read', [[]], [
            'fields' => ['name'],
            'limit' => 50,
        ]);
        $match = null;
        foreach (is_array($companies) ? $companies : [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && stripos($name, $gpName) !== false) {
                $match = $row;
                break;
            }
        }
        if (! is_array($match)) {
            throw new RuntimeException('Odoo has no company matching '.$gpName.'.');
        }

        $warehouses = $this->odoo->execute($company, 'stock.warehouse', 'search_read', [[
            ['company_id', '=', (int) $match['id']],
        ]], [
            'fields' => ['name', 'lot_stock_id'],
            'limit' => 1,
        ]);
        $warehouse = is_array($warehouses) ? ($warehouses[0] ?? null) : null;
        $locationId = (int) (is_array($warehouse['lot_stock_id'] ?? null) ? $warehouse['lot_stock_id'][0] : 0);
        if ($locationId === 0) {
            throw new RuntimeException('Odoo has no warehouse stock location for '.$match['name'].'.');
        }

        $name = trim((string) ($warehouse['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Odoo has no warehouse name for '.$match['name'].'.');
        }

        return [
            'company_id' => (int) $match['id'],
            'location_id' => $locationId,
            'name' => $name,
        ];
    }

    private function externalId(Company $company, string $model, int $resId, string $name): string
    {
        $existing = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
            ['model', '=', $model],
            ['res_id', '=', $resId],
        ]], [
            'fields' => ['module', 'name'],
            'limit' => 1,
        ]);
        if (is_array($existing) && isset($existing[0]['module'], $existing[0]['name'])) {
            return $existing[0]['module'].'.'.$existing[0]['name'];
        }

        $this->odoo->execute($company, 'ir.model.data', 'create', [[
            'module' => 'odooexport',
            'name' => $name,
            'model' => $model,
            'res_id' => $resId,
            'noupdate' => true,
        ]]);

        return 'odooexport.'.$name;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        require_once base_path('legacy/includes/exports.php');
        require_once base_path('legacy/includes/csv.php');
        $this->booted = true;
    }
}
