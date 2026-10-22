<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Smalot\PdfParser\Parser;
use Tests\Support\HttpTestCase;

final class PdfTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_generates_the_invoice_pdf(): void
    {
        $clientName = self::uniqueName('Pdfclient');
        $invoiceId  = $this->createInvoice($this->createClient(['client_name' => $clientName]));
        $this->saveInvoice($invoiceId, [
            self::item('Annual support', '1', '1234.50'),
            self::item('Training', '2', '100.00'),
        ]);
        $invoiceNumber = (string) self::fetchValue('SELECT invoice_number FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]);

        $text = $this->pdfText(
            $this->get("invoices/generate_pdf/{$invoiceId}", ['_ip_csrf' => $this->csrfToken()]),
            'invoice PDF'
        );

        $this->assertStringContainsString($clientName, $text);
        $this->assertStringContainsString('Annual support', $text);
        $this->assertStringContainsString('Training', $text);
        $this->assertStringContainsString('1,434.50', $text);
        if ($invoiceNumber !== '') {
            $this->assertStringContainsString($invoiceNumber, $text);
        }
    }

    #[Test]
    public function it_generates_the_quote_pdf(): void
    {
        $clientName = self::uniqueName('Quoteclient');
        $clientId   = $this->createClient(['client_name' => $clientName]);

        $json = $this->postJson('quotes/ajax/create', [
            'client_id'          => $clientId,
            'quote_date_created' => date('m/d/Y'),
            'quote_password'     => '',
            'user_id'            => self::fetchValue('SELECT MIN(user_id) FROM ip_users'),
            'invoice_group_id'   => self::fetchValue("SELECT setting_value FROM ip_settings WHERE setting_key = 'default_quote_group'"),
        ]);
        $this->assertSame(1, $json['success'] ?? null, json_encode($json));
        $quoteId = (int) $json['quote_id'];

        $item = self::item('Roadmap workshop', '1', '999.00');
        unset($item['item_task_id'], $item['item_is_recurring']);
        $item['quote_id']   = (string) $quoteId;
        $item['item_order'] = 1;
        $this->postJson('quotes/ajax/save', [
            'quote_id'           => $quoteId,
            'quote_number'       => (string) self::fetchValue('SELECT quote_number FROM ip_quotes WHERE quote_id = ?', [$quoteId]),
            'quote_date_created' => date('m/d/Y'),
            'quote_date_expires' => date('m/d/Y', strtotime('+15 days')),
            'quote_status_id'    => 1,
            'items'              => json_encode([$item]),
        ]);

        $text = $this->pdfText(
            $this->get("quotes/generate_pdf/{$quoteId}", ['_ip_csrf' => $this->csrfToken()]),
            'quote PDF'
        );

        $this->assertStringContainsString($clientName, $text);
        $this->assertStringContainsString('Roadmap workshop', $text);
        $this->assertStringContainsString('999.00', $text);
    }

    #[Test]
    public function it_embeds_the_sepa_payment_qr_code_in_the_invoice_pdf(): void
    {
        $qrSettings = [
            'qr_code'           => '1',
            'qr_code_recipient' => 'Regression Payee GmbH',
            'qr_code_iban'      => 'DE89370400440532013000',
            'qr_code_bic'       => 'COBADEFFXXX',
            'currency_code'     => 'EUR',
        ];
        $previous = [];
        foreach ($qrSettings as $key => $value) {
            $previous[$key] = self::setSetting($key, $value);
        }

        try {
            $invoiceId = $this->createInvoice($this->createClient());
            $this->saveInvoice($invoiceId, [self::item('QR billed item', '1', '99.00')]);

            $response = $this->get("invoices/generate_pdf/{$invoiceId}", ['_ip_csrf' => $this->csrfToken()]);
            $text     = $this->pdfText($response, 'invoice PDF with QR code');

            $this->assertStringContainsString('DE89370400440532013000', $text);
            $this->assertStringContainsString('COBADEFFXXX', $text);

            $images = array_filter(
                (new Parser())->parseContent((string) $response->getBody())->getObjectsByType('XObject'),
                static fn ($object): bool => $object->getHeader()->get('Subtype')->getContent() === 'Image'
            );
            $this->assertNotEmpty($images, 'invoice PDF does not embed the payment QR code image');
        } finally {
            foreach ($previous as $key => $value) {
                self::setSetting($key, $value);
            }
        }
    }

    #[Test]
    public function it_generates_the_sales_by_client_report_pdf(): void
    {
        $clientName = self::uniqueName('Reportclient');
        $invoiceId  = $this->createInvoice($this->createClient(['client_name' => $clientName]));
        $this->saveInvoice($invoiceId, [self::item('Reported item', '1', '321.00')], 2);

        $this->assertPageOk($this->get('reports/sales_by_client'), 'report form');

        $text = $this->pdfText($this->post('reports/sales_by_client', [
            'from_date'  => date('m/d/Y', strtotime('-1 day')),
            'to_date'    => date('m/d/Y', strtotime('+1 day')),
            'btn_submit' => '1',
        ]), 'sales by client report');

        $this->assertStringContainsString($clientName, $text);
    }

    private function pdfText(ResponseInterface $response, string $context): string
    {
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "{$context}: HTTP {$response->getStatusCode()}");
        $this->assertStringStartsWith('application/pdf', $response->getHeaderLine('Content-Type'), "{$context}: not a PDF: " . substr($body, 0, 500));
        $this->assertStringStartsWith('%PDF-', $body, "{$context}: body is not a PDF");

        $text = (new Parser())->parseContent($body)->getText();

        // mPDF's fonts substitute typographic ligatures (e.g. "ff" -> U+FB00); undo that before matching.
        return strtr($text, ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl']);
    }
}
