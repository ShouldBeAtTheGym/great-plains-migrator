<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SyncExport;
use App\Models\SyncRecord;
use RuntimeException;

class GpSync
{
    public const NOTICE = 'Since every system, ongoing remove and update cannot be done via sync.';

    public const BATCH = 2500;

    public function __construct(
        private ExportCatalog $catalog,
        private GpConnection $gp,
        private OdooClient $odoo,
    ) {}

    public function stepOneComplete(Company $company): bool
    {
        $total = $company->steps()->count();

        return $total > 0 && $company->steps()->where('status', '!=', 'complete')->doesntExist();
    }

    public function listing(Company $company): array
    {
        $saved = SyncExport::query()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('export_key');

        $groups = [];
        foreach ($this->catalog->all() as $export) {
            $row = $saved->get($export['key']);
            $profile = $this->profile($export['key']);
            $groups[$export['group']][] = [
                'export' => $export,
                'sync' => $row,
                'profile' => $profile,
                'parts' => $row ? $this->catalog->partCount((int) $row->new_count) : 1,
            ];
        }

        return $groups;
    }

    public function baseline(Company $company, string $key): string
    {
        $this->guard($company);
        $export = $this->export($key);
        $profile = $this->profile($key);
        $row = $this->row($company, $key);
        $connection = $this->gp->connect($company);

        try {
            $counts = $this->readCounts($connection, $export, $profile, (int) $row->watermark);
            $this->applyCounts($row, $counts);

            if ($profile['dex'] !== null) {
                $row->record_count = $counts['gp_count'];
                $row->new_count = 0;
                $row->watermark = $counts['seen_max'];
                $row->seen_max = $counts['seen_max'];
                $row->capture_done = true;
                $row->baselined_at = now();
                $row->last_export_at = now();
                $row->verify_ok = false;
                $row->verify_message = null;
                $row->user_field_note = 'User fields are copied when new rows are found. Fields that are not on the Odoo file are still saved.';
                $row->save();

                return 'Recorded '.$counts['gp_count'].' current rows. New rows added after this are the ones to download.';
            }

            $inserted = $this->captureKeys($connection, $company, $export, $profile, $row);
            $row->gp_count = $counts['gp_count'];
            $exported = $this->exportedCount($company, $key);
            $row->record_count = $exported;
            $row->new_count = max(0, $counts['gp_count'] - $exported);
            if ($row->capture_offset >= $counts['gp_count'] || $inserted === 0) {
                $row->capture_done = true;
                $row->baselined_at = now();
                $row->last_export_at = now();
                $row->new_count = max(0, $counts['gp_count'] - $this->exportedCount($company, $key));
                $note = $this->storeUserFields($connection, $company, $row, $profile, true);
                $row->user_field_note = $note;
            }
            $row->save();
        } finally {
            $this->close($connection);
        }

        if (! $row->capture_done) {
            return 'Recorded '.$row->capture_offset.' of '.number_format((int) $row->gp_count).' rows. Continue to finish this file.';
        }

        return 'Recorded '.$row->record_count.' current rows. New rows added after this are the ones to download.';
    }

    public function refresh(Company $company, string $key): string
    {
        $this->guard($company);
        $export = $this->export($key);
        $profile = $this->profile($key);
        $row = $this->row($company, $key);
        if (! $row->capture_done) {
            throw new RuntimeException('Record the current rows before checking for new ones.');
        }

        $connection = $this->gp->connect($company);
        try {
            $counts = $this->readCounts($connection, $export, $profile, (int) $row->watermark);
            $this->applyCounts($row, $counts);
            if ($profile['dex'] !== null) {
                $row->new_count = $counts['new_count'];
            } else {
                $row->new_count = max(0, $counts['gp_count'] - $this->exportedCount($company, $key));
            }
            $row->verify_ok = false;
            $note = $row->new_count > 0
                ? $this->storeUserFields($connection, $company, $row, $profile, false)
                : $row->user_field_note;
            $row->user_field_note = $note;
            $row->save();
        } finally {
            $this->close($connection);
        }

        return 'Great Plains has '.number_format((int) $row->gp_count).' rows. '.$row->new_count.' are new since the last export.';
    }

