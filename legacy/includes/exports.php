<?php

function export_catalog(): array
{
    static $catalog;
    static $loadedAt;
    $changedAt = @filemtime(__FILE__) ?: 0;
    if ($catalog !== null && $loadedAt === $changedAt) {
        return $catalog;
    }
    $loadedAt = $changedAt;
    $catalog = null;
    $products = product_exports();
    $catalog = array_merge(
        setup_exports(),
        partner_exports(),
        [$products[0]],
        customer_price_exports(),
        array_slice($products, 1),
        sales_exports(),
        purchase_exports()
    );
    return $catalog;
}

function country_code_sql(string $expr): string
{
    return "CASE UPPER(RTRIM($expr))
        WHEN '' THEN 'US'
        WHEN 'UNITED STATES' THEN 'US'
        WHEN 'US' THEN 'US'
        WHEN 'USA' THEN 'US'
        WHEN 'INUNITED STATES' THEN 'US'
        WHEN 'CANADA' THEN 'CA'
        WHEN 'ONTARIO' THEN 'CA'
        WHEN 'PUERTO RICO' THEN 'PR'
        WHEN 'PR' THEN 'PR'
        WHEN 'MEXICO' THEN 'MX'
        WHEN 'BAHAMAS' THEN 'BS'
        WHEN 'U.S. VIRGIN ISLANDS' THEN 'VI'
        WHEN 'UNITED STATES VIRGIN ISLANDS' THEN 'VI'
        WHEN 'COSTA RICA' THEN 'CR'
        WHEN 'ARUBA' THEN 'AW'
        WHEN 'PANAMA' THEN 'PA'
        WHEN 'CAYMAN ISLANDS' THEN 'KY'
        WHEN 'GUATEMALA' THEN 'GT'
        WHEN 'U.S. MINOR OUTLYING ISLANDS' THEN 'UM'
        WHEN 'TRINIDAD AND TOBAGO' THEN 'TT'
        WHEN 'SINT MAARTEN' THEN 'SX'
        WHEN 'BELIZE' THEN 'BZ'
        WHEN 'BERMUDA' THEN 'BM'
        WHEN 'CHINA' THEN 'CN'
        WHEN 'UNIGED KINGDOM' THEN 'GB'
        WHEN 'NETHERLANDS' THEN 'NL'
        WHEN 'CURACAO' THEN 'CW'
        WHEN 'JAMAICA' THEN 'JM'
        WHEN 'ANTIGUA AND BARBUDA' THEN 'AG'
        WHEN 'HONDURAS' THEN 'HN'
        WHEN 'FINLAND' THEN 'FI'
        WHEN 'TURKS AND CAICOS ISLANDS' THEN 'TC'
        WHEN 'SAINT BARTHELEMY' THEN 'BL'
        WHEN 'COLOMBIA' THEN 'CO'
        WHEN 'BONAIRE' THEN 'BQ'
        WHEN 'ARGENTINA' THEN 'AR'
        WHEN 'IRELAND' THEN 'IE'
        WHEN 'SOUTH AFRICA' THEN 'ZA'
        WHEN 'AUSTRALIA' THEN 'AU'
        WHEN 'FRANCE' THEN 'FR'
        WHEN 'DOMINICA' THEN 'DM'
        WHEN 'PHILIPPINES' THEN 'PH'
        WHEN 'VANUATU' THEN 'VU'
        WHEN 'PERU' THEN 'PE'
        WHEN 'EL SALVADOR' THEN 'SV'
        WHEN 'BRITISH VIRGIN ISLANDS' THEN 'VG'
        WHEN 'SURINAME' THEN 'SR'
        WHEN 'JAPAN' THEN 'JP'
        WHEN 'KOREA' THEN 'KR'
        WHEN 'GRENADA' THEN 'GD'
        WHEN 'FRENCH SOUTHERN TERRITORIES' THEN 'TF'
        WHEN 'VENEZUELA' THEN 'VE'
        WHEN 'ISRAEL' THEN 'IL'
        WHEN 'PALAU' THEN 'PW'
        WHEN 'BARBADOS' THEN 'BB'
        WHEN 'BAHRAIN' THEN 'BH'
        WHEN 'UNITED ARAB EMIRATES' THEN 'AE'
        WHEN 'SAINT MARTIN (FRENCH PART)' THEN 'MF'
        WHEN 'MICRONESIA' THEN 'FM'
        WHEN 'INDONESIA' THEN 'ID'
        WHEN 'OMAN' THEN 'OM'
        WHEN 'DOMINICAN PEPUBLIC' THEN 'DO'
        WHEN 'DENMARK' THEN 'DK'
        WHEN 'GERMANY' THEN 'DE'
        WHEN 'HAITI' THEN 'HT'
        WHEN 'AFGHANISTAN' THEN 'AF'
        WHEN 'SCHWEIZ' THEN 'CH'
        WHEN 'KUWAIT' THEN 'KW'
        WHEN 'RUSSIA' THEN 'RU'
        WHEN 'COOK ISLANDS' THEN 'CK'
        WHEN 'CHILE' THEN 'CL'
        WHEN 'AUSTRIA' THEN 'AT'
        WHEN 'MACAO (SAR)' THEN 'MO'
        WHEN 'SPAIN' THEN 'ES'
        WHEN 'FIJI' THEN 'FJ'
        ELSE ''
    END";
}

function payment_term_id_sql(string $expr): string
{
    $term = 'UPPER(RTRIM('.$expr.'))';
    foreach ([' ', '%', '/', '(', ')', '.', ',', '#', ':'] as $char) {
        $term = "REPLACE({$term}, '{$char}', '_')";
    }

    return "CASE WHEN NULLIF(RTRIM($expr), '') IS NULL THEN '' ELSE CONCAT('payment_term_', {$term}) END";
}

function state_code_sql(string $expr): string
{
    $value = "UPPER(LTRIM(RTRIM({$expr})))";
    $names = [
        'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA',
        'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE', 'DISTRICT OF COLUMBIA' => 'DC',
        'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI', 'IDAHO' => 'ID', 'ILLINOIS' => 'IL',
        'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA',
        'MAINE' => 'ME', 'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV',
        'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK', 'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD',
        'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA',
        'WASHINGTON' => 'WA', 'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        'ALBERTA' => 'AB', 'BRITISH COLUMBIA' => 'BC', 'MANITOBA' => 'MB', 'NEW BRUNSWICK' => 'NB',
        'NEWFOUNDLAND AND LABRADOR' => 'NL', 'NOVA SCOTIA' => 'NS', 'NORTHWEST TERRITORIES' => 'NT',
        'NUNAVUT' => 'NU', 'ONTARIO' => 'ON', 'PRINCE EDWARD ISLAND' => 'PE', 'QUEBEC' => 'QC',
        'SASKATCHEWAN' => 'SK', 'YUKON' => 'YT',
    ];
    $sql = "CASE {$value}";
    foreach ($names as $name => $code) {
        $sql .= " WHEN '{$name}' THEN '{$code}'";
    }

    return $sql." ELSE {$value} END";
}

function currency_id_sql(string $expr): string
{
    return "CASE RTRIM($expr)
        WHEN 'Z-UK' THEN 'base.GBP'
        ELSE 'base.USD'
    END";
}

function crm_html_sql(string $expr): string
{
    $text = 'CAST('.$expr.' AS varchar(max))';

    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$text}, '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), CHAR(13), ''), CHAR(10), '<br>')";
}

function gp_text_sql(string $expr): string
{
    $clean = "REPLACE(REPLACE(REPLACE(REPLACE($expr, CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' '), CHAR(160), ' ')";
    $clean = "REPLACE(REPLACE(REPLACE($clean, '  ', ' '), '  ', ' '), '  ', ' ')";

    return "LTRIM(RTRIM($clean))";
}

function external_id_sql(string $prefix, string $expr): string
{
    $value = 'RTRIM('.$expr.')';
    foreach ([' ', '#', ':', '/', '.'] as $char) {
        $value = "REPLACE({$value}, '{$char}', '_')";
    }

    return "CONCAT('{$prefix}', {$value})";
}

