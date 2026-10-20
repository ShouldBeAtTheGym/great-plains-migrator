<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Models\GpCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpCopyProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_step_shows_the_copy_progress(): void
    {
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'sort' => 0,
            'status' => 'pending',
        ]);

        $this->get('/steps/payment_terms')
            ->assertOk()
            ->assertSee('Next file is Payment terms')
            ->assertSee('Records')
            ->assertSee('Processed')
            ->assertSee('Current record')
            ->assertSee('id="step-downloads"', false);
    }

    public function test_copy_progress_returns_the_saved_counts(): void
    {
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'sort' => 0,
            'status' => 'pending',
        ]);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'status' => 'running',
            'record_count' => 12,
            'processed_count' => 5,
            'saved_count' => 5,
            'current_record' => 'payment_term_NET',
            'started_at' => now(),
            'pid' => getmypid(),
        ]);

        $this->getJson('/steps/payment_terms/copy')
            ->assertOk()
            ->assertJsonPath('record_count', 12)
            ->assertJsonPath('processed_count', 5)
            ->assertJsonPath('current_record', 'payment_term_NET')
            ->assertJsonPath('percent', 42);

        $this->getJson('/steps/customers/copy')->assertNotFound();

        $this->getJson('/copies')
            ->assertOk()
            ->assertJsonPath('active.key', 'payment_terms')
            ->assertJsonPath('active.title', 'Payment terms')
            ->assertJsonPath('steps.0.key', 'payment_terms');
    }
}
