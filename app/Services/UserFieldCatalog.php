<?php

namespace App\Services;

use App\Models\Company;

class UserFieldCatalog
{
    public function __construct(private GpConnection $gp) {}

    public function scan($connection): array
    {
        $fields = $this->gp->rows($connection, <<<'SQL'
SELECT Table_Name, Field_Name, Label
FROM dbo.spvUserField
WHERE Table_Name LIKE 'spx%'
ORDER BY Table_Name, Display_Seq, Field_Name
SQL);
        $columns = $this->gp->rows($connection, <<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME LIKE 'spx%' OR TABLE_NAME LIKE 'spv%'
SQL);

        return $this->collect($fields, $columns);
    }

    public function remember(Company $company, $connection): array
    {
        $report = $company->salespad_report ?? [];
        $saved = $report['user_field_catalog'] ?? null;
        if (($report['user_fields_scanned'] ?? false) && is_array($saved)) {
            return $saved;
        }

        $catalog = $this->scan($connection);
        $report['user_field_catalog'] = $catalog;
        $report['user_fields'] = array_map(fn (array $field) => [
            'table' => $field['table'],
            'field' => $field['field'],
            'label' => $field['label'],
        ], $catalog['fields']);
        $report['user_fields_scanned'] = true;
        $company->salespad_report = $report;
        $company->save();

        return $catalog;
    }

    public function collect(array $userFields, array $columns): array
    {
        $byTable = [];
        foreach ($columns as $column) {
            $table = trim((string) ($column['TABLE_NAME'] ?? ''));
            $name = trim((string) ($column['COLUMN_NAME'] ?? ''));
            if ($table === '' || $name === '') {
                continue;
            }
            $byTable[strtolower($table)][strtolower($name)] = $name;
        }

        $fields = [];
        foreach ($userFields as $row) {
            $table = trim((string) ($row['Table_Name'] ?? ''));
            $field = trim((string) ($row['Field_Name'] ?? ''));
            $label = trim((string) ($row['Label'] ?? ''));
            if ($table === '' || $field === '' || ! str_starts_with(strtolower($table), 'spx')) {
                continue;
            }
            $tableColumns = $byTable[strtolower($table)] ?? [];
            if (! isset($tableColumns[strtolower($field)])) {
                continue;
            }
            $view = 'spv'.substr($table, 3);
            $viewColumns = $byTable[strtolower($view)] ?? [];
            $onView = isset($viewColumns[strtolower($field)]);
            $fields[] = [
                'table' => $table,
                'view' => $view,
                'field' => $onView ? $viewColumns[strtolower($field)] : $tableColumns[strtolower($field)],
                'label' => $label,
                'on_view' => $onView,
            ];
        }

        $standards = [];
        $itemColumns = $byTable['spvitemmaster'] ?? [];
        if (isset($itemColumns['item_generic_description'])) {
            $standards['spvItemMaster']['item_generic_description'] = $itemColumns['item_generic_description'];
        }
        $customerColumns = $byTable['spvcustomer'] ?? [];
        foreach (['Primary_Email', 'Email_To', 'Email'] as $emailColumn) {
            if (isset($customerColumns[strtolower($emailColumn)])) {
                $standards['spvCustomer']['customer_email'] = $customerColumns[strtolower($emailColumn)];
                break;
            }
        }

        return [
            'fields' => $fields,
            'standards' => $standards,
        ];
    }

    public function find(array $catalog, string $table, array $needles, array $reject = []): ?string
    {
        $needles = array_map($this->normalize(...), $needles);
        foreach ($catalog['fields'] ?? [] as $field) {
            if (strcasecmp((string) ($field['table'] ?? ''), $table) !== 0 || ! ($field['on_view'] ?? false)) {
                continue;
            }
            foreach ([(string) ($field['field'] ?? ''), (string) ($field['label'] ?? '')] as $value) {
                $haystack = $this->normalize($value);
                if ($haystack === '') {
                    continue;
                }
                foreach ($reject as $blocked) {
                    if (str_contains($haystack, $blocked)) {
                        continue 2;
                    }
                }
                if (in_array($haystack, $needles, true)) {
                    return (string) $field['field'];
                }
            }
        }

        return null;
    }

    public function standard(array $catalog, string $view, string $column): ?string
    {
        $saved = $catalog['standards'][$view][strtolower($column)] ?? null;

        return is_string($saved) && $saved !== '' ? $saved : null;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^x(?=[a-z])/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', $value) ?? $value;
    }
}
