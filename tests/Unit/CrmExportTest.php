<?php

namespace Tests\Unit;

use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use App\Services\GpSync;
use Tests\TestCase;

class CrmExportTest extends TestCase
{
    public function test_crm_notes_and_events_follow_customer_addresses(): void
    {
        $keys = array_column(app(ExportCatalog::class)->all(), 'key');
        $addresses = array_search('customer_addresses', $keys, true);

        $this->assertIsInt($addresses);
        $this->assertSame('customer_crm_notes', $keys[$addresses + 1]);
        $this->assertSame('customer_crm_events', $keys[$addresses + 2]);
    }

    public function test_crm_files_point_at_the_odoo_records_they_create(): void
    {
        $catalog = app(ExportCatalog::class);
        $notes = $catalog->find('customer_crm_notes');
        $events = $catalog->find('customer_crm_events');
        $planner = app(ConversionPlanner::class);

        $this->assertStringContainsString('spCRMNote', $notes['sql']);
        $this->assertStringContainsString('mail.mt_note', $notes['sql']);
        $this->assertSame('res.partner', $notes['resolve_res_id']);
        $this->assertSame(['mail.message'], $planner->guide('customer_crm_notes', $notes['odoo'])['models']);

        $this->assertStringContainsString('spCRMEvent', $events['sql']);
        $this->assertStringContainsString('[[event_user_id]]', $events['sql']);
        $this->assertStringContainsString('Assigned_To', $events['sql']);
        $this->assertSame(['calendar.event'], $planner->guide('customer_crm_events', $events['odoo'])['models']);
        $this->assertSame('calendar.event', app(GpSync::class)->profile('customer_crm_events')['odoo']['model']);
    }

    public function test_an_event_is_assigned_only_when_the_person_matches(): void
    {
        $catalog = app(ExportCatalog::class);
        $users = [
            ['id' => 10, 'name' => 'Myles Steffen', 'login' => 'alex@example.com', 'email' => 'alex@example.com', 'partner_id' => [100, 'Myles Steffen']],
            ['id' => 11, 'name' => 'Justin Baehr', 'login' => 'justin@example.com', 'email' => 'justin@example.com', 'partner_id' => [110, 'Justin Baehr']],
            ['id' => 12, 'name' => 'Alyssa Exline', 'login' => 'alyssa@example.com', 'email' => 'alyssa@example.com', 'partner_id' => [120, 'Alyssa Exline']],
        ];
        $links = [
            '0025' => ['user_id' => 11, 'partner_id' => 110, 'name' => 'Justin Baehr'],
            '0020' => ['user_id' => 12, 'partner_id' => 120, 'name' => 'Alyssa Exline'],
        ];

        $myles = $catalog->matchEventAssignee('MYLES.STEFFEN', [
            'login' => 'myles.steffen',
            'display' => 'Myles Steffen',
            'sp' => '0019',
            'email' => 'myles@example.com',
        ], $users, $links);
        $morgan = $catalog->matchEventAssignee('MORGAN.OSBORN', [
            'login' => 'morgan.osborn',
            'display' => 'Morgan Osborn',
            'sp' => '0025',
            'email' => 'morgan@example.com',
        ], $users, $links);
        $alyssa = $catalog->matchEventAssignee('0020', null, $users, $links);

        $this->assertSame(10, $myles['user_id']);
        $this->assertNull($morgan);
        $this->assertSame(12, $alyssa['user_id']);
    }

    public function test_a_product_category_id_uses_the_casing_already_in_odoo(): void
    {
        $catalog = app(ExportCatalog::class);
        $export = ['category_id_map' => ['product_categ_999-gngsho' => 'product_categ_999-GngSho']];

        $this->assertSame(
            'product_categ_999-GngSho',
            $catalog->categoryExternalId($export, 'categ_id/id', 'product_categ_999-Gngsho')
        );
        $this->assertSame('product_100', $catalog->categoryExternalId($export, 'id', 'product_100'));
    }
}