function uom_id_sql(string $expr): string
{
    return "CASE
        WHEN NULLIF(RTRIM($expr), '') IS NULL THEN 'uom.product_uom_unit'
        WHEN RTRIM($expr) = 'EA' THEN 'uom.product_uom_unit'
        ELSE CONCAT('uom_', RTRIM($expr))
    END";
}

function export_by_key(string $key): ?array
{
    foreach (export_catalog() as $export) {
        if ($export['key'] === $key) {
            return $export;
        }
    }
    return null;
}

function setup_exports(): array
{
    $uom = uom_id_sql('u.uom');
    $category = gp_text_sql('[[category]]');
    $subcategory = gp_text_sql('[[subcategory]]');
    return [
        [
            'key' => 'payment_terms',
            'group' => 'Import first',
            'title' => 'Payment terms',
            'detail' => 'Odoo payment terms. Import this before customers, vendors, orders, and invoices. Terms used only on an order or a bill are included.',
            'odoo' => 'Payment terms',
            'file' => 'odoo18_payment_terms.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(*) AS c FROM (
    SELECT RTRIM(Payment_Terms) AS term FROM dbo.spvCustomer
    UNION
    SELECT RTRIM(Payment_Terms) FROM dbo.spvVendor
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.SOP10100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.SOP30200
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.POP10100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.POP30100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.PM20000
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.PM30200
) AS terms
WHERE term <> ''
SQL,
            'sql' => <<<'SQL'
SELECT
    CONCAT('payment_term_', UPPER(term)) AS id,
    UPPER(term) AS name
FROM (
    SELECT RTRIM(Payment_Terms) AS term FROM dbo.spvCustomer
    UNION
    SELECT RTRIM(Payment_Terms) FROM dbo.spvVendor
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.SOP10100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.SOP30200
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.POP10100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.POP30100
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.PM20000
    UNION
    SELECT RTRIM(PYMTRMID) FROM dbo.PM30200
) AS terms
WHERE term <> ''
SQL,
        ],
        [
            'key' => 'product_categories',
            'group' => 'Import first',
            'title' => 'Product categories',
            'detail' => 'A category with no subcategory is only its name, such as X - WASTE CANS. A real subcategory is the category, a slash, and the subcategory, such as WP - WAIST PACKS / BELT BAG. Blank and repeated subcategories are left off. Names are trimmed. Delete the categories already imported, then import this file again.',
            'odoo' => 'Product categories',
            'file' => 'odoo18_product_categories.csv',
            'count_sql' => <<<SQL
SELECT
    (SELECT COUNT(DISTINCT {$category})
     FROM dbo.spvItemMaster
     WHERE NULLIF({$category}, '') IS NOT NULL)
    +
    (SELECT COUNT(*) FROM (
        SELECT DISTINCT {$category} AS category, {$subcategory} AS subcategory
        FROM dbo.spvItemMaster
        WHERE NULLIF({$category}, '') IS NOT NULL
          AND NULLIF({$subcategory}, '') IS NOT NULL
          AND {$subcategory} <> {$category}
    ) AS subs) AS c
SQL,
            'sql' => <<<SQL
SELECT
    id,
    name,
    [parent_id/id]
FROM (
    SELECT DISTINCT
        CONCAT('product_categ_', {$category}) AS id,
        {$category} AS name,
        CAST(NULL AS varchar(200)) AS [parent_id/id],
        0 AS sort_key
    FROM dbo.spvItemMaster
    WHERE NULLIF({$category}, '') IS NOT NULL
    UNION ALL
    SELECT DISTINCT
        CONCAT('product_categ_', {$category}, '__', {$subcategory}),
        {$subcategory},
        CONCAT('product_categ_', {$category}),
        1
    FROM dbo.spvItemMaster
    WHERE NULLIF({$category}, '') IS NOT NULL
      AND NULLIF({$subcategory}, '') IS NOT NULL
      AND {$subcategory} <> {$category}
) AS categories
ORDER BY sort_key, name
OFFSET 0 ROWS
SQL,
        ],
        [
            'key' => 'units_of_measure',
            'group' => 'Import first',
            'title' => 'Units of measure',
            'hint' => 'Step 1: Enable Units of Measure in Settings. Go to Inventory → Configuration → Settings (or Sales → Configuration → Settings). Scroll down to the Products section. Check the box for Units of Measure (or Units of Measure & Packagings). Click Save at the top.',
            'detail' => 'Each (EA) uses the Odoo unit Units. This file is only the other GP units, such as case packs.',
            'odoo' => 'Units of measure',
            'file' => 'odoo18_units_of_measure.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(*) AS c FROM (
    SELECT DISTINCT RTRIM(Selling_U_of_M) AS uom FROM dbo.spvItemMaster
    UNION
    SELECT DISTINCT RTRIM(UOFM) FROM dbo.SOP10200
    UNION
    SELECT DISTINCT RTRIM(UOFM) FROM dbo.SOP30300
) AS units
WHERE uom <> '' AND uom <> 'EA'
SQL,
            'sql' => <<<SQL
SELECT
    {$uom} AS id,
    u.uom AS name,
    'uom.product_uom_categ_unit' AS [category_id/id],
    'bigger' AS uom_type,
    CASE
        WHEN u.uom LIKE 'CASE %' THEN TRY_CAST(REPLACE(u.uom, 'CASE ', '') AS numeric(18, 6))
        ELSE 1
    END AS factor_inv,
    0.01 AS rounding
FROM (
    SELECT DISTINCT RTRIM(Selling_U_of_M) AS uom FROM dbo.spvItemMaster
    UNION
    SELECT DISTINCT RTRIM(UOFM) FROM dbo.SOP10200
    UNION
    SELECT DISTINCT RTRIM(UOFM) FROM dbo.SOP30300
) AS u
WHERE u.uom <> '' AND u.uom <> 'EA'
SQL,
        ],
        [
            'key' => 'stock_locations',
            'group' => 'Import first',
            'title' => 'Warehouse locations',
            'detail' => 'One internal location per GP site, named with the GP site code. Import this before inventory on hand.',
            'hint' => 'Locations stays hidden until Storage Locations is on. Go to Inventory → Configuration → Settings. Under Warehouse, check Storage Locations. Click Save. Then open Inventory → Configuration → Locations. It is listed with Warehouses and Operations Types.',
            'odoo' => 'Locations',
            'file' => 'odoo18_stock_locations.csv',
            'count_sql' => "SELECT COUNT(*) AS c FROM (SELECT DISTINCT LOCNCODE FROM dbo.IV00102 WHERE RTRIM(LOCNCODE) <> '') AS sites",
            'sql' => <<<'SQL'
SELECT DISTINCT
    CONCAT('stock_location_', RTRIM(LOCNCODE)) AS id,
    RTRIM(LOCNCODE) AS name,
    'internal' AS usage,
    RTRIM(LOCNCODE) AS barcode,
    1 AS replenish_location,
    90 AS cyclic_inventory_frequency,
    'stock.removal_least_packages' AS [removal_strategy_id/id]
FROM dbo.IV00102
WHERE RTRIM(LOCNCODE) <> ''
SQL,
        ],
    ];
}

function salespad_customer_columns(): string
{
    if (! class_exists(\App\Models\Company::class) || ! class_exists(\App\Models\SalespadUserField::class)) {
        return '';
    }

    try {
        if (! \Illuminate\Support\Facades\Schema::hasTable('salespad_user_fields')) {
            return '';
        }
        $company = \App\Models\Company::query()->orderBy('id')->first();
        if (! $company) {
            return '';
        }

        return app(\App\Services\SalespadUserFields::class)->sqlColumns($company, 'customers');
    } catch (\Throwable) {
        return '';
    }
}

