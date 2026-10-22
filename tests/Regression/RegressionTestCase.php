<?php

declare(strict_types=1);

namespace Tests\Regression;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level regression tests against a running InvoicePlane instance.
 *
 * Start the stack with:
 *   docker compose -f compose.yml -f tests/compose.regression.yml up -d --build
 * then run vendor/bin/phpunit. IP_TEST_BASE_URL / IP_TEST_ADMIN_EMAIL /
 * IP_TEST_ADMIN_PASSWORD override the defaults in phpunit.xml.
 */
abstract class RegressionTestCase extends TestCase
{
    protected const CSRF_FIELD = '_ip_csrf';

    protected const CSRF_COOKIE = 'ip_csrf_cookie';

    private const PHP_ERROR_MARKERS = [
        'A PHP Error was encountered',
        'An uncaught Exception was encountered',
        'A Database Error Occurred',
        'Fatal error:',
        'Parse error:',
        'Deprecated:',
        'Warning:',
        'Notice:',
    ];

    protected static ?Client $http = null;

    protected static ?CookieJar $jar = null;

    protected static function baseUrl(): string
    {
        return rtrim((string) (getenv('IP_TEST_BASE_URL') ?: 'http://localhost:4895'), '/');
    }

    protected static function adminEmail(): string
    {
        return (string) getenv('IP_TEST_ADMIN_EMAIL');
    }

    protected static function adminPassword(): string
    {
        return (string) getenv('IP_TEST_ADMIN_PASSWORD');
    }

