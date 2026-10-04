<?php

namespace Tests\Unit;

use App\Services\GpConnection;
use App\Services\OdooClient;
use App\Services\SalespadUserFields;
use Tests\TestCase;

class SalespadUserFieldSuggestionTest extends TestCase
{
    public function test_disabled_selection_fields_keep_semicolon_choices(): void
    {
        $fields = new SalespadUserFields($this->createMock(GpConnection::class), $this->createMock(OdooClient::class));

        $suggestion = $fields->suggest('varchar', 'System.String', 'EMAIL; TEXT; CALL TO US', true);

        $this->assertSame('selection', $suggestion['field_type']);
        $this->assertSame('EMAIL; TEXT; CALL TO US', $suggestion['options']);
        $this->assertTrue($suggestion['disabled']);
    }

    public function test_yes_no_and_numbers_are_not_selections(): void
    {
        $fields = new SalespadUserFields($this->createMock(GpConnection::class), $this->createMock(OdooClient::class));

        $this->assertSame('boolean', $fields->suggest('bit', 'System.Boolean', '', false)['field_type']);
        $this->assertSame('number', $fields->suggest('int', 'System.Int32', '', false)['field_type']);
        $this->assertSame('text', $fields->suggest('varchar', 'System.String', '', false)['field_type']);
    }

    public function test_checked_fields_replace_the_user_fields_card(): void
    {
        $fields = new SalespadUserFields($this->createMock(GpConnection::class), $this->createMock(OdooClient::class));
        $arch = <<<'XML'
<data>
  <xpath expr="/form//page[@name='accounting_disabled']" position="after">
    <page string="User Feilds" name="studio_page">
      <group><field name="x_studio_char_2"/></group>
    </page>
  </xpath>
</data>
XML;

        $updated = $fields->mergeCard($arch, ['x_spx_company_type', 'x_spx_number_of_stores']);

        $this->assertNotNull($updated);
        $this->assertStringContainsString('name="x_spx_company_type"', $updated);
        $this->assertStringContainsString('name="x_spx_number_of_stores"', $updated);
        $this->assertStringNotContainsString('x_studio_char_2', $updated);
        $this->assertStringContainsString('User Fields', $updated);
    }
}
