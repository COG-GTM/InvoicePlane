<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class PaymentsTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    private function addPayment(int $invoiceId, string $amount): array
    {
        $modal = $this->assertPageOk(
            $this->postAjax('payments/ajax/modal_add_payment', [
                'invoice_id'             => $invoiceId,
                'invoice_balance'        => $amount,
                'invoice_payment_method' => '',
                'payment_cf_exist'       => '',
            ]),
            'add-payment modal'
        );
        preg_match('#<select[^>]+name="payment_method_id"[^>]*>.*?<option[^>]+value="(\d+)"#s', $modal, $method);

        return $this->postAjaxJson('payments/ajax/add', [
            'invoice_id'        => $invoiceId,
            'payment_amount'    => $amount,
            'payment_method_id' => $method[1] ?? '',
            'payment_date'      => $this->today(),
            'payment_note'      => 'Regression payment',
        ]);
    }

    #[Test]
    public function it_records_partial_and_final_payments_and_marks_the_invoice_paid(): void
    {
        $invoiceId = $this->createInvoice($this->createClient($this->uniqueName('Payer Co')));
        $this->saveInvoiceItems($invoiceId, [
            ['name' => 'Implementation', 'quantity' => '2', 'price' => '150.00'],
            ['name' => 'Support plan', 'quantity' => '1', 'price' => '49.99'],
        ]);

        $first = $this->addPayment($invoiceId, '100.00');
        $this->assertSame(1, $first['success'] ?? null, 'Partial payment failed: ' . json_encode($first));
        $this->assertSame('249.99', number_format((float) $this->invoiceBalance($invoiceId), 2, '.', ''));

        $over = $this->addPayment($invoiceId, '1000.00');
        $this->assertSame(0, $over['success'] ?? null, 'Overpayment was accepted.');

        $final = $this->addPayment($invoiceId, '249.99');
        $this->assertSame(1, $final['success'] ?? null, 'Final payment failed: ' . json_encode($final));
        $this->assertSame(0.0, (float) $this->invoiceBalance($invoiceId));

        $paid = $this->assertPageOk($this->get('invoices/status/paid'), 'paid invoices');
        $this->assertSee("invoices/view/{$invoiceId}", $paid);

        $payments = $this->assertPageOk($this->get('payments'), 'payment list');
        $this->assertSee('249.99', $payments);
    }
}