function partner_exports(): array
{
    $customerCountry = country_code_sql('a.Country');
    $stateCode = state_code_sql('a.State');
    $customerTerm = payment_term_id_sql('c.Payment_Terms');
    $customerCurrency = currency_id_sql('c.Currency_ID');
    $addressCountry = country_code_sql('a.Country');
    $vendorCountry = country_code_sql('a.Country');
    $vendorTerm = payment_term_id_sql('v.Payment_Terms');
    $vendorCurrency = currency_id_sql('v.Currency_ID');
    $salespadColumns = salespad_customer_columns();
    $noteText = crm_html_sql('n.Description');
    $noteType = crm_html_sql('n.Note_Type');
    $noteBy = crm_html_sql('n.Created_By');
    $eventText = crm_html_sql('e.Description');
    $eventContact = crm_html_sql('e.Contact_Name');
    $eventAssigned = crm_html_sql('e.Assigned_To');
    $eventMinutes = "CASE
        WHEN RTRIM(ISNULL(e.Duration, '')) LIKE '[0-9][0-9]:[0-9][0-9]:[0-9][0-9]'
            THEN CAST(LEFT(RTRIM(e.Duration), 2) AS int) * 60 + CAST(SUBSTRING(RTRIM(e.Duration), 4, 2) AS int)
        ELSE 0
    END";
    $eventStart = 'COALESCE(e.Starts_On, e.Created_On)';
    $eventStop = "CASE
        WHEN e.Ends_On IS NOT NULL AND e.Starts_On IS NOT NULL AND e.Ends_On > e.Starts_On THEN e.Ends_On
        ELSE DATEADD(minute, CASE WHEN ({$eventMinutes}) < 1 THEN 30 ELSE ({$eventMinutes}) END, {$eventStart})
    END";
    return [
        [
            'key' => 'customers',
            'group' => 'Customers and vendors',
            'title' => 'Customers',
            'detail' => 'Odoo contacts. Country is the ISO code. A blank GP country is exported as US. Payment terms point at the payment term file. SalesPad user fields from spxCustomer fill the User Fields tab. Columns that start with gp_ are reference only.',
            'before' => [
                'Accounting → Configuration → Currencies. Activate USD. Activate GBP too if any customer uses Z-UK.',
                'Contacts → Configuration → Countries. Open United States and confirm the states are listed, including SC.',
            ],
            'fields' => [
                'id' => 'External ID',
                'mobile' => 'Mobile',
                'state_id/code' => 'State / Code',
                'country_id/code' => 'Country / Code',
                'currency_id/id' => 'Currency / External ID',
                'property_payment_term_id/id' => 'Customer Payment Terms / External ID',
                'use_partner_credit_limit' => 'Partner Limit',
                'credit_limit' => 'Credit Limit',
                'vat' => 'Tax ID',
                'comment' => 'Notes',
                'x_gp_customer_number' => 'Customer Number',
                'x_gp_customer_class' => 'Customer Class',
                'x_gp_shipping_method' => 'Shipping Method',
            ],
            'odoo' => 'Contacts, customer',
            'file' => 'odoo18_customers.csv',
            'count_sql' => 'SELECT COUNT(*) AS c FROM dbo.spvCustomer',
            'sql' => <<<SQL
SELECT
    CONCAT('partner_', RTRIM(c.Customer_Num)) AS id,
    RTRIM(c.Customer_Num) AS x_gp_customer_number,
    COALESCE(NULLIF(LTRIM(RTRIM(c.Customer_Name)), ''), RTRIM(c.Customer_Num)) AS name,
    1 AS customer_rank,
    0 AS supplier_rank,
    CASE WHEN c.Inactive = 1 THEN 0 ELSE 1 END AS active,
    RTRIM(CAST(c.[[customer_email]] AS varchar(255))) AS email,
    RTRIM(a.Phone_1) AS phone,
    RTRIM(a.Phone_2) AS mobile,
    RTRIM(a.Address_Line_1) AS street,
    RTRIM(a.Address_Line_2) AS street2,
    RTRIM(a.City) AS city,
    {$stateCode} AS [state_id/code],
    RTRIM(a.Zip) AS zip,
    {$customerCountry} AS [country_id/code],
    RTRIM(a.Contact_Person) AS gp_contact_name,
    {$customerTerm} AS [property_payment_term_id/id],
    {$customerCurrency} AS [currency_id/id],
    CASE WHEN c.Customer_Credit_Limit > 0 THEN 1 ELSE 0 END AS use_partner_credit_limit,
    c.Customer_Credit_Limit AS credit_limit,
    RTRIM(c.Tax_Registration_Num) AS vat,
    RTRIM(c.Comment_1) AS comment,
    RTRIM(c.Sales_Person_ID) AS gp_salesperson,
    RTRIM(c.Customer_Class) AS x_gp_customer_class,
    RTRIM(c.Price_Level) AS gp_price_level,
    RTRIM(c.Tax_Schedule) AS gp_tax_schedule,
    c.Balance AS gp_ar_balance,
    RTRIM(c.Shipping_Method) AS x_gp_shipping_method,
    CASE WHEN c.On_Hold = 1 THEN 1 ELSE 0 END AS gp_on_hold{$salespadColumns}
FROM dbo.spvCustomer AS c
LEFT JOIN dbo.spvCustomerAddr AS a
    ON a.Customer_Num = c.Customer_Num
   AND a.Address_Code = c.Primary_Addr_Code
LEFT JOIN dbo.spxCustomer AS u
    ON u.Customer_Num = c.Customer_Num
SQL,
        ],
        [
            'key' => 'customer_addresses',
            'group' => 'Customers and vendors',
            'title' => 'Customer addresses',
            'detail' => 'Odoo child contacts. Import customers first so parent_id/id matches.',
            'before' => [
                'Import customers first. The parent is the complete id Odoo saved, such as __import__.partner_1001. Addresses with no customer number are left out.',
                'Contacts → Configuration → Countries. United States and its states, including SC, must already be there.',
            ],
            'fields' => [
                'mobile' => 'Mobile',
                'state_id/code' => 'State / Code',
                'country_id/code' => 'Country / Code',
                'parent_id/id' => 'Related Company / External ID',
                'x_gp_address_code' => 'Address Code',
                'x_gp_is_primary' => 'Primary',
                'x_gp_is_bill_to' => 'Bill To',
                'x_gp_is_ship_to' => 'Ship To',
            ],
            'odoo' => 'Contacts, invoice and delivery',
            'file' => 'odoo18_customer_addresses.csv',
            'count_sql' => "SELECT COUNT(*) AS c FROM dbo.spvCustomerAddr WHERE RTRIM(ISNULL(Customer_Num, '')) <> ''",
            'sql' => <<<SQL
SELECT
    CONCAT('partner_addr_', RTRIM(a.Customer_Num), '_', RTRIM(a.Address_Code)) AS id,
    CONCAT('__import__.partner_', RTRIM(a.Customer_Num)) AS [parent_id/id],
    CASE
        WHEN RTRIM(a.Address_Code) = RTRIM(c.Primary_Bill_To_Addr_Code)
         AND RTRIM(a.Address_Code) = RTRIM(c.Primary_Ship_To_Addr_Code) THEN 'other'
        WHEN RTRIM(a.Address_Code) = RTRIM(c.Primary_Bill_To_Addr_Code) THEN 'invoice'
        ELSE 'delivery'
    END AS type,
    COALESCE(NULLIF(RTRIM(a.Alt_Company_Name), ''), NULLIF(RTRIM(a.Contact_Person), ''), RTRIM(a.Address_Code)) AS name,
    RTRIM(a.Email) AS email,
    RTRIM(a.Phone_1) AS phone,
    RTRIM(a.Phone_2) AS mobile,
    RTRIM(a.Address_Line_1) AS street,
    RTRIM(a.Address_Line_2) AS street2,
    RTRIM(a.City) AS city,
    {$stateCode} AS [state_id/code],
    RTRIM(a.Zip) AS zip,
    {$addressCountry} AS [country_id/code],
    RTRIM(a.Address_Code) AS x_gp_address_code,
    RTRIM(a.Address_Line_3) AS gp_street3,
    CASE WHEN RTRIM(a.Address_Code) = RTRIM(c.Primary_Addr_Code) THEN 1 ELSE 0 END AS x_gp_is_primary,
    CASE WHEN RTRIM(a.Address_Code) = RTRIM(c.Primary_Bill_To_Addr_Code) THEN 1 ELSE 0 END AS x_gp_is_bill_to,
    CASE WHEN RTRIM(a.Address_Code) = RTRIM(c.Primary_Ship_To_Addr_Code) THEN 1 ELSE 0 END AS x_gp_is_ship_to
FROM dbo.spvCustomerAddr AS a
LEFT JOIN dbo.spvCustomer AS c
    ON c.Customer_Num = a.Customer_Num
WHERE RTRIM(ISNULL(a.Customer_Num, '')) <> ''
SQL,
        ],
        [
            'key' => 'customer_crm_notes',
            'group' => 'Customers and vendors',
            'title' => 'Customer CRM notes',
            'detail' => 'SalesPad notes from spCRMNote. Each row is a log note on the Odoo customer. The customer number is looked up in Odoo when the file is copied, and that contact id is written in Related Document ID. A note whose customer is not in Odoo is left out and listed in the report on this page.',
            'before' => [
                'Import customers first. A note whose customer is not in Odoo yet is left out of the file and listed in the report on this page.',
                'Turn on developer mode. Settings → General Settings → Developer Tools → Activate the developer mode.',
            ],
            'hint' => 'Messages is under Settings → Technical → Messages. It stays hidden until developer mode is on.',
            'fields' => [
                'id' => 'External ID',
                'model' => 'Related Document Model',
                'res_id' => 'Related Document ID',
                'subject' => 'Subject',
                'body' => 'Contents',
                'date' => 'Date',
                'message_type' => 'Type',
                'subtype_id/id' => 'Subtype / External ID',
            ],
            'resolve_res_id' => 'res.partner',
            'odoo' => 'Messages, log note on the customer',
            'file' => 'odoo18_customer_crm_notes.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(*) AS c
FROM dbo.spCRMNote AS n
INNER JOIN dbo.spvCustomer AS c
    ON RTRIM(c.Customer_Num) = RTRIM(n.Customer_Num)
WHERE RTRIM(ISNULL(n.Customer_Num, '')) <> ''
SQL,
            'sql' => <<<SQL
SELECT
    CONCAT('crm_note_', n.Note_ID) AS id,
    'res.partner' AS model,
    CONCAT('partner_', RTRIM(n.Customer_Num)) AS res_id,
    RTRIM(n.Note_Type) AS subject,
    CONCAT('<p>', {$noteType}, ' · ', {$noteBy}, '</p><p>', {$noteText}, '</p>') AS body,
    n.Created_On AS date,
    'comment' AS message_type,
    'mail.mt_note' AS [subtype_id/id]
FROM dbo.spCRMNote AS n
INNER JOIN dbo.spvCustomer AS c
    ON RTRIM(c.Customer_Num) = RTRIM(n.Customer_Num)
WHERE RTRIM(ISNULL(n.Customer_Num, '')) <> ''
SQL,
        ],
        [
            'key' => 'customer_crm_events',
            'group' => 'Customers and vendors',
            'title' => 'Customer CRM events',
            'detail' => 'SalesPad events from spCRMEvent. Each row is a meeting on Calendar → Calendar, organized by the Odoo user who matches the SalesPad person in Assigned To. Do not import this file on Configuration → Calendars. That screen has no Start or Stop. A customer is added as an attendee when the customer number is filled in, or when the subject matches exactly one customer name.',
            'before' => [
                'Import customers first so an event with a customer can point at that contact.',
                'Open Calendar → Calendar. Switch to the list of meetings. The gear menu on that list has Import records. Configuration → Calendars is a different screen, and Start and Stop will not map there.',
                'On user_id/.id, choose Organizer / Database ID. That is the person the meeting is assigned to. On partner_ids/.id, choose Attendees / Database ID. On start, choose Start. On stop, choose Stop. The name column is Meeting Subject. If Odoo calls it Name, you are on the wrong screen.',
                'A SalesPad login that has no matching Odoo user is left without an organizer. Download the file again after that user exists in Odoo.',
            ],
            'hint' => 'Calendar → Calendar, then the list icon. Import records is on that list. Configuration → Calendars has no Start or Stop.',
            'fields' => [
                'id' => 'External ID',
                'name' => 'Meeting Subject',
                'start' => 'Start',
                'stop' => 'Stop',
                'allday' => 'All Day',
                'description' => 'Description',
                'user_id/.id' => 'Organizer / Database ID',
                'partner_ids/.id' => 'Attendees / Database ID',
            ],
            'odoo' => 'Calendar → Calendar, meetings list',
            'file' => 'odoo18_customer_crm_events.csv',
            'count_sql' => 'SELECT COUNT(*) AS c FROM dbo.spCRMEvent AS e WHERE COALESCE(e.Starts_On, e.Created_On) IS NOT NULL',
            'sql' => <<<SQL
SELECT
    CONCAT('crm_event_', e.Event_ID) AS id,
    COALESCE(NULLIF(RTRIM(e.Subject), ''), NULLIF(RTRIM(e.Contact_Name), ''), CONCAT('Event ', e.Event_ID)) AS name,
    {$eventStart} AS start,
    {$eventStop} AS stop,
    0 AS allday,
    [[event_user_id]] AS [user_id/.id],
    [[event_attendees]] AS [partner_ids/.id],
    CONCAT(
        '<p>',
        CASE WHEN RTRIM(ISNULL(e.Contact_Name, '')) = '' THEN '' ELSE CONCAT('Contact: ', {$eventContact}, '<br>') END,
        CASE WHEN RTRIM(ISNULL(e.Assigned_To, '')) = '' THEN '' ELSE CONCAT('Assigned to: ', {$eventAssigned}, '<br>') END,
        CASE WHEN e.Is_Complete = 1 THEN 'Complete' ELSE 'Open' END,
        '</p><p>',
        {$eventText},
        '</p>'
    ) AS description
FROM dbo.spCRMEvent AS e
OUTER APPLY (
    SELECT TOP 1 RTRIM(c.Customer_Num) AS Customer_Num
    FROM dbo.spvCustomer AS c
    WHERE RTRIM(ISNULL(e.Customer_Num, '')) = ''
      AND RTRIM(ISNULL(e.Subject, '')) <> ''
      AND RTRIM(c.Customer_Name) = RTRIM(e.Subject)
      AND (
          SELECT COUNT(*)
          FROM dbo.spvCustomer AS c2
          WHERE RTRIM(c2.Customer_Name) = RTRIM(e.Subject)
      ) = 1
) AS named
WHERE COALESCE(e.Starts_On, e.Created_On) IS NOT NULL
SQL,
        ],
        [
            'key' => 'vendors',
            'group' => 'Customers and vendors',
            'title' => 'Vendors',
            'detail' => 'Odoo vendor contacts. Vendor payment terms use property_supplier_payment_term_id. Columns that start with gp_ are reference only.',
            'before' => [
                'Accounting → Configuration → Currencies. Activate USD. Activate GBP too if any vendor uses Z-UK.',
                'Contacts → Configuration → Countries. Open United States and confirm the states are listed, including SC.',
            ],
            'fields' => [
                'mobile' => 'Mobile',
                'state_id/code' => 'State / Code',
                'country_id/code' => 'Country / Code',
                'currency_id/id' => 'Currency / External ID',
                'property_supplier_payment_term_id/id' => 'Vendor Payment Terms / External ID',
                'comment' => 'Notes',
                'x_gp_customer_number' => 'Customer Number',
            ],
            'odoo' => 'Contacts, vendor',
            'file' => 'odoo18_vendors.csv',
            'count_sql' => 'SELECT COUNT(*) AS c FROM dbo.spvVendor',
            'sql' => <<<SQL
SELECT
    CONCAT('vendor_', RTRIM(v.Vendor_ID)) AS id,
    RTRIM(v.Vendor_ID) AS x_gp_customer_number,
    RTRIM(v.Vendor_Name) AS name,
    0 AS customer_rank,
    1 AS supplier_rank,
    CASE WHEN RTRIM(v.Vendor_Status) = 'INACTIVE' THEN 0 ELSE 1 END AS active,
    RTRIM(a.Email) AS email,
    RTRIM(a.Phone_1) AS phone,
    RTRIM(a.Phone_2) AS mobile,
    RTRIM(a.Address_Line_1) AS street,
    RTRIM(a.Address_Line_2) AS street2,
    RTRIM(a.City) AS city,
    {$stateCode} AS [state_id/code],
    RTRIM(a.Zip) AS zip,
    {$vendorCountry} AS [country_id/code],
    {$vendorTerm} AS [property_supplier_payment_term_id/id],
    {$vendorCurrency} AS [currency_id/id],
    RTRIM(v.Comment1) AS comment,
    RTRIM(v.Vendor_Class_ID) AS gp_vendor_class,
    RTRIM(v.Tax_Schedule) AS gp_tax_schedule,
    RTRIM(v.Shipping_Method) AS gp_shipping_method,
    CASE WHEN v.Hold = 1 THEN 1 ELSE 0 END AS gp_on_hold,
    RTRIM(v.Vendor_Status) AS gp_status
FROM dbo.spvVendor AS v
LEFT JOIN dbo.spvVendorAddr AS a
    ON a.Vendor_ID = v.Vendor_ID
   AND a.Address_Code = v.Primary_Addr_Code
SQL,
        ],
        [
            'key' => 'vendor_addresses',
            'group' => 'Customers and vendors',
            'title' => 'Vendor addresses',
            'detail' => 'Odoo vendor child contacts. Import vendors first.',
            'before' => [
                'Import vendors first. Odoo stores each vendor as __import__.vendor_ plus the vendor id. This file uses that full id for the parent.',
                'Contacts → Configuration → Countries. United States and its states, including SC, must already be there.',
            ],
            'fields' => [
                'mobile' => 'Mobile',
                'state_id/code' => 'State / Code',
                'country_id/code' => 'Country / Code',
                'parent_id/id' => 'Related Company / External ID',
                'x_gp_address_code' => 'Address Code',
            ],
            'odoo' => 'Contacts, vendor addresses',
            'file' => 'odoo18_vendor_addresses.csv',
            'count_sql' => 'SELECT COUNT(*) AS c FROM dbo.spvVendorAddr',
            'sql' => <<<SQL
SELECT
    CONCAT('vendor_addr_', RTRIM(a.Vendor_ID), '_', RTRIM(a.Address_Code)) AS id,
    CONCAT('__import__.vendor_', RTRIM(a.Vendor_ID)) AS [parent_id/id],
    CASE
        WHEN RTRIM(a.Address_Code) = RTRIM(v.Remit_To_Addr_Code) THEN 'invoice'
        WHEN RTRIM(a.Address_Code) = RTRIM(v.Purchase_Addr_Code) THEN 'other'
        ELSE 'delivery'
    END AS type,
    COALESCE(NULLIF(RTRIM(a.Contact_Person), ''), RTRIM(a.Address_Code)) AS name,
    RTRIM(a.Email) AS email,
    RTRIM(a.Phone_1) AS phone,
    RTRIM(a.Address_Line_1) AS street,
    RTRIM(a.Address_Line_2) AS street2,
    RTRIM(a.City) AS city,
    {$stateCode} AS [state_id/code],
    RTRIM(a.Zip) AS zip,
    {$vendorCountry} AS [country_id/code],
    RTRIM(a.Address_Code) AS x_gp_address_code,
    RTRIM(a.Address_Line_3) AS gp_street3
FROM dbo.spvVendorAddr AS a
LEFT JOIN dbo.spvVendor AS v
    ON v.Vendor_ID = a.Vendor_ID
SQL,
        ],
    ];
}

