<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Models\SyncExport;
use App\Models\User;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_two_stays_closed_until_the_checklist_is_finished(): void
    {
        $this->signIn();

        $this->get(route('sync.show'))
            ->assertOk()
            ->assertSee('Finish Step One first')
            ->assertSee('Since every system, ongoing remove and update cannot be done via sync.')
            ->assertDontSee('Download new');
    }

    public function test_a_finished_checklist_can_record_the_current_count(): void
    {
        $this->signIn();
        $this->finishChecklist();

        $this->mock(GpConnection::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(null);
            $mock->shouldReceive('rows')->andReturn([
                ['c' => 106, 'mx' => 900, 'new_count' => 106],
            ]);
        });

        $this->get(route('sync.show'))
            ->assertOk()
            ->assertSee('Ongoing resync')
            ->assertSee('Open sales orders — headers')
            ->assertDontSee('Download new');

        $this->post(route('sync.baseline', 'sales_orders_open_headers'))
            ->assertRedirect(route('sync.show'));

        $row = SyncExport::query()->where('export_key', 'sales_orders_open_headers')->first();
        $this->assertNotNull($row);
        $this->assertSame(106, $row->record_count);
        $this->assertSame(0, $row->new_count);
        $this->assertSame(900, $row->watermark);
        $this->assertNotNull($row->last_export_at);
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
            'intro_open' => false,
        ]);
        $this->actingAs($user);
    }

    private function finishChecklist(): void
    {
        $company = Company::query()->first();
        $sort = 0;
        foreach (app(ExportCatalog::class)->all() as $export) {
            ConversionStep::query()->create([
                'company_id' => $company->id,
                'export_key' => $export['key'],
                'sort' => $sort,
                'status' => 'complete',
                'completed_at' => now(),
            ]);
            $sort++;
        }
    }
}
