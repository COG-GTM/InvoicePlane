<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;

final class ClientsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_creates_a_client_and_lists_it(): void
    {
        $name     = self::uniqueName('Globex');
        $clientId = $this->createClient(['client_name' => $name, 'client_city' => 'Shelbyville']);

        $row = self::fetchRow('SELECT * FROM ip_clients WHERE client_id = ?', [$clientId]);
        $this->assertSame('Shelbyville', $row['client_city']);
        $this->assertSame('1', (string) $row['client_active']);

        $list = $this->assertPageOk($this->get('clients/status/active'), 'client list');
        $this->assertStringContainsString('clients/view/', $list);

        $filteredBody = $this->filterList('clients', $name);
        $this->assertStringContainsString(htmlspecialchars($name), $filteredBody);
        $this->assertStringContainsString("clients/view/{$clientId}", $filteredBody);

        $view = $this->assertPageOk($this->get("clients/view/{$clientId}"), 'client view');
        $this->assertStringContainsString(htmlspecialchars($name), $view);
        $this->assertStringContainsString('Shelbyville', $view);
    }

    #[Test]
    public function it_edits_an_existing_client(): void
    {
        $clientId = $this->createClient();

        $form = $this->assertPageOk($this->get("clients/form/{$clientId}"), 'client edit form');
        $this->assertStringContainsString('name="client_name"', $form);

        $newName = self::uniqueName('Initech');
        $this->assertPageOk($this->post("clients/form/{$clientId}", [
            'is_update'       => '1',
            'client_active'   => '1',
            'client_name'     => $newName,
            'client_surname'  => 'Edited',
            'client_language' => 'system',
            'client_city'     => 'Capital City',
            'client_email'    => 'edited@example.com',
            'btn_submit'      => '1',
        ]), 'update client');

        $row = self::fetchRow('SELECT * FROM ip_clients WHERE client_id = ?', [$clientId]);
        $this->assertSame($newName, $row['client_name']);
        $this->assertSame('Capital City', $row['client_city']);
        $this->assertSame('edited@example.com', $row['client_email']);
    }

    #[Test]
    public function it_rejects_a_client_without_a_name(): void
    {
        $before = (int) self::fetchValue('SELECT COUNT(*) FROM ip_clients');

        $this->assertPageOk($this->post('clients/form', [
            'is_update'    => '0',
            'client_name'  => '',
            'client_email' => 'nobody@example.com',
            'btn_submit'   => '1',
        ]), 'invalid client');

        $this->assertSame($before, (int) self::fetchValue('SELECT COUNT(*) FROM ip_clients'));
    }
}