function customer_price_exports(): array
{
    $currency = currency_id_sql('c.Currency_ID');

    return [
        [
            'key' => 'customer_price_lists',
            'group' => 'Products',
            'title' => 'Customer price lists',
            'detail' => 'SalesPad customer prices from spvCustomPrice. Each customer with special prices gets one Odoo pricelist, and each row is that customer\'s price for one item. Import customers and products first. The price points at the product external id from that import. Kits and archived products are left out.',
            'before' => [
                'Import customers first, then products. A price can only point at a product already in Odoo.',
                'Sales → Configuration → Settings. Under Pricing, turn Pricelists on if Sales → Products → Pricelists is not in the menu.',
            ],
            'fields' => [
                'id' => 'External ID',
                'currency_id/id' => 'Currency / External ID',
                'item_ids/applied_on' => 'Do not import',
                'item_ids/product_tmpl_id/id' => 'Product / External ID',
                'item_ids/compute_price' => 'Compute Price',
                'item_ids/fixed_price' => 'Fixed Price',
                'item_ids/min_quantity' => 'Min. Quantity',
                'item_ids/date_start' => 'Start Date',
                'item_ids/date_end' => 'End Date',
            ],
            'odoo' => 'Sales → Products → Pricelists',
            'file' => 'odoo18_customer_price_lists.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(*) AS c
FROM dbo.spvCustomPrice AS p
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(p.Item_Number)
   AND RTRIM(i.Item_Type) <> 'Kit'
   AND ISNULL(i.Inactive, 0) = 0
WHERE NULLIF(RTRIM(p.Customer_Num), '') IS NOT NULL
  AND NULLIF(RTRIM(p.Item_Number), '') IS NOT NULL
SQL,
            'sql' => <<<SQL
SELECT
    CONCAT('pricelist_', RTRIM(p.Customer_Num)) AS id,
    CONCAT(RTRIM(p.Customer_Num), ' ', COALESCE(NULLIF(RTRIM(c.Customer_Name), ''), 'prices')) AS name,
    {$currency} AS [currency_id/id],
    '1_product' AS [item_ids/applied_on],
    CONCAT('__import__.product_', RTRIM(p.Item_Number)) AS [item_ids/product_tmpl_id/id],
    'fixed' AS [item_ids/compute_price],
    p.Price AS [item_ids/fixed_price],
    CASE WHEN p.Qty_From > 0 THEN p.Qty_From ELSE 0 END AS [item_ids/min_quantity],
    CASE WHEN p.Begin_Date > '1900-01-02' THEN CONVERT(varchar(19), p.Begin_Date, 120) ELSE '' END AS [item_ids/date_start],
    CASE WHEN p.End_Date > '1900-01-02' THEN CONVERT(varchar(19), p.End_Date, 120) ELSE '' END AS [item_ids/date_end]
FROM dbo.spvCustomPrice AS p
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(p.Item_Number)
   AND RTRIM(i.Item_Type) <> 'Kit'
   AND ISNULL(i.Inactive, 0) = 0
LEFT JOIN dbo.spvCustomer AS c
    ON RTRIM(c.Customer_Num) = RTRIM(p.Customer_Num)
WHERE NULLIF(RTRIM(p.Customer_Num), '') IS NOT NULL
  AND NULLIF(RTRIM(p.Item_Number), '') IS NOT NULL
SQL,
        ],
        [
            'key' => 'customer_price_assign',
            'group' => 'Products',
            'title' => 'Assign customer price lists',
            'detail' => 'Sets the SalesPad custom price list on the customer. Import this file on Contacts. Do not import it on Pricelists. Search Pricelist and choose Pricelist / External ID. Customer numbers that are not in the customer list are left out.',
            'before' => [
                'Import the customer price lists first. The pricelist has to exist before the customer can use it. If Odoo says a pricelist external id was not found, download the price lists that are not in Odoo yet and import that file on Pricelists.',
                'Open Contacts. Do not open Sales → Products → Pricelists for this file.',
            ],
            'fields' => [
                'id' => 'External ID',
                'name' => 'Name',
                'property_product_pricelist/id' => 'Pricelist / External ID',
            ],
            'odoo' => 'Contacts',
            'file' => 'odoo18_customer_price_assign.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(DISTINCT RTRIM(p.Customer_Num)) AS c
FROM dbo.spvCustomPrice AS p
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(p.Item_Number)
   AND RTRIM(i.Item_Type) <> 'Kit'
   AND ISNULL(i.Inactive, 0) = 0
INNER JOIN dbo.spvCustomer AS c
    ON RTRIM(c.Customer_Num) = RTRIM(p.Customer_Num)
WHERE NULLIF(RTRIM(p.Customer_Num), '') IS NOT NULL
  AND NULLIF(RTRIM(p.Item_Number), '') IS NOT NULL
SQL,
            'sql' => <<<'SQL'
SELECT DISTINCT
    CONCAT('partner_', RTRIM(p.Customer_Num)) AS id,
    COALESCE(NULLIF(LTRIM(RTRIM(c.Customer_Name)), ''), RTRIM(p.Customer_Num)) AS name,
    CONCAT('__import__.pricelist_', RTRIM(p.Customer_Num)) AS [property_product_pricelist/id]
FROM dbo.spvCustomPrice AS p
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(p.Item_Number)
   AND RTRIM(i.Item_Type) <> 'Kit'
   AND ISNULL(i.Inactive, 0) = 0
INNER JOIN dbo.spvCustomer AS c
    ON RTRIM(c.Customer_Num) = RTRIM(p.Customer_Num)
WHERE NULLIF(RTRIM(p.Customer_Num), '') IS NOT NULL
  AND NULLIF(RTRIM(p.Item_Number), '') IS NOT NULL
SQL,
        ],
    ];
}

