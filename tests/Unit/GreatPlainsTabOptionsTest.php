<?php

namespace Tests\Unit;

use App\Services\GpConnection;
use App\Services\GreatPlainsTab;
use App\Services\OdooClient;
use Tests\TestCase;

class GreatPlainsTabOptionsTest extends TestCase
{
    public function test_distinct_values_are_trimmed_and_sorted(): void
    {
        $tab = new GreatPlainsTab($this->createMock(GpConnection::class), $this->createMock(OdooClient::class));

        $values = $tab->values([
            ['value' => ' UPS '],
            ['value' => ''],
            ['value' => 'UPS'],
            ['value' => 'AAA COOPER'],
            ['value' => '  '],
        ]);

        $this->assertSame(['AAA COOPER', 'UPS'], $values);
    }

    public function test_the_tab_holds_both_selection_fields(): void
    {
        $tab = new GreatPlainsTab($this->createMock(GpConnection::class), $this->createMock(OdooClient::class));
        $arch = $tab->pageArch();

        $this->assertStringContainsString('string="Great Plains"', $arch);
        $this->assertStringContainsString('name="x_gp_customer_number"', $arch);
        $this->assertStringContainsString('name="x_gp_customer_class"', $arch);
        $this->assertStringContainsString('name="x_gp_shipping_method"', $arch);
        $related = $tab->relatedArch();
        $this->assertStringContainsString('child_ids', $related);
        $this->assertStringContainsString('name="x_gp_address_code"', $related);
        $this->assertStringContainsString('name="x_gp_is_primary"', $related);
        $this->assertStringContainsString('name="x_gp_is_bill_to"', $related);
        $this->assertStringContainsString('name="x_gp_is_ship_to"', $related);
    }
}