    public function verify(Company $company, string $key): string
    {
        $this->guard($company);
        $profile = $this->profile($key);
        $row = $this->row($company, $key);
        if (! $row->capture_done) {
            throw new RuntimeException('Record the current rows before verifying.');
        }

        $this->refresh($company, $key);
        $row->refresh();

        $odooCount = $this->odooCount($company, $profile);
        $expected = $odooCount === null ? null : $this->expectedOdooCount($company, $profile);
        $lines = $profile['lines'];
        $result = $this->assessment(
            (int) $row->gp_count,
            (int) $row->record_count,
            (int) $row->new_count,
            $odooCount,
            $expected,
            $lines,
            $row->downloaded_at !== null,
        );

        $row->odoo_count = $odooCount;
        $row->verified_at = now();
        $row->verify_ok = $result['ok'];
        $row->verify_message = $result['message'];

        if ($result['accept']) {
            $row->record_count = (int) $row->record_count + (int) $row->new_count;
            $row->new_count = 0;
            $row->watermark = (int) $row->seen_max;
            $row->last_export_at = now();
            $row->downloaded_at = null;
            SyncRecord::query()
                ->where('company_id', $company->id)
                ->where('export_key', $key)
                ->whereNull('exported_at')
                ->update(['exported_at' => now()]);
        }

        $row->save();

        return $row->verify_message;
    }

    public function prepareDownload(Company $company, string $key, ?int $part): array
    {
        $this->guard($company);
        $export = $this->catalog->prepare($company, $this->export($key));
        $profile = $this->profile($key);
        $row = $this->row($company, $key);
        if (! $row->capture_done) {
            throw new RuntimeException('Record the current rows before downloading new ones.');
        }
        if ((int) $row->new_count < 1) {
            throw new RuntimeException('There are no new rows to download. Refresh the count first.');
        }

        $parts = $this->catalog->partCount((int) $row->new_count);
        if ($parts > 1 && $part === null) {
            throw new RuntimeException('This new file is too large for one Odoo upload. Download one part at a time.');
        }
        if ($part !== null && ($part < 1 || $part > $parts)) {
            abort(404);
        }

        if ($profile['dex'] !== null) {
            $export['sql'] = $this->newRowsSql($export['sql'], $profile['dex'], (int) $row->watermark);
        }
        if ($part !== null) {
            $export['sql'] = $this->catalog->partSql($export['sql'], $part);
        }

        $row->downloaded_at = now();
        $row->last_export_at = now();
        $row->save();

        $name = preg_replace('/\.csv$/', '', $export['file']);
        $suffix = $parts > 1 ? '_part'.str_pad((string) $part, 2, '0', STR_PAD_LEFT) : '';
        $export['download_name'] = $name.'_new'.$suffix.'_'.date('Ymd').'.csv';
        $export['known_keys'] = $profile['dex'] === null ? $this->knownKeys($company, $key) : null;

        return $export;
    }

    public function countQuery(string $countSql, ?string $alias, int $watermark): string
    {
        $column = $alias === null || $alias === '' ? 'DEX_ROW_ID' : $alias.'.DEX_ROW_ID';
        $watermark = max(0, $watermark);
        $select = 'SELECT COUNT(*) AS c, ISNULL(MAX('.$column.'), 0) AS mx, ISNULL(SUM(CASE WHEN '.$column.' > '.$watermark.' THEN 1 ELSE 0 END), 0) AS new_count';

        $updated = preg_replace('/SELECT\s+COUNT\(\*\)\s+AS\s+c/i', $select, $countSql, 1);

        return is_string($updated) ? $updated : $countSql;
    }

    public function newRowsSql(string $sql, ?string $alias, int $watermark): string
    {
        $column = $alias === null || $alias === '' ? 'DEX_ROW_ID' : $alias.'.DEX_ROW_ID';
        $predicate = $column.' > '.max(0, $watermark);
        if (preg_match('/\bWHERE\b/i', $sql)) {
            return $sql."\n AND ".$predicate;
        }

        return $sql."\n WHERE ".$predicate;
    }