function product_exports(): array
{
    $sellUom = uom_id_sql('i.Selling_U_of_M');
    $buyUom = uom_id_sql('i.Purchasing_U_of_M');
    $category = gp_text_sql('i.[[category]]');
    $subcategory = gp_text_sql('i.[[subcategory]]');
    $categId = "CASE
        WHEN NULLIF({$category}, '') IS NULL THEN ''
        WHEN NULLIF({$subcategory}, '') IS NULL OR {$subcategory} = {$category} THEN CONCAT('product_categ_', {$category})
        ELSE CONCAT('product_categ_', {$category}, '__', {$subcategory})
    END";
    return [
        [
            'key' => 'products',
            'group' => 'Products',
            'title' => 'Products',
            'detail' => 'Odoo products. Goods are type consu. Stockable items have is_storable set. EA uses the Odoo unit Units. Import categories and units first. Kits are left out. When the same barcode is on more than one item, the first item keeps it. The next ones are numbered -1, -2, and so on.',
            'odoo' => 'Products',
            'file' => 'odoo18_products.csv',
            'count_sql' => "SELECT COUNT(*) AS c FROM dbo.spvItemMaster WHERE RTRIM(Item_Type) <> 'Kit'",
            'sql' => <<<SQL
SELECT
    CONCAT('product_', RTRIM(i.Item_Number)) AS id,
    RTRIM(i.Item_Number) AS default_code,
    RTRIM(i.Item_Description) AS name,
    CASE
        WHEN NULLIF(RTRIM(i.[[barcode]]), '') IS NULL THEN ''
        WHEN bc.barcode_seq = 1 THEN RTRIM(i.[[barcode]])
        ELSE CONCAT(RTRIM(i.[[barcode]]), '-', bc.barcode_seq - 1)
    END AS barcode,
    i.List_Price AS list_price,
    i.Standard_Cost AS standard_price,
    'consu' AS type,
    CASE WHEN i.Item_Type = 'Sales Inventory' THEN 1 ELSE 0 END AS is_storable,
    {$categId} AS [categ_id/id],
    {$sellUom} AS [uom_id/id],
    {$buyUom} AS [uom_po_id/id],
    i.Item_Shipping_Weight AS weight,
    CASE WHEN i.Inactive = 1 THEN 0 ELSE 1 END AS active,
    RTRIM(i.[[barcode]]) AS gp_barcode,
    i.Current_Cost AS gp_current_cost,
    RTRIM(i.Item_Type) AS gp_item_type,
    RTRIM(i.[[subcategory]]) AS gp_subcategory,
    RTRIM(i.[[brand]]) AS gp_brand,
    RTRIM(i.Selling_U_of_M) AS gp_uom
FROM dbo.spvItemMaster AS i
LEFT JOIN (
    SELECT
        RTRIM(Item_Number) AS item_number,
        ROW_NUMBER() OVER (PARTITION BY RTRIM([[barcode]]) ORDER BY RTRIM(Item_Number)) AS barcode_seq
    FROM dbo.spvItemMaster
    WHERE NULLIF(RTRIM([[barcode]]), '') IS NOT NULL
      AND RTRIM(Item_Type) <> 'Kit'
) AS bc
    ON bc.item_number = RTRIM(i.Item_Number)
WHERE RTRIM(i.Item_Type) <> 'Kit'
SQL,
        ],
        [
            'key' => 'inventory_on_hand',
            'group' => 'Products',
            'title' => 'Inventory on hand',
            'detail' => 'Odoo inventory counts. Import products and warehouse locations first, then apply the inventory in Odoo. The product column is the variant, product_variant_ plus the item number. Services and other items that are not Sales Inventory are left out. Odoo cannot put a quantity on a consumable or a service.',
            'fields' => [
                'product_id/id' => 'Variant / External ID',
            ],
            'odoo' => 'Inventory adjustment',
            'file' => 'odoo18_inventory_on_hand.csv',
            'count_sql' => <<<'SQL'
SELECT COUNT(*) AS c
FROM dbo.IV00102 AS q
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(q.ITEMNMBR)
   AND RTRIM(i.Item_Type) = 'Sales Inventory'
WHERE RTRIM(q.LOCNCODE) <> ''
  AND (q.QTYONHND <> 0 OR q.ATYALLOC <> 0 OR q.QTYONORD <> 0 OR q.QTYBKORD <> 0)
SQL,
            'sql' => <<<'SQL'
SELECT
    CONCAT('stock_', RTRIM(q.ITEMNMBR), '_', RTRIM(q.LOCNCODE)) AS id,
    CONCAT('__import__.product_variant_', RTRIM(q.ITEMNMBR)) AS [product_id/id],
    CONCAT('stock_location_', RTRIM(q.LOCNCODE)) AS [location_id/id],
    q.QTYONHND AS inventory_quantity,
    q.ATYALLOC AS gp_qty_allocated,
    q.QTYONORD AS gp_qty_on_order,
    q.QTYBKORD AS gp_qty_backordered
FROM dbo.IV00102 AS q
INNER JOIN dbo.spvItemMaster AS i
    ON RTRIM(i.Item_Number) = RTRIM(q.ITEMNMBR)
   AND RTRIM(i.Item_Type) = 'Sales Inventory'
WHERE RTRIM(q.LOCNCODE) <> ''
  AND (q.QTYONHND <> 0 OR q.ATYALLOC <> 0 OR q.QTYONORD <> 0 OR q.QTYBKORD <> 0)
SQL,
        ],
    ];
}

