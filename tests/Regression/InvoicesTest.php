<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class InvoicesTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_creates_an_invoice_with_line_items_and_computes_the_total(): void
    {
        $invoiceId = $this->createInvoice($this->createClient($this->uniqueName('Invoice Co')));

        $this->saveInvoiceItems($invoiceId, [
            ['name' => 'Implementation', 'quantity' => '2', 'price' => '150.00'],
            ['name' => 'Support plan', 'quantity' => '1', 'price' => '49.99'],
        ]);

        $page = $this->assertPageOk($this->get("invoices/view/{$invoiceId}"), 'invoice view');
        $this->assertSee('Implementation', $page);
        $this->assertSee('Support plan', $page);
        $this->assertSee('349.99', $this->invoiceTotalCell($page));
        $this->assertSame('349.99', number_format((float) $this->invoiceBalance($invoiceId), 2, '.', ''));

        $list = $this->assertPageOk($this->get('invoices/status/all'), 'invoice list');
        $this->assertSee("invoices/view/{$invoiceId}", $list);
    }

    #[Test]
    public function it_rejects_negative_quantities(): void
    {
        $invoiceId = $this->createInvoice($this->createClient($this->uniqueName('Negative Co')));

        $json = $this->postAjaxJson('invoices/ajax/save', [
            'invoice_id'           => $invoiceId,
            'invoice_number'       => '',
            'invoice_date_created' => $this->today(),
            'invoice_date_due'     => $this->today(),
            'invoice_status_id'    => '1',
            'items'                => $this->itemsJson([['name' => 'Bad', 'quantity' => '-1', 'price' => '10']], true),
        ]);

        $this->assertSame(0, $json['success'] ?? null);
        $this->assertNotEmpty($json['validation_errors'] ?? []);
    }

    #[Test]
    public function it_generates_the_invoice_pdf(): void
    {
        $invoiceId = $this->createInvoice($this->createClient($this->uniqueName('PDF Co')));
        $this->saveInvoiceItems($invoiceId, [['name' => 'PDF line', 'quantity' => '3', 'price' => '10.00']]);

        $response = $this->request('GET', "invoices/generate_pdf/{$invoiceId}", [
            'query' => [self::CSRF_FIELD => $this->csrfToken()],
        ]);

        $this->assertPdf($response, 'invoice PDF');
    }
}
