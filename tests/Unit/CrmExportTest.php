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
        $this->assertSame('customer_interactions', $keys[$addresses + 2]);
        $this->assertSame('customer_crm_events', $keys[$addresses + 3]);
    }

    public function test_crm_files_point_at_the_odoo_records_they_create(): void
    {
        $catalog = app(ExportCatalog::class);
        $notes = $catalog->find('customer_crm_notes');
        $interactions = $catalog->find('customer_interactions');
        $events = $catalog->find('customer_crm_events');
        $planner = app(ConversionPlanner::class);

        $this->assertStringContainsString('spCRMNote', $notes['sql']);
        $this->assertStringContainsString('mail.mt_note', $notes['sql']);
        $this->assertStringContainsString('[[note_author_id]]', $notes['sql']);
        $this->assertSame('Author / Database ID', $notes['fields']['author_id/.id']);
        $this->assertSame('res.partner', $notes['resolve_res_id']);
        $this->assertSame(['mail.message'], $planner->guide('customer_crm_notes', $notes['odoo'])['models']);

        $this->assertStringContainsString('spvCustomerInteraction', $interactions['sql']);
        $this->assertStringContainsString('[[interaction_author_id]]', $interactions['sql']);
        $this->assertStringContainsString('mail.mt_note', $interactions['sql']);
        $this->assertSame('Author / Database ID', $interactions['fields']['author_id/.id']);
        $this->assertSame('res.partner', $interactions['resolve_res_id']);
        $this->assertSame(['mail.message'], $planner->guide('customer_interactions', $interactions['odoo'])['models']);
        $this->assertSame('mail.message', app(GpSync::class)->profile('customer_interactions')['odoo']['model']);

        $this->assertStringContainsString('spCRMEvent', $events['sql']);
        $this->assertStringContainsString('spvInteraction', $events['sql']);
        $this->assertStringContainsString('crm_event_interaction_', $events['sql']);
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

    public function test_a_note_author_uses_the_matching_odoo_person(): void
    {
        $catalog = app(ExportCatalog::class);
        $users = [
            ['id' => 10, 'name' => 'Myles Steffen', 'login' => 'alex@example.com', 'email' => 'alex@example.com', 'partner_id' => [100, 'Myles Steffen']],
            ['id' => 20, 'name' => 'Erik Shahinian', 'login' => 'erik@example.com', 'email' => 'erik@example.com', 'partner_id' => [200, 'Erik Shahinian']],
        ];
        $salespad = [
            ['login' => 'myles.steffen', 'display' => 'Myles Steffen', 'sp' => '0019', 'email' => 'myles@example.com'],
            ['login' => 'erik.shahinian', 'display' => 'Erik Shahinian', 'sp' => '', 'email' => 'erik@example.com'],
        ];

        $sql = $catalog->noteAuthorSql(['MYLES.STEFFEN', 'ERIKSHAHINIAN', 'NICOLE'], $salespad, [$users[0]], [], [
            'ERIK SHAHINIAN' => 200,
        ]);

        $this->assertStringContainsString("WHEN 'ERIKSHAHINIAN' THEN '200'", $sql);
        $this->assertStringContainsString("WHEN 'MYLES.STEFFEN' THEN '100'", $sql);
        $this->assertStringContainsString('n.Created_By', $sql);
        $this->assertStringNotContainsString('NICOLE', $sql);

        $assigned = $catalog->noteAuthorSql(['ANDY.FORTIER'], [
            ['login' => 'andy.fortier', 'display' => 'Andy Fortier', 'sp' => '', 'email' => 'andy@example.com'],
        ], [
            ['id' => 30, 'name' => 'Andy Fortier', 'login' => 'andy@example.com', 'email' => 'andy@example.com', 'partner_id' => [300, 'Andy Fortier']],
        ], [], [], 'i.Assigned_To');

        $this->assertStringContainsString('i.Assigned_To', $assigned);
        $this->assertStringContainsString("WHEN 'ANDY.FORTIER' THEN '300'", $assigned);
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