function sales_exports(): array
{
    $specs = [
        sales_spec('sales_orders_open', 'Open sales orders', 'Orders still in the GP work file.', 'Sales orders', 'open', 'ORDER', 2, 'order', 'sale', 'date_order', 'order_line', 'dbo.SOP10100', 'dbo.SOP10200'),
        sales_spec('sales_orders_history', 'Historical sales orders', 'Posted orders. Voided documents are excluded.', 'Sales orders', 'history', 'ORDER', 2, 'order', 'sale', 'date_order', 'order_line', 'dbo.SOP30200', 'dbo.SOP30300'),
        sales_spec('sales_invoices_open', 'Open sales invoices', 'Unposted invoices still in the work file. Posted invoices are in history.', 'Customer invoices', 'open', 'INVOICE', 3, 'invoice', 'invoice', 'invoice_date', 'invoice_line_ids', 'dbo.SOP10100', 'dbo.SOP10200', 'out_invoice'),
        sales_spec('sales_invoices_history', 'Historical sales invoices', 'Posted customer invoices. Voided documents are excluded.', 'Customer invoices', 'history', 'INVOICE', 3, 'invoice', 'invoice', 'invoice_date', 'invoice_line_ids', 'dbo.SOP30200', 'dbo.SOP30300', 'out_invoice'),
        sales_spec('sales_returns_open', 'Open returns', 'Returns still in the work file.', 'Customer credit notes', 'open', 'RETURN', 4, 'invoice', 'refund', 'invoice_date', 'invoice_line_ids', 'dbo.SOP10100', 'dbo.SOP10200', 'out_refund'),
        sales_spec('sales_returns_history', 'Historical returns', 'Posted returns. Voided documents are excluded.', 'Customer credit notes', 'history', 'RETURN', 4, 'invoice', 'refund', 'invoice_date', 'invoice_line_ids', 'dbo.SOP30200', 'dbo.SOP30300', 'out_refund'),
        sales_spec('sales_quotes_open', 'Open quotes', 'Quotes still in the work file.', 'Sales quotations', 'open', 'QUOTE', 1, 'order', 'quote', 'date_order', 'order_line', 'dbo.SOP10100', 'dbo.SOP10200'),
        sales_spec('sales_quotes_history', 'Historical quotes', 'Quote history. Voided documents are excluded.', 'Sales quotations', 'history', 'QUOTE', 1, 'order', 'quote', 'date_order', 'order_line', 'dbo.SOP30200', 'dbo.SOP30300'),
    ];

    $exports = [];
    foreach ($specs as $spec) {
        $exports[] = sales_header_export($spec);
        $exports[] = sales_line_export($spec);
    }
    return $exports;
}

