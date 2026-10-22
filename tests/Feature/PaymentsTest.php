<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;

final class PaymentsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    private function sentInvoice(string $price): int
    {
        $invoiceId = $this->createInvoice($this->createClient());
        $json      = $this->saveInvoice($invoiceId, [self::item('Service', '1', $price)], 2);
        $this->assertSame(1, $json['success'] ?? null, json_encode($json));

        return $invoiceId;
    }

    private function pay(int $invoiceId, string $amount): void
    {
        $this->assertPageOk($this->post('payments/form', [
            'payment_id'        => '',
            'invoice_id'        => $invoiceId,
            'payment_date'      => date('m/d/Y'),
            'payment_amount'    => $amount,
            'payment_method_id' => '1',
            'payment_note'      => 'Regression payment',
            'btn_submit'        => '1',
        ]), 'record payment');
    }

    #[Test]
    public function it_records_a_partial_payment_and_updates_the_balance(): void
    {
        $invoiceId = $this->sentInvoice('500.00');

        $form = $this->assertPageOk($this->get('payments/form'), 'payment form');
        $this->assertStringContainsString('value="' . $invoiceId . '"', $form, 'Sent invoice not offered on payment form');

        $this->pay($invoiceId, '200.00');

        $amounts = self::invoiceAmounts($invoiceId);
        $this->assertSame('200.00', $amounts['invoice_paid']);
        $this->assertSame('300.00', $amounts['invoice_balance']);
        $this->assertSame('2', (string) self::fetchValue('SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]));

        $list = $this->assertPageOk($this->get('payments'), 'payment list');
        $this->assertStringContainsString('200.00', $list);
    }

    #[Test]
    public function it_marks_the_invoice_paid_when_fully_settled(): void
    {
        $invoiceId = $this->sentInvoice('80.00');

        $this->pay($invoiceId, '80.00');

        $amounts = self::invoiceAmounts($invoiceId);
        $this->assertSame('80.00', $amounts['invoice_paid']);
        $this->assertSame('0.00', $amounts['invoice_balance']);
        $this->assertSame('4', (string) self::fetchValue('SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]));
    }

    #[Test]
    public function it_rejects_an_overpayment(): void
    {
        $invoiceId = $this->sentInvoice('50.00');

        $this->pay($invoiceId, '75.00');

        $this->assertSame('0', (string) self::fetchValue('SELECT COUNT(*) FROM ip_payments WHERE invoice_id = ?', [$invoiceId]));
        $this->assertSame('50.00', self::invoiceAmounts($invoiceId)['invoice_balance']);
    }
}
