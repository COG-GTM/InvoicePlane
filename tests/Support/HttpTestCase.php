<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use mysqli;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for HTTP-level regression tests.
 *
 * Drives a running InvoicePlane instance (IP_TEST_BASE_URL) the way the browser
 * does — same form fields, same AJAX payloads, same CSRF cookie/token pair — and
 * checks the results both in the rendered pages and in the database.
 */
abstract class HttpTestCase extends TestCase
{
    /** Markers CodeIgniter / PHP print when an error, warning or deprecation leaks into output. */
    private const PHP_ERROR_MARKERS = [
        'A PHP Error was encountered',
        'An uncaught Exception was encountered',
        'A Database Error Occurred',
        'Fatal error:',
        'Parse error:',
        'Warning:',
        'Deprecated:',
        'Notice:',
    ];

    protected Client $http;

    protected CookieJar $cookies;

    private static ?mysqli $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookies = new CookieJar();
        $this->http    = new Client([
            'base_uri'        => rtrim((string) getenv('IP_TEST_BASE_URL'), '/') . '/',
            'cookies'         => $this->cookies,
            'http_errors'     => false,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
            'timeout'         => 60,
        ]);
    }

    protected static function db(): mysqli
    {
        if (self::$db === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$db = new mysqli(
                (string) getenv('IP_TEST_DB_HOST'),
                (string) getenv('IP_TEST_DB_USER'),
                (string) getenv('IP_TEST_DB_PASSWORD'),
                (string) getenv('IP_TEST_DB_NAME'),
                (int) (getenv('IP_TEST_DB_PORT') ?: 3306)
            );
            self::$db->set_charset('utf8mb4');
        }

        return self::$db;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function fetchRow(string $sql, array $params = []): ?array
    {
        $stmt = self::db()->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    }

    protected static function fetchValue(string $sql, array $params = []): mixed
    {
        $row = self::fetchRow($sql, $params);

        return $row === null ? null : reset($row);
    }

    protected static function uniqueName(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    protected function csrfToken(): string
    {
        $cookie = $this->cookies->getCookieByName('ip_csrf_cookie');
        if ($cookie === null) {
            $this->get('sessions/login');
            $cookie = $this->cookies->getCookieByName('ip_csrf_cookie');
        }

        $this->assertNotNull($cookie, 'CSRF cookie was not issued');

        return (string) $cookie->getValue();
    }

    protected function get(string $uri, array $query = []): ResponseInterface
    {
        return $this->http->get(ltrim($uri, '/'), ['query' => $query]);
    }

    protected function post(string $uri, array $form = [], array $headers = []): ResponseInterface
    {
        $form['_ip_csrf'] = $this->csrfToken();

        return $this->http->post(ltrim($uri, '/'), ['form_params' => $form, 'headers' => $headers]);
    }

    /**
     * POST to one of the JSON endpoints used by the UI's jQuery code.
     *
     * @return array<string, mixed>
     */
    /**
     * Run a list page's search box filter (an XHR returning the table partial). The full list pages
     * are paginated and sorted by number, so a freshly created record is not guaranteed to be on page 1.
     */
    protected function filterList(string $entity, string $query): string
    {
        $response = $this->post("filter/ajax/filter_{$entity}", ['filter_query' => $query], ['X-Requested-With' => 'XMLHttpRequest']);
        $body     = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "filter_{$entity} failed: {$body}");
        $this->assertNoPhpErrors($body, "filter_{$entity}");

        return $body;
    }

    protected function postJson(string $uri, array $form = []): array
    {
        // Ajax controllers reject requests that are not flagged as XHR, like jQuery does.
        $response = $this->post($uri, $form, ['X-Requested-With' => 'XMLHttpRequest']);
        $body     = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), "POST {$uri} failed: {$body}");
        $this->assertNoPhpErrors($body, "POST {$uri}");

        $json = json_decode($body, true);
        $this->assertIsArray($json, "POST {$uri} did not return JSON: {$body}");

        return $json;
    }

    protected function finalPath(ResponseInterface $response, string $requested): string
    {
        $history = $response->getHeader('X-Guzzle-Redirect-History');
        $url     = $history === [] ? $requested : end($history);

        return '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    protected function assertNoPhpErrors(string $body, string $context = ''): void
    {
        foreach (self::PHP_ERROR_MARKERS as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                trim($context . ': PHP error output found in response'),
            );
        }
    }

    protected function assertPageOk(ResponseInterface $response, string $context): string
    {
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), "{$context} returned HTTP {$response->getStatusCode()}");
        $this->assertNoPhpErrors($body, $context);

        return $body;
    }

    protected function login(?string $email = null, ?string $password = null): ResponseInterface
    {
        $this->get('sessions/login');

        return $this->post('sessions/login', [
            'email'     => $email ?? (string) getenv('IP_TEST_ADMIN_EMAIL'),
            'password'  => $password ?? (string) getenv('IP_TEST_ADMIN_PASSWORD'),
            'btn_login' => 'true',
        ]);
    }

    protected function loginAsAdmin(): void
    {
        $response = $this->login();
        $this->assertSame('/dashboard', $this->finalPath($response, 'sessions/login'), 'Admin login failed');
        $this->assertPageOk($response, 'dashboard after login');
    }

    /**
     * Create a client through the real client form and return its id.
     */
    protected function createClient(array $overrides = []): int
    {
        $fields = array_merge([
            'is_update'        => '0',
            'client_active'    => '1',
            'client_name'      => self::uniqueName('Acme'),
            'client_surname'   => 'Regression',
            'client_language'  => 'system',
            'client_address_1' => '1 Test Street',
            'client_city'      => 'Springfield',
            'client_zip'       => '12345',
            'client_country'   => 'US',
            'client_email'     => 'billing+' . bin2hex(random_bytes(3)) . '@example.com',
            'btn_submit'       => '1',
        ], $overrides);

        $response = $this->post('clients/form', $fields);
        $this->assertPageOk($response, 'create client');

        $clientId = self::fetchValue(
            'SELECT client_id FROM ip_clients WHERE client_name = ? ORDER BY client_id DESC LIMIT 1',
            [$fields['client_name']]
        );
        $this->assertNotNull($clientId, 'Client was not stored');

        return (int) $clientId;
    }

    protected function createTaxRate(string $name, string $percent): int
    {
        $response = $this->post('tax_rates/form', [
            'tax_rate_name'    => $name,
            'tax_rate_percent' => $percent,
            'btn_submit'       => '1',
        ]);
        $this->assertPageOk($response, 'create tax rate');

        $id = self::fetchValue('SELECT tax_rate_id FROM ip_tax_rates WHERE tax_rate_name = ? ORDER BY tax_rate_id DESC LIMIT 1', [$name]);
        $this->assertNotNull($id, 'Tax rate was not stored');

        return (int) $id;
    }

    protected function createInvoice(int $clientId): int
    {
        $json = $this->postJson('invoices/ajax/create', [
            'client_id'            => $clientId,
            'invoice_date_created' => date('m/d/Y'),
            'invoice_group_id'     => self::fetchValue("SELECT setting_value FROM ip_settings WHERE setting_key = 'default_invoice_group'"),
            'invoice_time_created' => date('H:i:s'),
            'invoice_password'     => '',
            'user_id'              => self::fetchValue('SELECT MIN(user_id) FROM ip_users'),
            'payment_method'       => '',
        ]);

        $this->assertSame(1, $json['success'] ?? null, 'Invoice create failed: ' . json_encode($json));

        return (int) $json['invoice_id'];
    }

    /**
     * Shape of a line item exactly as invoices/views/view.php serialises it.
     */
    protected static function item(string $name, string $quantity, string $price, int|string $taxRateId = '0', string $itemId = ''): array
    {
        return [
            'item_id'              => $itemId,
            'item_name'            => $name,
            'item_description'     => 'Regression item ' . $name,
            'item_quantity'        => $quantity,
            'item_price'           => $price,
            'item_discount_amount' => '',
            'item_tax_rate_id'     => (string) $taxRateId,
            'item_product_id'      => '',
            'item_product_unit_id' => '',
            'item_task_id'         => '',
            'item_is_recurring'    => false,
        ];
    }

    /**
     * Save invoice header + line items the same way the "Save" button does.
     *
     * @param list<array<string, mixed>> $items
     */
    protected function saveInvoice(int $invoiceId, array $items, int $statusId = 1, array $overrides = []): array
    {
        $order = 1;
        foreach ($items as &$item) {
            $item['invoice_id'] = (string) $invoiceId;
            $item['item_order'] = $order++;
        }
        unset($item);

        return $this->postJson('invoices/ajax/save', array_merge([
            'legacy_calculation'       => '1',
            'invoice_id'               => $invoiceId,
            'invoice_number'           => (string) self::fetchValue('SELECT invoice_number FROM ip_invoices WHERE invoice_id = ?', [$invoiceId]),
            'invoice_date_created'     => date('m/d/Y'),
            'invoice_date_due'         => date('m/d/Y', strtotime('+30 days')),
            'invoice_status_id'        => $statusId,
            'invoice_password'         => '',
            'items'                    => json_encode($items),
            'invoice_discount_amount'  => '',
            'invoice_discount_percent' => '',
            'invoice_terms'            => 'Net 30',
            'payment_method'           => '',
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    protected static function invoiceAmounts(int $invoiceId): array
    {
        $row = self::fetchRow('SELECT * FROM ip_invoice_amounts WHERE invoice_id = ?', [$invoiceId]);
        self::assertNotNull($row, 'No ip_invoice_amounts row');

        return $row;
    }
}
