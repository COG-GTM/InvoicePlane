<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;

final class AuthenticationTest extends HttpTestCase
{
    #[Test]
    public function it_renders_the_login_form(): void
    {
        $body = $this->assertPageOk($this->get('sessions/login'), 'login page');

        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="_ip_csrf"', $body);
    }

    #[Test]
    public function it_redirects_anonymous_users_to_the_login_page(): void
    {
        foreach (['dashboard', 'clients', 'invoices', 'quotes', 'payments'] as $page) {
            $response = $this->get($page);

            $this->assertSame('/sessions/login', $this->finalPath($response, $page), "{$page} is reachable without login");
        }
    }

    #[Test]
    public function it_rejects_a_wrong_password(): void
    {
        $response = $this->login(password: 'definitely-not-the-password');

        $this->assertSame('/sessions/login', $this->finalPath($response, 'sessions/login'));
        $this->assertNoPhpErrors((string) $response->getBody(), 'failed login');
        $this->assertSame('/sessions/login', $this->finalPath($this->get('dashboard'), 'dashboard'));
    }

    #[Test]
    public function it_logs_in_and_out_as_the_admin_user(): void
    {
        $this->loginAsAdmin();

        $dashboard = $this->assertPageOk($this->get('dashboard'), 'dashboard');
        $this->assertStringContainsString('sessions/logout', $dashboard);

        $this->post('sessions/logout');

        $this->assertSame('/sessions/login', $this->finalPath($this->get('dashboard'), 'dashboard'));
    }
}
