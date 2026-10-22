<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class ProductsTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_creates_a_product_and_lists_it_with_its_price(): void
    {
        $this->assertPageOk($this->get('products/form'), 'product form');

        $name     = $this->uniqueName('Consulting hour');
        $response = $this->postForm('products/form', [
            'product_sku'         => 'SKU-' . bin2hex(random_bytes(3)),
            'product_name'        => $name,
            'product_description' => 'Regression product',
            'product_price'       => '125.50',
            'purchase_price'      => '80.00',
            'provider_name'       => 'Regression Supplier',
            'family_id'           => '',
            'unit_id'             => '',
            'tax_rate_id'         => '',
            'product_tariff'      => '',
            'btn_submit'          => '1',
        ]);

        $this->assertContains($response->getStatusCode(), [302, 303, 307], 'Product form did not redirect after save.');
        $this->assertStringEndsWith('/products', $this->locationPath($response));

        $list = $this->assertPageOk($this->get('products'), 'product list');
        $this->assertSee(htmlspecialchars($name), $list);
        $this->assertSee('125.50', $list);
    }

    #[Test]
    public function it_requires_a_product_name_and_price(): void
    {
        $page = $this->assertPageOk(
            $this->postForm('products/form', ['product_name' => '', 'product_price' => '', 'btn_submit' => '1']),
            'invalid product'
        );

        $this->assertSee('alert-danger', $page);
    }
}