    public function assessment(
        int $gpCount,
        int $recordCount,
        int $newCount,
        ?int $odooCount,
        ?int $expectedOdoo,
        bool $lines,
        bool $downloaded,
    ): array {
        $synced = $recordCount + $newCount;
        $parts = [
            'Great Plains has '.number_format($gpCount).' rows.',
            number_format($recordCount).' are already exported.',
            number_format($newCount).' are new.',
        ];
        $ok = true;
        $accept = false;

        if ($gpCount !== $synced) {
            $parts[] = self::NOTICE;
        }

        if ($odooCount !== null && $expectedOdoo === null) {
            $ok = false;
            $parts[] = 'Odoo has '.number_format($odooCount).' records. Record every file in this set before the count can be checked.';
        } elseif ($odooCount !== null) {
            $parts[] = 'Odoo has '.number_format($odooCount).' records.';
            if ($expectedOdoo !== null && $odooCount !== $expectedOdoo) {
                $ok = false;
                $parts[] = 'Import the new file, then verify again.';
            }
        } elseif ($lines && $newCount > 0 && ! $downloaded) {
            $ok = false;
            $parts[] = 'Download the new rows, import them in Odoo, then verify.';
        } elseif ($lines && $newCount > 0) {
            $parts[] = 'Line files are checked by this row count. Import the download in Odoo before relying on it.';
        }

        if ($ok && $newCount > 0) {
            $accept = true;
            $parts[] = 'The new rows are now part of the sync.';
        }

        if ($ok && $newCount === 0) {
            $parts[] = 'Counts match.';
        }

        return [
            'ok' => $ok,
            'accept' => $accept,
            'message' => implode(' ', $parts),
        ];
    }

    public function profile(string $key): array
    {
        $sales = $this->salesProfile($key);
        if ($sales !== null) {
            return $sales;
        }

        $dex = match (true) {
            str_starts_with($key, 'purchase_orders_') => '',
            str_starts_with($key, 'vendor_bills_') => '',
            $key === 'inventory_on_hand' => 'q',
            default => null,
        };

        $user = match ($key) {
            'customers' => ['view' => 'spvCustomer', 'table' => 'spxCustomer', 'key_column' => 'Customer_Num', 'key_prefix' => 'partner_'],
            'customer_addresses' => ['view' => 'spvCustomerAddr', 'table' => 'spxCustomerAddr', 'key_column' => 'Customer_Num', 'key_prefix' => 'partner_addr_'],
            'vendors' => ['view' => 'spvVendor', 'table' => 'spxVendor', 'key_column' => 'Vendor_ID', 'key_prefix' => 'vendor_'],
            'vendor_addresses' => ['view' => 'spvVendorAddr', 'table' => 'spxVendorAddr', 'key_column' => 'Vendor_ID', 'key_prefix' => 'vendor_addr_'],
            'products' => ['view' => 'spvItemMaster', 'table' => 'spxItemMaster', 'key_column' => 'Item_Number', 'key_prefix' => 'product_'],
            default => null,
        };

        $odoo = match ($key) {
            'payment_terms' => ['model' => 'account.payment.term', 'prefix' => 'payment_term_', 'group' => 'payment_term', 'exclude' => []],
            'product_categories' => ['model' => 'product.category', 'prefix' => 'product_categ_', 'group' => 'product_categ', 'exclude' => []],
            'customers' => ['model' => 'res.partner', 'prefix' => 'partner_', 'group' => 'partner', 'exclude' => ['partner_addr_']],
            'customer_addresses' => ['model' => 'res.partner', 'prefix' => 'partner_addr_', 'group' => 'partner_addr', 'exclude' => []],
            'vendors' => ['model' => 'res.partner', 'prefix' => 'vendor_', 'group' => 'vendor', 'exclude' => ['vendor_addr_']],
            'vendor_addresses' => ['model' => 'res.partner', 'prefix' => 'vendor_addr_', 'group' => 'vendor_addr', 'exclude' => []],
            'products' => ['model' => 'product.template', 'prefix' => 'product_', 'group' => 'product', 'exclude' => ['product_categ_', 'product_variant_']],
            'stock_locations' => ['model' => 'stock.location', 'prefix' => 'stock_location_', 'group' => 'stock_location', 'exclude' => []],
            default => null,
        };

        return [
            'dex' => $dex,
            'lines' => str_ends_with($key, '_lines') || $key === 'customer_price_lists' || $key === 'inventory_on_hand',
            'identity' => $key === 'customer_price_lists'
                ? ['id', 'item_ids/product_id/id', 'item_ids/min_quantity']
                : ['id'],
            'user' => $user,
            'odoo' => $odoo,
        ];
    }