function sales_spec(
    string $base,
    string $title,
    string $detail,
    string $odoo,
    string $source,
    string $docType,
    int $sopType,
    string $kind,
    string $idPrefix,
    string $dateField,
    string $linePrefix,
    string $sopTable,
    string $sopLineTable,
    string $moveType = ''
): array {
    return compact(
        'base',
        'title',
        'detail',
        'odoo',
        'source',
        'docType',
        'sopType',
        'kind',
        'idPrefix',
        'dateField',
        'linePrefix',
        'sopTable',
        'sopLineTable',
        'moveType'
    );
}

function sales_problem_predicate(): string
{
    return "(NULLIF(RTRIM(h.CUSTNMBR), '') IS NULL)";
}

function sales_problem_queries(array $spec): array
{
    $predicate = sales_problem_predicate();
    $where = "h.SOPTYPE = {$spec['sopType']} AND h.VOIDSTTS = 0 AND {$predicate}";

    return [
        'problem_file' => 'odoo18_'.$spec['base'].'_problems.csv',
        'problem_count_sql' => "SELECT COUNT(*) AS c FROM {$spec['sopTable']} AS h WHERE {$where}",
        'problem_sql' => "
SELECT
    RTRIM(h.SOPNUMBE) AS document,
    CONVERT(varchar(10), h.DOCDATE, 23) AS document_date,
    RTRIM(h.CUSTNMBR) AS customer_number,
    RTRIM(h.CUSTNAME) AS customer_name,
    h.DOCAMNT AS total,
    'No customer number.' AS problem
FROM {$spec['sopTable']} AS h
WHERE {$where}
",
    ];
}

function sales_header_export(array $spec): array
{
    $term = payment_term_id_sql('h.PYMTRMID');
    $currency = currency_id_sql('h.CURNCYID');
    $problem = sales_problem_queries($spec);
    $keep = 'NOT ('.sales_problem_predicate().')';
    if ($spec['kind'] === 'invoice') {
        $columns = "
    '{$spec['moveType']}' AS move_type,
    RTRIM(h.SOPNUMBE) AS payment_reference,
    RTRIM(h.CSTPONBR) AS ref,
    CONVERT(varchar(10), h.DOCDATE, 23) AS invoice_date,
    CONVERT(varchar(10), h.DUEDATE, 23) AS invoice_date_due,
    {$term} AS [invoice_payment_term_id/id],";
    } else {
        $columns = "
    RTRIM(h.CSTPONBR) AS client_order_ref,
    CONVERT(varchar(10), h.DOCDATE, 23) AS date_order,
    CONVERT(varchar(10), h.DUEDATE, 23) AS gp_due_date,
    CONVERT(varchar(10), h.ReqShipDate, 23) AS commitment_date,
    {$term} AS [payment_term_id/id],";
    }

    return [
        'key' => $spec['base'] . '_headers',
        'group' => 'Sales documents',
        'title' => $spec['title'] . ' — headers',
        'detail' => $spec['detail'] . ' Use the lines file to import into Odoo. Documents load as drafts. Orders that would stop the import are left out. Look at the problem report on this page before you download the file.',
        'odoo' => $spec['odoo'],
        'file' => 'odoo18_' . $spec['base'] . '_headers.csv',
        'count_sql' => "SELECT COUNT(*) AS c FROM {$spec['sopTable']} AS h WHERE h.SOPTYPE = {$spec['sopType']} AND h.VOIDSTTS = 0 AND {$keep}",
        'problem_file' => $problem['problem_file'],
        'problem_count_sql' => $problem['problem_count_sql'],
        'problem_sql' => $problem['problem_sql'],
        'sql' => "
SELECT
    CONCAT('{$spec['idPrefix']}_', RTRIM(h.SOPNUMBE)) AS id,
    RTRIM(h.SOPNUMBE) AS name,
    CONCAT('__import__.partner_', RTRIM(h.CUSTNMBR)) AS [partner_id/id],
    {$columns}
    {$currency} AS [currency_id/id],
    h.SUBTOTAL AS gp_subtotal,
    h.FRTAMNT AS gp_freight,
    h.MISCAMNT AS gp_misc,
    h.TAXAMNT AS gp_tax,
    h.DOCAMNT AS gp_total,
    RTRIM(h.SLPRSNID) AS gp_salesperson,
    RTRIM(h.ORIGNUMB) AS gp_previous_document
FROM {$spec['sopTable']} AS h
WHERE h.SOPTYPE = {$spec['sopType']}
  AND h.VOIDSTTS = 0
  AND {$keep}
",
    ];
}

function sales_line_export(array $spec): array
{
    $term = payment_term_id_sql('h.PYMTRMID');
    $currency = currency_id_sql('h.CURNCYID');
    $uom = uom_id_sql('l.UOFM');
    $product = external_id_sql('__import__.product_variant_', 'l.ITEMNMBR');
    $prefix = $spec['linePrefix'];
    $problem = sales_problem_queries($spec);
    $keep = 'NOT ('.sales_problem_predicate().')';
    if ($spec['kind'] === 'invoice') {
        $columns = "
    '{$spec['moveType']}' AS move_type,
    RTRIM(h.SOPNUMBE) AS payment_reference,
    RTRIM(h.CSTPONBR) AS ref,
    CONVERT(varchar(10), h.DOCDATE, 23) AS invoice_date,
    CONVERT(varchar(10), h.DUEDATE, 23) AS invoice_date_due,
    {$term} AS [invoice_payment_term_id/id],";
        $qty = 'quantity';
        $uomColumn = 'product_uom_id/id';
    } else {
        $columns = "
    RTRIM(h.CSTPONBR) AS client_order_ref,
    CONVERT(varchar(10), h.DOCDATE, 23) AS date_order,
    CONVERT(varchar(10), h.ReqShipDate, 23) AS commitment_date,
    {$term} AS [payment_term_id/id],";
        $qty = 'product_uom_qty';
        $uomColumn = 'product_uom/id';
    }

    return [
        'key' => $spec['base'] . '_lines',
        'group' => 'Sales documents',
        'title' => $spec['title'] . ' — lines',
        'detail' => 'Odoo import file. One row per line, with the same document id repeated. ' . $spec['detail'] . ' These load as drafts. Orders that would stop the import are left out. A kit, or an item that is no longer in the product file, keeps its description and price, and the product column is left blank. Look at the problem report on this page before you download the file.',
        'odoo' => $spec['odoo'] . ' with lines',
        'file' => 'odoo18_' . $spec['base'] . '_lines.csv',
        'count_sql' => "
SELECT COUNT(*) AS c
FROM {$spec['sopLineTable']} AS l
INNER JOIN {$spec['sopTable']} AS h
    ON h.SOPNUMBE = l.SOPNUMBE
   AND h.SOPTYPE = l.SOPTYPE
WHERE l.SOPTYPE = {$spec['sopType']}
  AND h.VOIDSTTS = 0
  AND {$keep}
",
        'problem_file' => $problem['problem_file'],
        'problem_count_sql' => $problem['problem_count_sql'],
        'problem_sql' => $problem['problem_sql'],
        'sql' => "
SELECT
    CONCAT('{$spec['idPrefix']}_', RTRIM(h.SOPNUMBE)) AS id,
    RTRIM(h.SOPNUMBE) AS name,
    CONCAT('__import__.partner_', RTRIM(h.CUSTNMBR)) AS [partner_id/id],
    {$columns}
    {$currency} AS [currency_id/id],
    (l.LNITMSEQ / 16384) * 10 AS [{$prefix}/sequence],
    CASE
        WHEN NULLIF(RTRIM(l.ITEMNMBR), '') IS NULL THEN ''
        WHEN product_item.Item_Number IS NULL THEN ''
        ELSE {$product}
    END AS [{$prefix}/product_id/id],
    RTRIM(l.ITEMDESC) AS [{$prefix}/name],
    l.QUANTITY AS [{$prefix}/{$qty}],
    {$uom} AS [{$prefix}/{$uomColumn}],
    l.UNITPRCE AS [{$prefix}/price_unit],
    l.MRKDNPCT AS [{$prefix}/discount],
    l.TAXAMNT AS gp_tax_amount,
    l.UNITCOST AS gp_unit_cost,
    RTRIM(l.UOFM) AS gp_uom,
    RTRIM(h.SLPRSNID) AS gp_salesperson
FROM {$spec['sopLineTable']} AS l
INNER JOIN {$spec['sopTable']} AS h
    ON h.SOPNUMBE = l.SOPNUMBE
   AND h.SOPTYPE = l.SOPTYPE
LEFT JOIN dbo.spvItemMaster AS product_item
    ON RTRIM(product_item.Item_Number) = RTRIM(l.ITEMNMBR)
   AND RTRIM(product_item.Item_Type) <> 'Kit'
WHERE l.SOPTYPE = {$spec['sopType']}
  AND h.VOIDSTTS = 0
  AND {$keep}
",
    ];
}

