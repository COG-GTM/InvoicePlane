<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class ClientsTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_renders_the_client_list_and_form(): void
    {
        $this->assertPageOk($this->get('clients'), 'client list');
        $form = $this->assertPageOk($this->get('clients/form'), 'client form');

        $this->assertSee('name="client_name"', $form);
    }

    #[Test]
    public function it_creates_a_client_and_shows_it_on_the_detail_page_and_list(): void
    {
        $name     = $this->uniqueName('Acme');
        $clientId = $this->createClient($name);

        $view = $this->assertPageOk($this->get("clients/view/{$clientId}"), 'client view');
        $this->assertSee(htmlspecialchars($name), $view);
        $this->assertSee('1 Regression Way', $view);

        $list = $this->assertPageOk($this->get('clients/status/active'), 'active clients');
        $this->assertSee(htmlspecialchars($name), $list);
    }

    #[Test]
    public function it_rejects_a_duplicate_client(): void
    {
        $name = $this->uniqueName('Duplicate');
        $this->createClient($name);

        $response = $this->postForm('clients/form', [
            'is_update'      => '0',
            'client_active'  => '1',
            'client_name'    => $name,
            'client_surname' => 'Regression',
            'btn_submit'     => '1',
        ]);

        $this->assertStringEndsWith('/clients/form', $this->locationPath($response));
        $page = $this->assertPageOk($this->follow($response), 'duplicate client');
        $this->assertSee('alert-danger', $page);
    }

    #[Test]
    public function it_requires_a_client_name(): void
    {
        $response = $this->postForm('clients/form', ['is_update' => '0', 'client_name' => '', 'btn_submit' => '1']);
        $page     = $this->assertPageOk($response, 'client without name');

        $this->assertSee('alert-danger', $page);
    }
}
