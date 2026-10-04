<?php

namespace App\Services;

class SalesPadInspector
{
    public function __construct(private GpConnection $gp) {}

    public function inspect($connection): array
    {
        $views = $this->names($connection, "SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_NAME LIKE 'spv%' ORDER BY TABLE_NAME");
        $tables = $this->names($connection, "SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE 'spx%' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

        $required = [
            'spvCustomer',
            'spvCustomerAddr',
            'spvVendor',
            'spvVendorAddr',
            'spvItemMaster',
            'spvCompanyInfo',
            'spvUserField',
        ];
        $missing = array_values(array_diff($required, $views));

        $userFields = [];
        if (in_array('spvUserField', $views, true)) {
            $userFields = $this->gp->rows($connection, <<<'SQL'
SELECT Table_Name, Field_Name, Label
FROM dbo.spvUserField
WHERE Table_Name IN (
    'spxItemMaster', 'spxCustomer', 'spxCustomerAddr', 'spxVendor', 'spxVendorAddr',
    'spxSalesDocument', 'spxSalesLineItem', 'spxPurchaseOrder', 'spxPurchaseLineItem'
)
ORDER BY Table_Name, Display_Seq, Field_Name
SQL);
            $userFields = array_map(function (array $row) {
                return [
                    'table' => trim((string) $row['Table_Name']),
                    'field' => trim((string) $row['Field_Name']),
                    'label' => trim((string) $row['Label']),
                ];
            }, $userFields);
        }

        $company = $this->company($connection, $views);

        return [
            'installed' => $missing === [] && count($views) > 0,
            'spv_count' => count($views),
            'spx_count' => count($tables),
            'missing_views' => $missing,
            'sample_views' => array_slice($views, 0, 12),
            'sample_tables' => array_slice($tables, 0, 12),
            'user_fields' => $userFields,
            'company' => $company,
        ];
    }

    private function company($connection, array $views): array
    {
        $profile = [
            'name' => '',
            'site_name' => '',
            'address' => '',
            'phone' => '',
            'version' => '',
            'currency' => '',
        ];

        if (in_array('spvCompanyInfo', $views, true)) {
            $rows = $this->gp->rows($connection, 'SELECT TOP 1 * FROM dbo.spvCompanyInfo', 1);
            if ($rows !== []) {
                $row = $rows[0];
                $profile['name'] = $this->text($row['Company_Name'] ?? '');
                $profile['site_name'] = $this->text($row['Site_Name'] ?? '');
                $profile['version'] = $this->text($row['GreatPlains_Version'] ?? '');
                $profile['currency'] = $this->text($row['Currency_ID'] ?? '');
                $profile['phone'] = $this->phone($row['Phone'] ?? '');
                $profile['address'] = $this->address($row);
            }
        }

        if ($profile['name'] === '') {
            $rows = $this->gp->rows($connection, "SELECT TOP 1 CMPNYNAM, ADDRESS1, CITY, STATE, ZIPCODE FROM DYNAMICS.dbo.SY01500 WHERE INTERID = DB_NAME()", 1);
            if ($rows !== []) {
                $row = $rows[0];
                $profile['name'] = $this->text($row['CMPNYNAM'] ?? '');
                $profile['address'] = collect([
                    $this->text($row['ADDRESS1'] ?? ''),
                    $this->text($row['CITY'] ?? ''),
                    trim($this->text($row['STATE'] ?? '').' '.$this->text($row['ZIPCODE'] ?? '')),
                ])->filter()->implode(', ');
            }
        }

        return $profile;
    }

    private function names($connection, string $sql): array
    {
        return array_map(
            fn (array $row) => trim((string) $row['name']),
            $this->gp->rows($connection, $sql)
        );
    }

    private function address(array $row): string
    {
        return collect([
            $this->text($row['Address1'] ?? ''),
            $this->text($row['Address2'] ?? ''),
            $this->text($row['City'] ?? ''),
            trim($this->text($row['State'] ?? '').' '.$this->text($row['Zip'] ?? '')),
        ])->filter()->implode(', ');
    }

    private function phone(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', $this->text($value)) ?? '';
        if ($digits === '' || preg_match('/^0+$/', $digits)) {
            return '';
        }
        if (preg_match('/^(\d{10})0+$/', $digits, $match)) {
            $digits = $match[1];
        }
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        return $this->text($value);
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