function purchase_exports(): array
{
    return [
        po_header_export('purchase_orders_open', 'Open purchase orders', 'Purchase orders still open in GP.', 'dbo.POP10100'),
        po_line_export('purchase_orders_open', 'Open purchase orders', 'dbo.POP10100', 'dbo.POP10110'),
        po_header_export('purchase_orders_history', 'Historical purchase orders', 'Purchase orders moved to history.', 'dbo.POP30100'),
        po_line_export('purchase_orders_history', 'Historical purchase orders', 'dbo.POP30100', 'dbo.POP30110'),
        vendor_bill_export('vendor_bills_open', 'Open vendor bills', 'Unpaid and open payables invoices and credit memos. Each bill is one line for the GP document total.', 'dbo.PM20000'),
        vendor_bill_export('vendor_bills_history', 'Historical vendor bills', 'Payables history. Each bill is one line for the GP document total. Payments are not included.', 'dbo.PM30200'),
    ];
}

function po_status_sql(string $alias): string
{
    return "CASE {$alias}.POSTATUS
        WHEN 1 THEN 'New'
        WHEN 2 THEN 'Released'
        WHEN 3 THEN 'Change Order'
        WHEN 4 THEN 'Received'
        WHEN 5 THEN 'Closed'
        WHEN 6 THEN 'Canceled'
        ELSE CONVERT(varchar(10), {$alias}.POSTATUS)
    END";
}

function po_header_export(string $base, string $title, string $detail, string $table): array
{
    $status = po_status_sql('h');
    $term = payment_term_id_sql('h.PYMTRMID');
    $currency = currency_id_sql('h.CURNCYID');
    $openAmount = $table === 'dbo.POP10100' ? "\n    h.ONORDAMT AS gp_amount_open," : '';
    return [
        'key' => $base . '_headers',
        'group' => 'Purchasing',
        'title' => $title . ' — headers',
        'detail' => $detail . ' Use the lines file to import into Odoo.',
        'odoo' => 'Purchase orders',
        'file' => 'odoo18_' . $base . '_headers.csv',
        'count_sql' => "SELECT COUNT(*) AS c FROM {$table}",
        'sql' => "
SELECT
    CONCAT('po_', RTRIM(h.PONUMBER)) AS id,
    RTRIM(h.PONUMBER) AS name,
    CONCAT('__import__.vendor_', RTRIM(h.VENDORID)) AS [partner_id/id],
    CONVERT(varchar(10), h.DOCDATE, 23) AS date_order,
    {$term} AS [payment_term_id/id],
    {$currency} AS [currency_id/id],{$openAmount}
    {$status} AS gp_status,
    RTRIM(h.BUYERID) AS gp_buyer
FROM {$table} AS h
",
    ];
}

function po_line_export(string $base, string $title, string $headerTable, string $lineTable): array
{
    $term = payment_term_id_sql('h.PYMTRMID');
    $currency = currency_id_sql('h.CURNCYID');
    $uom = uom_id_sql('l.UOFM');
    return [
        'key' => $base . '_lines',
        'group' => 'Purchasing',
        'title' => $title . ' — lines',
        'detail' => 'Odoo purchase order import. One row per line. ' . $title . '.',
        'odoo' => 'Purchase orders with lines',
        'file' => 'odoo18_' . $base . '_lines.csv',
        'count_sql' => "SELECT COUNT(*) AS c FROM {$lineTable}",
        'sql' => "
SELECT
    CONCAT('po_', RTRIM(h.PONUMBER)) AS id,
    RTRIM(h.PONUMBER) AS name,
    CONCAT('__import__.vendor_', RTRIM(h.VENDORID)) AS [partner_id/id],
    CONVERT(varchar(10), h.DOCDATE, 23) AS date_order,
    {$term} AS [payment_term_id/id],
    {$currency} AS [currency_id/id],
    l.LineNumber AS [order_line/sequence],
    CASE WHEN NULLIF(RTRIM(l.ITEMNMBR), '') IS NULL THEN '' ELSE CONCAT('__import__.product_variant_', RTRIM(l.ITEMNMBR)) END AS [order_line/product_id/id],
    RTRIM(l.ITEMDESC) AS [order_line/name],
    l.QTYORDER AS [order_line/product_qty],
    {$uom} AS [order_line/product_uom/id],
    l.UNITCOST AS [order_line/price_unit],
    CONVERT(varchar(19), l.PRMDATE, 120) AS [order_line/date_planned],
    RTRIM(l.VNDITNUM) AS gp_vendor_product_code
FROM {$lineTable} AS l
INNER JOIN {$headerTable} AS h
    ON h.PONUMBER = l.PONUMBER
",
    ];
}

function vendor_bill_export(string $key, string $title, string $detail, string $table): array
{
    $term = payment_term_id_sql('v.PYMTRMID');
    $currency = currency_id_sql('v.CURNCYID');
    return [
        'key' => $key,
        'group' => 'Purchasing',
        'title' => $title,
        'detail' => $detail . ' The line is the GP document total and has no product, so map an expense account during import.',
        'odoo' => 'Vendor bills',
        'file' => 'odoo18_' . $key . '.csv',
        'count_sql' => "SELECT COUNT(*) AS c FROM {$table} WHERE DOCTYPE IN (1, 5) AND VOIDED = 0",
        'sql' => "
SELECT
    CONCAT('bill_', RTRIM(v.VCHRNMBR)) AS id,
    CASE v.DOCTYPE WHEN 5 THEN 'in_refund' ELSE 'in_invoice' END AS move_type,
    CONCAT('__import__.vendor_', RTRIM(v.VENDORID)) AS [partner_id/id],
    RTRIM(v.DOCNUMBR) AS ref,
    CONVERT(varchar(10), v.DOCDATE, 23) AS invoice_date,
    CONVERT(varchar(10), v.DUEDATE, 23) AS invoice_date_due,
    {$currency} AS [currency_id/id],
    {$term} AS [invoice_payment_term_id/id],
    1 AS [invoice_line_ids/quantity],
    v.DOCAMNT AS [invoice_line_ids/price_unit],
    COALESCE(NULLIF(RTRIM(v.TRXDSCRN), ''), CONCAT('GP vendor bill ', RTRIM(v.DOCNUMBR))) AS [invoice_line_ids/name],
    v.CURTRXAM AS gp_amount_due,
    v.TAXAMNT AS gp_tax,
    RTRIM(v.PONUMBER) AS gp_purchase_order
FROM {$table} AS v
WHERE v.DOCTYPE IN (1, 5)
  AND v.VOIDED = 0
",
    ];
}
