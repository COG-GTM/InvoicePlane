<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;

final class QuotesTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    private function createQuote(int $clientId): int
    {
        $json = $this->postJson('quotes/ajax/create', [
            'client_id'          => $clientId,
            'quote_date_created' => date('m/d/Y'),
            'quote_password'     => '',
            'user_id'            => self::fetchValue('SELECT MIN(user_id) FROM ip_users'),
            'invoice_group_id'   => self::fetchValue("SELECT setting_value FROM ip_settings WHERE setting_key = 'default_quote_group'"),
        ]);
        $this->assertSame(1, $json['success'] ?? null, 'Quote create failed: ' . json_encode($json));

        return (int) $json['quote_id'];
    }

    private function saveQuote(int $quoteId, array $items): array
    {
        $order = 1;
        foreach ($items as &$item) {
            unset($item['item_task_id'], $item['item_is_recurring']);
            $item['quote_id']   = (string) $quoteId;
            $item['item_order'] = $order++;
        }
        unset($item);

        return $this->postJson('quotes/ajax/save', [
            'legacy_calculation'     => '1',
            'quote_id'               => $quoteId,
            'quote_number'           => (string) self::fetchValue('SELECT quote_number FROM ip_quotes WHERE quote_id = ?', [$quoteId]),
            'quote_date_created'     => date('m/d/Y'),
            'quote_date_expires'     => date('m/d/Y', strtotime('+15 days')),
            'quote_status_id'        => 1,
            'quote_password'         => '',
            'items'                  => json_encode($items),
            'quote_discount_amount'  => '',
            'quote_discount_percent' => '',
            'notes'                  => 'Regression quote',
        ]);
    }

    #[Test]
    public function it_creates_a_quote_with_items_and_totals(): void
    {
        $clientId = $this->createClient();
        $quoteId  = $this->createQuote($clientId);

        $json = $this->saveQuote($quoteId, [
            self::item('Design', '10', '75.00'),
            self::item('Review', '2', '50.00'),
        ]);
        $this->assertSame(1, $json['success'] ?? null, 'Quote save failed: ' . json_encode($json));

        $amounts = self::fetchRow('SELECT * FROM ip_quote_amounts WHERE quote_id = ?', [$quoteId]);
        $this->assertSame('850.00', $amounts['quote_item_subtotal']);
        $this->assertSame('850.00', $amounts['quote_total']);

        $view = $this->assertPageOk($this->get("quotes/view/{$quoteId}"), 'quote view');
        $this->assertStringContainsString('Design', $view);
        $this->assertStringContainsString('850.00', $view);

        $list = $this->assertPageOk($this->get('quotes/status/all'), 'quote list');
        $this->assertStringContainsString('quotes/view/', $list);

        $clientName = (string) self::fetchValue('SELECT client_name FROM ip_clients WHERE client_id = ?', [$clientId]);
        $this->assertStringContainsString("quotes/view/{$quoteId}", $this->filterList('quotes', $clientName));
    }

    #[Test]
    public function it_converts_a_quote_into_an_invoice(): void
    {
        $clientId = $this->createClient();
        $quoteId  = $this->createQuote($clientId);
        $this->saveQuote($quoteId, [self::item('Implementation', '3', '120.00')]);

        $json = $this->postJson('quotes/ajax/quote_to_invoice', [
            'client_id'            => $clientId,
            'quote_id'             => $quoteId,
            'invoice_date_created' => date('m/d/Y'),
            'invoice_group_id'     => self::fetchValue("SELECT setting_value FROM ip_settings WHERE setting_key = 'default_invoice_group'"),
            'invoice_time_created' => date('H:i:s'),
            'invoice_password'     => '',
            'user_id'              => self::fetchValue('SELECT MIN(user_id) FROM ip_users'),
        ]);
        $this->assertSame(1, $json['success'] ?? null, 'Quote to invoice failed: ' . json_encode($json));

        $invoiceId = (int) $json['invoice_id'];
        $this->assertSame((string) $invoiceId, (string) self::fetchValue('SELECT invoice_id FROM ip_quotes WHERE quote_id = ?', [$quoteId]));
        $this->assertSame('Implementation', self::fetchValue('SELECT item_name FROM ip_invoice_items WHERE invoice_id = ?', [$invoiceId]));
        $this->assertSame('360.00', self::invoiceAmounts($invoiceId)['invoice_total']);
    }
}
