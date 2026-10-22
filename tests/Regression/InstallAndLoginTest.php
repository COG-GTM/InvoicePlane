<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class InstallAndLoginTest extends RegressionTestCase
{
    #[Test]
    public function it_redirects_anonymous_visitors_to_the_login_page(): void
    {
        $response = $this->request('GET', 'dashboard');

        $this->assertContains($response->getStatusCode(), [302, 303, 307]);
        $this->assertStringEndsWith('/sessions/login', $this->locationPath($response));
    }

    #[Test]
    public function it_renders_the_login_form(): void
    {
        $page = $this->assertPageOk($this->get('sessions/login'), 'login page');

        $this->assertSee('name="email"', $page);
        $this->assertSee('name="password"', $page);
        $this->assertSee('name="' . self::CSRF_FIELD . '"', $page);
    }

    #[Test]
    public function it_keeps_the_setup_wizard_locked_after_install(): void
    {
        $response = $this->get('setup');
        $body     = (string) $response->getBody();

        $this->assertNoPhpErrors($body, 'setup');
        $this->assertStringNotContainsString('name="ip_lang"', $body, 'Setup wizard is reachable on an installed app.');
        $this->assertStringNotContainsString('setup/prerequisites', $body);
    }

    #[Test]
    public function it_rejects_a_wrong_password(): void
    {
        $response = $this->login(static::adminEmail(), 'definitely-not-the-password');

        $this->assertContains($response->getStatusCode(), [302, 303, 307]);
        $this->assertStringEndsWith('/sessions/login', $this->locationPath($response));

        $after = $this->assertPageOk($this->follow($response), 'login after failure');
        $this->assertSee('alert-danger', $after);
        $this->assertStringEndsWith('/sessions/login', $this->locationPath($this->request('GET', 'dashboard')));
    }

    #[Test]
    public function it_logs_in_the_admin_and_renders_the_dashboard(): void
    {
        $this->loginAsAdmin();

        $page = $this->assertPageOk($this->get('dashboard'), 'dashboard');

        $this->assertSee('id="panel-quick-actions"', $page);
        $this->assertSee('/clients/form', $page);
        $this->assertSee('/invoices', $page);
    }

    #[Test]
    public function it_logs_out_with_a_csrf_protected_post(): void
    {
        $this->loginAsAdmin();

        $response = $this->postForm('sessions/logout', []);
        $this->assertContains($response->getStatusCode(), [302, 303, 307]);

        $this->assertStringEndsWith('/sessions/login', $this->locationPath($this->request('GET', 'dashboard')));
    }
}