    public static function setUpBeforeClass(): void
    {
        static::$jar  = new CookieJar();
        static::$http = new Client([
            'base_uri'        => static::baseUrl(),
            'cookies'         => static::$jar,
            'allow_redirects' => false,
            'http_errors'     => false,
            'timeout'         => 60,
        ]);
    }

    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        return static::$http->request($method, '/' . ltrim($path, '/'), $options);
    }

    /**
     * GET a page, following same-site redirects. Returns [response, final path].
     */
    protected function get(string $path, array $query = []): ResponseInterface
    {
        return $this->follow($this->request('GET', $path, ['query' => $query]));
    }

    protected function follow(ResponseInterface $response, int $maxHops = 5): ResponseInterface
    {
        while ($maxHops-- > 0 && in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
            $response = $this->request('GET', $this->locationPath($response));
        }

        return $response;
    }

    protected function locationPath(ResponseInterface $response): string
    {
        $location = $response->getHeaderLine('Location');
        $this->assertNotSame('', $location, 'Expected a redirect Location header.');

        $path = (string) parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);

        return $path . ($query ? '?' . $query : '');
    }

    protected function csrfToken(): string
    {
        $cookie = static::$jar->getCookieByName(self::CSRF_COOKIE);
        if ($cookie === null) {
            $this->request('GET', 'sessions/login');
            $cookie = static::$jar->getCookieByName(self::CSRF_COOKIE);
        }

        $this->assertNotNull($cookie, 'CSRF cookie was not issued.');

        return (string) $cookie->getValue();
    }

    /**
     * Submit a regular (non-AJAX) form POST, without following the redirect.
     */
    protected function postForm(string $path, array $fields): ResponseInterface
    {
        $fields[self::CSRF_FIELD] = $this->csrfToken();

        return $this->request('POST', $path, ['form_params' => $fields]);
    }

    /**
     * Submit a multipart form the way a browser does, including empty file inputs.
     */
    protected function postMultipart(string $path, array $fields, array $emptyFileInputs = []): ResponseInterface
    {
        $fields[self::CSRF_FIELD] = $this->csrfToken();
        $parts                    = [];

        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => (string) $value];
        }

        foreach ($emptyFileInputs as $name) {
            $parts[] = [
                'name'     => $name,
                'contents' => '',
                'headers'  => [
                    'Content-Disposition' => sprintf('form-data; name="%s"; filename=""', $name),
                    'Content-Type'        => 'application/octet-stream',
                ],
            ];
        }

        return $this->request('POST', $path, ['multipart' => $parts]);
    }

    /**
     * POST to an Ajax controller the way the jQuery front end does and decode the JSON reply.
     */
    protected function postAjaxJson(string $path, array $fields): array
    {
        $response = $this->postAjax($path, $fields);
        $body     = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "POST {$path} failed: {$body}");
        $this->assertNoPhpErrors($body, "POST {$path}");

        $json = json_decode($body, true);
        $this->assertIsArray($json, "POST {$path} did not return JSON: {$body}");

        return $json;
    }

    protected function postAjax(string $path, array $fields): ResponseInterface
    {
        $fields[self::CSRF_FIELD] = $this->csrfToken();

        return $this->request('POST', $path, [
            'form_params' => $fields,
            'headers'     => ['X-Requested-With' => 'XMLHttpRequest'],
        ]);
    }

    protected function login(?string $email = null, ?string $password = null): ResponseInterface
    {
        $this->get('sessions/login');

        return $this->postForm('sessions/login', [
            'email'     => $email ?? static::adminEmail(),
            'password'  => $password ?? static::adminPassword(),
            'btn_login' => 'true',
        ]);
    }

    protected function loginAsAdmin(): void
    {
        $response = $this->login();
        $this->assertContains($response->getStatusCode(), [302, 303, 307]);
        $this->assertStringEndsWith('/dashboard', $this->locationPath($response), 'Admin login did not land on the dashboard.');
    }

    protected function assertPageOk(ResponseInterface $response, string $context): string
    {
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "{$context}: unexpected HTTP status.");
        $this->assertNoPhpErrors($body, $context);

        return $body;
    }

    protected function assertNoPhpErrors(string $body, string $context): void
    {
        $text = strip_tags($body);

        foreach (self::PHP_ERROR_MARKERS as $marker) {
            $position = strpos($text, $marker);
            $excerpt  = $position === false ? '' : (string) preg_replace('/\s+/', ' ', substr($text, $position, 600));

            $this->assertFalse($position !== false, "{$context}: response contains a PHP error: {$excerpt}");
        }
    }

    /**
     * assertStringContainsString() without dumping a 60 KB HTML page on failure.
     */
    protected function assertSee(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            trim($message . " Expected response to contain '{$needle}' (response length " . strlen($haystack) . ').')
        );
    }

    protected function assertPdf(ResponseInterface $response, string $context): void
    {
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "{$context}: unexpected HTTP status.");
        $this->assertStringStartsWith('application/pdf', $response->getHeaderLine('Content-Type'), "{$context}: not served as a PDF.");
        $this->assertStringStartsWith('%PDF-', $body, "{$context}: body is not a PDF (output before the PDF header?).");
        $this->assertGreaterThan(5000, strlen($body), "{$context}: PDF is suspiciously small.");
    }

    protected function uniqueName(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    protected function today(): string
    {
        // Matches the default date_format setting (m/d/Y) used by date_to_mysql().
        return date('m/d/Y');
    }

    protected function createClient(string $name, string $surname = 'Regression'): int
    {
        $this->get('clients/form');

        $response = $this->postForm('clients/form', [
            'is_update'        => '0',
            'client_active'    => '1',
            'client_name'      => $name,
            'client_surname'   => $surname,
            'client_language'  => 'system',
            'client_address_1' => '1 Regression Way',
            'client_city'      => 'Toronto',
            'client_country'   => 'CA',
            'client_email'     => 'billing@example.org',
            'client_title'     => 'mr',
            'btn_submit'       => '1',
        ]);

        $this->assertContains($response->getStatusCode(), [302, 303, 307], 'Client form did not redirect after save.');
        $this->assertMatchesRegularExpression('#/clients/view/(\d+)$#', $this->locationPath($response));
        preg_match('#/clients/view/(\d+)$#', $this->locationPath($response), $m);

        return (int) $m[1];
    }

    protected function defaultGroupId(string $page, string $selectName = 'invoice_group_id'): string
    {
        $this->assertMatchesRegularExpression(
            '#<select[^>]+name="' . $selectName . '"[^>]*>.*?<option[^>]+value="(\d+)"#s',
            $page,
            "No {$selectName} options found."
        );
        preg_match('#<select[^>]+name="' . $selectName . '"[^>]*>.*?<option[^>]+value="(\d+)"[^>]*selected#s', $page, $selected);
        if ($selected !== []) {
            return $selected[1];
        }

        preg_match('#<select[^>]+name="' . $selectName . '"[^>]*>.*?<option[^>]+value="(\d+)"#s', $page, $first);

        return $first[1];
    }

    protected function adminUserId(): string
    {
        // The seeded admin is the only user in a fresh install.
        return '1';
    }

    /**
     * Line items in the JSON shape the invoice/quote editors post.
     *
     * @param array<int, array{name: string, quantity: string, price: string}> $lines
     */
    protected function itemsJson(array $lines, bool $invoice): string
    {
        $items = [];
        foreach ($lines as $order => $line) {
            $item = [
                'item_id'              => '',
                'item_product_id'      => $line['product_id'] ?? '',
                'item_name'            => $line['name'],
                'item_description'     => 'Regression line ' . ($order + 1),
                'item_quantity'        => $line['quantity'],
                'item_price'           => $line['price'],
                'item_discount_amount' => '',
                'item_tax_rate_id'     => '0',
                'item_product_unit_id' => '',
                'item_order'           => $order + 1,
            ];

            if ($invoice) {
                $item['item_task_id']      = '';
                $item['item_is_recurring'] = '';
                $item['item_date']         = '';
            }

            $items[] = $item;
        }

        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    protected function createInvoice(int $clientId): int
    {
        $modal = $this->postAjax('invoices/ajax/modal_create_invoice', ['client_id' => $clientId]);
        $page  = $this->assertPageOk($modal, 'create-invoice modal');

        $json = $this->postAjaxJson('invoices/ajax/create', [
            'client_id'            => $clientId,
            'invoice_date_created' => $this->today(),
            'invoice_group_id'     => $this->defaultGroupId($page),
            'invoice_time_created' => date('H:i:s'),
            'invoice_password'     => '',
            'user_id'              => $this->adminUserId(),
            'payment_method'       => '0',
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Invoice create failed: ' . json_encode($json));

        return (int) $json['invoice_id'];
    }

    protected function saveInvoiceItems(int $invoiceId, array $lines): void
    {
        $json = $this->postAjaxJson('invoices/ajax/save', [
            'legacy_calculation'       => '0',
            'invoice_id'               => $invoiceId,
            'invoice_number'           => '',
            'invoice_date_created'     => $this->today(),
            'invoice_date_due'         => date('m/d/Y', strtotime('+30 days')),
            'invoice_status_id'        => '1',
            'invoice_password'         => '',
            'items'                    => $this->itemsJson($lines, true),
            'invoice_discount_amount'  => '',
            'invoice_discount_percent' => '',
            'invoice_terms'            => 'Net 30',
            'payment_method'           => '0',
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Invoice save failed: ' . json_encode($json));
    }

    protected function invoiceBalance(int $invoiceId): string
    {
        $page = $this->assertPageOk($this->get("invoices/view/{$invoiceId}"), "invoice {$invoiceId} view");
        preg_match('#data-invoice-balance="([^"]*)"#', $page, $m);

        return $m[1] ?? '0';
    }

    protected function invoiceTotalCell(string $page): string
    {
        $this->assertMatchesRegularExpression('#<td class="amount"><b>([^<]+)</b></td>#', $page, 'Invoice total cell not found.');
        preg_match('#<td class="amount"><b>([^<]+)</b></td>#', $page, $m);

        return html_entity_decode(trim($m[1]));
    }
}
