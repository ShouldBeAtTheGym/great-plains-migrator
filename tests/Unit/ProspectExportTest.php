<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use App\Services\GpSync;
use Tests\TestCase;

class ProspectExportTest extends TestCase
{
    public function test_prospects_follow_the_customer_files_and_point_at_crm(): void
    {
        $keys = array_column(app(ExportCatalog::class)->all(), 'key');
        $addresses = array_search('vendor_addresses', $keys, true);
        $prospects = array_search('prospects', $keys, true);
        $contacts = array_search('prospect_contacts', $keys, true);
        $interactions = array_search('prospect_interactions', $keys, true);

        $this->assertIsInt($addresses);
        $this->assertSame($addresses + 1, $prospects);
        $this->assertSame($prospects + 1, $contacts);
        $this->assertSame($contacts + 1, $interactions);

        $catalog = app(ExportCatalog::class);
        $prospectsExport = $catalog->find('prospects');
        $contactsExport = $catalog->find('prospect_contacts');
        $interactionsExport = $catalog->find('prospect_interactions');
        $planner = app(ConversionPlanner::class);

        $this->assertStringContainsString('spvProspect', $prospectsExport['sql']);
        $this->assertStringContainsString("xLast_Contacted >= '2026-01-01'", $prospectsExport['sql']);
        $adapted = $catalog->prospectSql($prospectsExport['sql'], ['Prospect_ID', 'Prospect_Class', 'Historical'], ['Contact_ID']);
        $this->assertStringNotContainsString('xLast_Contacted', $adapted);
        $this->assertStringContainsString('ISNULL(p.Historical, 0) = 0', $adapted);
        $this->assertStringContainsString('p.Prospect_Class', $adapted);
        $this->assertStringNotContainsString('p.xClass', $adapted);
        $kept = $catalog->prospectSql($prospectsExport['sql'], ['xLast_Contacted', 'xClass', 'Historical'], ['xTitle']);
        $this->assertStringContainsString("xLast_Contacted >= '2026-01-01'", $kept);
        $this->assertStringContainsString('p.xClass', $kept);
        $this->assertStringContainsString('ISNULL(p.Historical, 0) = 0', $prospectsExport['sql']);
        $this->assertStringContainsString('spvProspectContact', $prospectsExport['sql']);
        $this->assertStringContainsString('ct.Prospect_ID = p.Prospect_ID', $prospectsExport['sql']);
        $this->assertStringContainsString("'opportunity' AS [type]", $prospectsExport['sql']);
        $this->assertStringContainsString('RTRIM(p.Sales_Person_ID) AS gp_salesperson', $prospectsExport['sql']);
        $this->assertSame('CRM → Pipeline', $planner->guide('prospects', $prospectsExport['odoo'])['screen']);
        $this->assertSame(['crm.lead'], $planner->guide('prospects', $prospectsExport['odoo'])['models']);
        $this->assertSame('crm.lead', app(GpSync::class)->profile('prospects')['odoo']['model']);

        $this->assertStringContainsString('spvProspectContact', $contactsExport['sql']);
        $this->assertStringContainsString('c.Prospect_ID', $contactsExport['sql']);
        $this->assertStringContainsString('prospect_contact_', $contactsExport['sql']);
        $this->assertSame(['res.partner'], $planner->guide('prospect_contacts', $contactsExport['odoo'])['models']);
        $this->assertSame('res.partner', app(GpSync::class)->profile('prospect_contacts')['odoo']['model']);

        $this->assertSame('crm.lead', $interactionsExport['resolve_res_id']);
        $this->assertStringContainsString('spvProspectInteraction', $interactionsExport['sql']);
        $this->assertStringContainsString('p.Prospect_ID = i.Prospect_ID', $interactionsExport['sql']);
        $this->assertStringContainsString("CHAR(13) + CHAR(10), '<br>'", $interactionsExport['sql']);
        $this->assertStringContainsString("CHAR(10), '<br>'", $interactionsExport['sql']);
        $this->assertStringContainsString("CHAR(13), '<br>'", $interactionsExport['sql']);
        $this->assertStringContainsString("'crm.lead' AS model", $interactionsExport['sql']);
        $this->assertSame('Settings → Technical → Messages', $planner->guide('prospect_interactions', $interactionsExport['odoo'])['screen']);
        $this->assertSame(['mail.message'], $planner->guide('prospect_interactions', $interactionsExport['odoo'])['models']);
        $this->assertSame('mail.message', app(GpSync::class)->profile('prospect_interactions')['odoo']['model']);
    }

    public function test_a_missing_opportunity_is_left_out_of_the_interaction_file(): void
    {
        $copies = app(\App\Services\GpCopyService::class);
        $export = ['resolve_res_id' => 'crm.lead', 'res_id_map' => ['prospect_8' => 44]];

        $this->assertNull($copies->problemFor([
            'id' => 'prospect_interaction_20',
            'res_id' => 'prospect_8',
            'subject' => 'Call',
            'date' => '2026-05-01',
        ], $export));
        $this->assertSame(
            ['prospect_interaction_21', '2026-05-02', '9', 'Visit', 'Opportunity is not in Odoo.'],
            $copies->problemFor([
                'id' => 'prospect_interaction_21',
                'res_id' => 'prospect_9',
                'subject' => 'Visit',
                'date' => '2026-05-02',
            ], $export)
        );

        $before = $copies->fingerprint($export);
        $export['res_id_map']['prospect_9'] = 45;
        $this->assertNotSame($before, $copies->fingerprint($export));
    }

    public function test_a_prospect_salesperson_becomes_the_odoo_user(): void
    {
        $export = app(ExportCatalog::class)->prepare(new Company, app(ExportCatalog::class)->find('prospects'));

        $this->assertStringContainsString("'' AS [user_id/id]", $export['sql']);
        $this->assertStringNotContainsString('gp_salesperson', $export['sql']);
        $this->assertSame('Salesperson / External ID', $export['fields']['user_id/id']);
    }
}