    public function recordKey(array $row, array $identity): string
    {
        $parts = [];
        foreach ($identity as $column) {
            $parts[] = trim((string) ($row[$column] ?? ''));
        }
        $key = implode('|', $parts);

        return substr($key, 0, 191);
    }

    private function salesProfile(string $key): ?array
    {
        if (! preg_match('/^sales_(orders|invoices|returns|quotes)_(open|history)_(headers|lines)$/', $key, $match)) {
            return null;
        }

        $kind = $match[1];
        $source = $match[2];
        $side = $match[3];
        $headers = $side === 'headers';
        $docType = ['orders' => 'ORDER', 'invoices' => 'INVOICE', 'returns' => 'RETURN', 'quotes' => 'QUOTE'][$kind];
        $prefix = ['orders' => 'sale_', 'invoices' => 'invoice_', 'returns' => 'refund_', 'quotes' => 'quote_'][$kind];
        $model = in_array($kind, ['orders', 'quotes'], true) ? 'sale.order' : 'account.move';

        return [
            'dex' => $headers ? 'h' : 'l',
            'lines' => ! $headers,
            'identity' => ['id'],
                'user' => [
                'view' => $source === 'history'
                    ? ($headers ? 'spv3SalesDocumentHistory' : 'spv3SalesLineItemHistory')
                    : ($headers ? 'spv3SalesDocument' : 'spv3SalesLineItem'),
                'table' => $headers ? 'spxSalesDocument' : 'spxSalesLineItem',
                'key_column' => 'Sales_Doc_Num',
                'key_prefix' => $prefix,
                'doc_type' => $docType,
                'source_table' => $source === 'history'
                    ? ($headers ? 'dbo.SOP30200' : 'dbo.SOP30300')
                    : ($headers ? 'dbo.SOP10100' : 'dbo.SOP10200'),
                'sop_type' => ['orders' => 2, 'invoices' => 3, 'returns' => 4, 'quotes' => 1][$kind],
            ],
            'odoo' => $headers ? [
                'model' => $model,
                'prefix' => $prefix,
                'group' => $prefix,
                'exclude' => [],
            ] : null,
        ];
    }

    private function guard(Company $company): void
    {
        if (! $this->stepOneComplete($company)) {
            throw new RuntimeException('Finish Step One before the ongoing sync.');
        }
    }

    private function export(string $key): array
    {
        $export = $this->catalog->find($key);
        if (! $export) {
            abort(404);
        }

        return $export;
    }

    private function row(Company $company, string $key): SyncExport
    {
        return SyncExport::query()->firstOrCreate(
            ['company_id' => $company->id, 'export_key' => $key],
        );
    }

    private function readCounts($connection, array $export, array $profile, int $watermark): array
    {
        $sql = $profile['dex'] !== null
            ? $this->countQuery($export['count_sql'], $profile['dex'], $watermark)
            : $export['count_sql'];
        $rows = $this->gp->rows($connection, $sql, 1);
        $first = $rows[0] ?? [];

        return [
            'gp_count' => (int) ($first['c'] ?? 0),
            'seen_max' => (int) ($first['mx'] ?? 0),
            'new_count' => (int) ($first['new_count'] ?? 0),
        ];
    }

    private function applyCounts(SyncExport $row, array $counts): void
    {
        $row->gp_count = $counts['gp_count'];
        $row->seen_max = max((int) $row->seen_max, $counts['seen_max']);
    }

