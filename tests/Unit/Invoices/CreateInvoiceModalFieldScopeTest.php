<?php

namespace Tests\Unit\Invoices;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The create-invoice and create-credit modals open on top of an invoice page whose form
 * uses the same input ids (invoice_date_created, invoice_password, ...). A document-wide
 * $('#id') lookup returns the page's field, so the modal posted the open invoice's date.
 */
class CreateInvoiceModalFieldScopeTest extends TestCase
{
    public static function modals(): array
    {
        return [
            'create invoice' => ['modal_create_invoice.php', 'create-invoice', 'invoices/ajax/create'],
            'create credit'  => ['modal_create_credit.php', 'modal-create-credit-invoice', 'invoices/ajax/create_credit'],
        ];
    }

    #[Test]
    #[DataProvider('modals')]
    public function it_reads_every_posted_field_from_inside_the_modal(string $view, string $modal_id, string $endpoint): void
    {
        // Arrange
        $source = $this->view_source($view);

        // Act
        $payload = $this->post_payload($source, $endpoint);
        preg_match_all("/\\$\\('#[\\w-]+'\\)/", $payload, $global_lookups);

        // Assert
        $this->assertStringContainsString("var \$modal = \$('#" . $modal_id . "');", $source);
        $this->assertStringContainsString('id="' . $modal_id . '"', $source);
        $this->assertStringContainsString("invoice_date_created: \$modal.find('#invoice_date_created').val()", $payload);
        $this->assertSame([], $global_lookups[0], 'Posted fields must be read with $modal.find(), not a page-wide $(\'#id\').');
    }

    #[Test]
    #[DataProvider('modals')]
    public function it_highlights_validation_errors_inside_the_modal(string $view, string $modal_id, string $endpoint): void
    {
        // Arrange
        $source = $this->view_source($view);

        // Act / Assert
        $this->assertStringContainsString("\$modal.find('#' + key)", $source);
        $this->assertStringNotContainsString("\$('#' + key)", $source);
    }

    private function view_source(string $view): string
    {
        $path = dirname(__DIR__, 3) . '/application/modules/invoices/views/' . $view;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function post_payload(string $source, string $endpoint): string
    {
        $pattern = '/\$\.post\("<\?php echo site_url\(\'' . preg_quote($endpoint, '/') . '\'\); \?>",\s*\{(.*?)\n\s*\},/s';
        $this->assertSame(1, preg_match($pattern, $source, $matches), 'Could not find the $.post payload for ' . $endpoint);

        return $matches[1];
    }
}
