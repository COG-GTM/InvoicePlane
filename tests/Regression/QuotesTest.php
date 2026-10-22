<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class QuotesTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    private function createQuote(int $clientId): int
    {
        $modal = $this->assertPageOk(
            $this->postAjax('quotes/ajax/modal_create_quote', ['client_id' => $clientId]),
            'create-quote modal'
        );

        $json = $this->postAjaxJson('quotes/ajax/create', [
            'client_id'          => $clientId,
            'quote_date_created' => $this->today(),
            'quote_password'     => '',
            'user_id'            => $this->adminUserId(),
            'invoice_group_id'   => $this->defaultGroupId($modal),
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Quote create failed: ' . json_encode($json));

        return (int) $json['quote_id'];
    }

    private function saveQuoteItems(int $quoteId, array $lines): void
    {
        $json = $this->postAjaxJson('quotes/ajax/save', [
            'legacy_calculation'     => '0',
            'quote_id'               => $quoteId,
            'quote_number'           => '',
            'quote_date_created'     => $this->today(),
            'quote_date_expires'     => date('m/d/Y', strtotime('+15 days')),
            'quote_status_id'        => '1',
            'quote_password'         => '',
            'items'                  => $this->itemsJson($lines, false),
            'quote_discount_amount'  => '',
            'quote_discount_percent' => '',
            'notes'                  => 'Regression quote',
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Quote save failed: ' . json_encode($json));
    }

    #[Test]
    public function it_creates_a_quote_generates_its_pdf_and_converts_it_to_an_invoice(): void
    {
        $clientId = $this->createClient($this->uniqueName('Quote Co'));
        $quoteId  = $this->createQuote($clientId);

        $this->saveQuoteItems($quoteId, [
            ['name' => 'Discovery workshop', 'quantity' => '1', 'price' => '500.00'],
            ['name' => 'Design sprint', 'quantity' => '4', 'price' => '250.00'],
        ]);

        $quotePage = $this->assertPageOk($this->get("quotes/view/{$quoteId}"), 'quote view');
        $this->assertSee('Discovery workshop', $quotePage);
        $this->assertSee('1,500.00', $quotePage);

        $this->assertPdf(
            $this->request('GET', "quotes/generate_pdf/{$quoteId}", ['query' => [self::CSRF_FIELD => $this->csrfToken()]]),
            'quote PDF'
        );

        $modal = $this->assertPageOk(
            $this->postAjax("quotes/ajax/modal_quote_to_invoice/{$quoteId}", []),
            'quote-to-invoice modal'
        );

        $json = $this->postAjaxJson('quotes/ajax/quote_to_invoice', [
            'legacy_calculation'   => '0',
            'quote_id'             => $quoteId,
            'client_id'            => $clientId,
            'invoice_date_created' => $this->today(),
            'invoice_time_created' => date('H:i:s'),
            'invoice_group_id'     => $this->defaultGroupId($modal),
            'invoice_password'     => '',
            'user_id'              => $this->adminUserId(),
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Quote to invoice failed: ' . json_encode($json));
        $invoiceId = (int) $json['invoice_id'];

        $invoicePage = $this->assertPageOk($this->get("invoices/view/{$invoiceId}"), 'converted invoice');
        $this->assertSee('Discovery workshop', $invoicePage);
        $this->assertSee('Design sprint', $invoicePage);
        $this->assertSee('1,500.00', $this->invoiceTotalCell($invoicePage));

        $this->assertPageOk($this->get("quotes/view/{$quoteId}"), 'quote after conversion');
    }

    #[Test]
    public function it_lists_quotes(): void
    {
        $this->assertPageOk($this->get('quotes/status/all'), 'quote list');
    }
}