    private function captureKeys($connection, Company $company, array $export, array $profile, SyncExport $row): int
    {
        $columns = [];
        foreach ($profile['identity'] as $column) {
            $columns[] = '['.str_replace(']', ']]', $column).']';
        }
        $offset = (int) $row->capture_offset;
        $sql = 'SELECT '.implode(', ', $columns).' FROM ('.$export['sql'].') AS sync_src ORDER BY '.$columns[0].' OFFSET '.$offset.' ROWS FETCH NEXT '.self::BATCH.' ROWS ONLY';
        $rows = $this->gp->rows($connection, $sql);
        $now = now();
        $batch = [];
        foreach ($rows as $record) {
            $key = $this->recordKey($record, $profile['identity']);
            if ($key === '' || $key === '|') {
                continue;
            }
            $batch[] = [
                'company_id' => $company->id,
                'export_key' => $row->export_key,
                'record_key' => $key,
                'user_fields' => null,
                'exported_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($batch !== []) {
            SyncRecord::query()->insertOrIgnore($batch);
        }
        $row->capture_offset = $offset + count($rows);

        return count($batch);
    }

    private function exportedCount(Company $company, string $key): int
    {
        return SyncRecord::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->whereNotNull('exported_at')
            ->count();
    }

    private function knownKeys(Company $company, string $key): array
    {
        return SyncRecord::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->whereNotNull('exported_at')
            ->pluck('record_key')
            ->all();
    }

    private function odooCount(Company $company, array $profile): ?int
    {
        $odoo = $profile['odoo'];
        if (! is_array($odoo)) {
            return null;
        }

        $domain = [
            ['module', '=', '__import__'],
            ['model', '=', $odoo['model']],
            ['name', '=like', $odoo['prefix'].'%'],
        ];
        foreach ($odoo['exclude'] as $prefix) {
            $domain[] = ['name', 'not like', $prefix.'%'];
        }

        try {
            $count = $this->odoo->execute($company, 'ir.model.data', 'search_count', [$domain]);
        } catch (RuntimeException) {
            return null;
        }

        return is_numeric($count) ? (int) $count : null;
    }

    private function expectedOdooCount(Company $company, array $profile): ?int
    {
        $odoo = $profile['odoo'];
        if (! is_array($odoo)) {
            return null;
        }

        $expected = 0;
        foreach ($this->catalog->all() as $export) {
            $other = $this->profile($export['key']);
            if (($other['odoo']['group'] ?? null) !== $odoo['group']) {
                continue;
            }
            $saved = SyncExport::query()
                ->where('company_id', $company->id)
                ->where('export_key', $export['key'])
                ->first();
            if (! $saved || ! $saved->capture_done) {
                return null;
            }
            $expected += (int) $saved->record_count + (int) $saved->new_count;
        }

        return $expected;
    }

    private function storeUserFields($connection, Company $company, SyncExport $row, array $profile, bool $baseline): ?string
    {
        $user = $profile['user'];
        if (! is_array($user)) {
            return $row->user_field_note;
        }

        $view = str_replace("'", '', (string) $user['view']);
        $table = str_replace("'", '', (string) $user['table']);
        try {
            $views = $this->gp->rows($connection, "SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_NAME = '".$view."'");
            if ($views === []) {
                return 'SalesPad view '.$view.' is not on this company, so user fields were not copied.';
            }
            $columns = $this->userColumns($connection, $view, $table);
            $spxOnly = $this->spxOnlyColumns($connection, $table, $columns);
            $fieldColumns = array_merge($columns, $spxOnly);
            if ($fieldColumns === []) {
                return 'No user-field columns were found on '.$view.'.';
            }

            $keyColumn = str_replace(']', ']]', (string) $user['key_column']);
            $syncExpr = 'RTRIM(v.['.$keyColumn.'])';
            if ($profile['lines'] && ! empty($user['doc_type'])) {
                $syncExpr = "CONCAT(RTRIM(v.[Sales_Doc_Num]), '|', CONVERT(varchar(20), v.Line_Num), '|', CONVERT(varchar(20), v.Component_Seq_Num))";
            }
            $select = $columns === []
                ? $syncExpr.' AS sync_key'
                : $syncExpr.' AS sync_key, '.implode(', ', array_map(fn (string $column) => 'v.['.str_replace(']', ']]', $column).']', $columns));
            if ($spxOnly !== []) {
                $select .= ', '.implode(', ', array_map(fn (string $column) => 'x.['.str_replace(']', ']]', $column).']', $spxOnly));
            }
            $sql = 'SELECT '.$select.' FROM dbo.['.$view.'] AS v';
            if ($spxOnly !== []) {
                $sql .= ' LEFT JOIN dbo.['.$table.'] AS x ON RTRIM(x.['.$keyColumn.']) = RTRIM(v.['.$keyColumn.'])';
                if (! empty($user['doc_type'])) {
                    $sql .= ' AND RTRIM(x.Sales_Doc_Type) = RTRIM(v.Sales_Doc_Type)';
                }
            }
            if (! $baseline && $profile['dex'] !== null && ! empty($user['source_table'])) {
                if ((int) $row->new_count > 5000) {
                    return 'More than 5,000 new rows. User fields stay on the SalesPad view and are copied on the next smaller check. The download still uses the export columns.';
                }
                $docType = str_replace("'", '', (string) ($user['doc_type'] ?? ''));
                $sql .= ' INNER JOIN '.$user['source_table'].' AS src ON RTRIM(src.SOPNUMBE) = RTRIM(v.['.$keyColumn.']) AND src.SOPTYPE = '.(int) $user['sop_type'];
                $sql .= ' WHERE src.DEX_ROW_ID > '.(int) $row->watermark;
                if ($docType !== '') {
                    $sql .= " AND RTRIM(v.Sales_Doc_Type) = '".$docType."'";
                }
            }
            $records = $this->gp->rows($connection, $sql);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        $saved = 0;
        foreach ($records as $record) {
            $business = trim((string) ($record['sync_key'] ?? ''));
            if ($business === '') {
                continue;
            }
            $fields = [];
            foreach ($fieldColumns as $column) {
                $value = $record[$column] ?? null;
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $fields[$column] = is_scalar($value) || $value === null ? $value : (string) $value;
            }
            $recordKey = substr($user['key_prefix'].$business, 0, 191);
            $existing = SyncRecord::query()->firstOrNew([
                'company_id' => $company->id,
                'export_key' => $row->export_key,
                'record_key' => $recordKey,
            ]);
            $existing->user_fields = $fields;
            if (! $existing->exists && $baseline) {
                $existing->exported_at = now();
            }
            $existing->save();
            $saved++;
            if (! $baseline && $saved >= 5000) {
                break;
            }
        }

        return 'Saved user fields from '.$view.' on '.$saved.' rows, including fields that are not on the Odoo file.';
    }

    private function userColumns($connection, string $view, string $table): array
    {
        $found = [];
        $viewColumns = $this->gp->rows($connection, "SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$view."' AND (COLUMN_NAME LIKE 'x%' OR COLUMN_NAME LIKE 'User[_]Def%')");
        foreach ($viewColumns as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name !== '') {
                $found[$name] = $name;
            }
        }

        try {
            $defined = $this->gp->rows($connection, "SELECT Field_Name AS name FROM dbo.spvUserField WHERE Table_Name = '".$table."'");
        } catch (RuntimeException) {
            $defined = [];
        }
        $viewNames = array_map(
            fn (array $column) => strtolower(trim((string) ($column['name'] ?? ''))),
            $this->gp->rows($connection, "SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$view."'")
        );
        foreach ($defined as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name !== '' && in_array(strtolower($name), $viewNames, true)) {
                $found[$name] = $name;
            }
        }

        return array_values($found);
    }

    private function spxOnlyColumns($connection, string $table, array $already): array
    {
        try {
            $rows = $this->gp->rows($connection, "SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$table."' AND (COLUMN_NAME LIKE 'x%' OR COLUMN_NAME LIKE 'User[_]Def%')");
        } catch (RuntimeException) {
            return [];
        }

        $skip = array_map('strtolower', $already);
        $found = [];
        foreach ($rows as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name !== '' && ! in_array(strtolower($name), $skip, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    private function close($connection): void
    {
        if ($connection) {
            sqlsrv_close($connection);
        }
    }
}
