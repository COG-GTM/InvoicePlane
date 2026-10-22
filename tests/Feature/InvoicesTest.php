<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;

final class InvoicesTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_creates_an_invoice_with_a_number_and_empty_totals(): void
    {
        $clientId  = $this->createClient();
        $invoiceId = $this->createInvoice($clientId);

        $invoice = self::fetchRow('SELECT * FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]);
        $this->assertSame((string) $clientId, (string) $invoice['client_id']);
        $this->assertSame('1', (string) $invoice['invoice_status_id']);
        $this->assertNotEmpty($invoice['invoice_url_key']);

        $this->assertPageOk($this->get("invoices/view/{$invoiceId}"), 'invoice view');
    }

    #[Test]
    public function it_saves_line_items_and_calculates_totals(): void
    {
        $taxRateId = $this->createTaxRate(self::uniqueName('VAT'), '10');
        $clientId  = $this->createClient();
        $invoiceId = $this->createInvoice($clientId);

        $json = $this->saveInvoice($invoiceId, [
            self::item('Consulting', '2', '150.00', $taxRateId),
            self::item('Hosting', '3', '19.99'),
        ]);
        $this->assertSame(1, $json['success'] ?? null, 'Invoice save failed: ' . json_encode($json));

        $items = self::db()->query("SELECT item_name, item_quantity, item_price FROM ip_invoice_items WHERE invoice_id = {$invoiceId} ORDER BY item_order")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(2, $items);
        $this->assertSame('Consulting', $items[0]['item_name']);
        $this->assertEquals(2, (float) $items[0]['item_quantity']);
        $this->assertEquals(19.99, (float) $items[1]['item_price']);

        // 2 x 150.00 = 300.00 (+10% tax = 30.00), 3 x 19.99 = 59.97
        $amounts = self::invoiceAmounts($invoiceId);
        $this->assertSame('359.97', $amounts['invoice_item_subtotal']);
        $this->assertSame('30.00', $amounts['invoice_item_tax_total']);
        $this->assertSame('389.97', $amounts['invoice_total']);
        $this->assertSame('389.97', $amounts['invoice_balance']);

        $view = $this->assertPageOk($this->get("invoices/view/{$invoiceId}"), 'invoice view');
        $this->assertStringContainsString('Consulting', $view);
        $this->assertStringContainsString('Hosting', $view);
        $this->assertStringContainsString('389.97', $view);
    }

    #[Test]
    public function it_edits_line_items_and_recalculates_totals(): void
    {
        $clientId  = $this->createClient();
        $invoiceId = $this->createInvoice($clientId);
        $this->saveInvoice($invoiceId, [self::item('Widget', '1', '100.00')]);
        $this->assertSame('100.00', self::invoiceAmounts($invoiceId)['invoice_total']);

        $itemId = (string) self::fetchValue('SELECT item_id FROM ip_invoice_items WHERE invoice_id = ?', [$invoiceId]);
        $json   = $this->saveInvoice($invoiceId, [
            self::item('Widget', '4', '100.00', '0', $itemId),
            self::item('Setup fee', '1', '25.50'),
        ]);
        $this->assertSame(1, $json['success'] ?? null, json_encode($json));

        $this->assertSame('2', (string) self::fetchValue('SELECT COUNT(*) FROM ip_invoice_items WHERE invoice_id = ?', [$invoiceId]));
        $this->assertSame('425.50', self::invoiceAmounts($invoiceId)['invoice_total']);
    }

    #[Test]
    public function it_applies_an_invoice_level_percent_discount(): void
    {
        $clientId  = $this->createClient();
        $invoiceId = $this->createInvoice($clientId);

        $json = $this->saveInvoice($invoiceId, [self::item('Licence', '1', '200.00')], 1, ['invoice_discount_percent' => '10']);
        $this->assertSame(1, $json['success'] ?? null, json_encode($json));

        $this->assertSame('180.00', self::invoiceAmounts($invoiceId)['invoice_total']);
    }

    #[Test]
    public function it_rejects_negative_quantities(): void
    {
        $clientId  = $this->createClient();
        $invoiceId = $this->createInvoice($clientId);

        $json = $this->saveInvoice($invoiceId, [self::item('Refund hack', '-1', '50.00')]);

        $this->assertSame(0, $json['success'] ?? null);
        $this->assertSame('0', (string) self::fetchValue('SELECT COUNT(*) FROM ip_invoice_items WHERE invoice_id = ?', [$invoiceId]));
    }

    #[Test]
    public function it_lists_invoices_by_status(): void
    {
        $clientName = self::uniqueName('Listed');
        $clientId   = $this->createClient(['client_name' => $clientName]);
        $invoiceId  = $this->createInvoice($clientId);
        $this->saveInvoice($invoiceId, [self::item('Thing', '1', '10.00')], 2);

        $this->assertSame('2', (string) self::fetchValue('SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]));

        foreach (['invoices/status/all', 'invoices/status/sent'] as $page) {
            $body = $this->assertPageOk($this->get($page), $page);
            $this->assertStringContainsString('invoices/view/', $body, "{$page} lists no invoices");
        }

        $this->assertStringContainsString("invoices/view/{$invoiceId}", $this->filterList('invoices', $clientName));

        $this->assertPageOk($this->get('dashboard'), 'dashboard');
    }
}
