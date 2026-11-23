<?php

namespace Tests\Unit;

use App\Services\OdooClient;
use Tests\TestCase;

class ProductVariantResolveTest extends TestCase
{
    public function test_a_company_product_uses_the_shared_item_when_that_is_what_odoo_has(): void
    {
        $odoo = new OdooClient;
        $known = [
            'product_variant_KOL21-03218' => true,
            'product_variant_blu2_BAR-0752' => true,
        ];

        $this->assertSame(
            '__import__.product_variant_KOL21-03218',
            $odoo->resolveProductVariant('__import__.product_variant_blu2_KOL21-03218', $known)
        );
        $this->assertSame(
            '__import__.product_variant_blu2_BAR-0752',
            $odoo->resolveProductVariant('__import__.product_variant_blu2_BAR-0752', $known)
        );
        $this->assertSame('', $odoo->resolveProductVariant('__import__.product_variant_blu2_MISSING', $known));
        $this->assertSame('', $odoo->resolveProductVariant('', $known));
    }
}
