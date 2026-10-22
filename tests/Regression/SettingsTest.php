<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class SettingsTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        $this->loginAsAdmin();
    }

    #[Test]
    public function it_renders_every_settings_tab(): void
    {
        $page = $this->assertPageOk($this->get('settings'), 'settings');

        foreach (['general', 'invoices', 'quotes', 'taxes', 'email', 'online-payment', 'projects-tasks', 'updates'] as $tab) {
            $this->assertSee('id="settings-' . $tab . '"', $page, "Settings tab {$tab} missing.");
        }
    }

    #[Test]
    public function it_saves_and_persists_a_setting(): void
    {
        $terms = 'Regression terms ' . bin2hex(random_bytes(4));

        $response = $this->postMultipart(
            'settings',
            ['settings[default_invoice_terms]' => $terms],
            ['invoice_logo', 'login_logo']
        );
        $this->assertContains($response->getStatusCode(), [302, 303, 307]);
        $this->assertStringEndsWith('/settings', $this->locationPath($response));

        $page = $this->assertPageOk($this->follow($response), 'settings after save');
        $this->assertSee('alert-success', $page);
        $this->assertSee($terms, $page);
    }

    #[Test]
    public function it_rejects_an_unknown_theme(): void
    {
        $response = $this->postMultipart(
            'settings',
            ['settings[system_theme]' => '"><script>alert(1)</script>'],
            ['invoice_logo', 'login_logo']
        );

        $page = $this->assertPageOk($this->follow($response), 'settings after invalid theme');
        $this->assertSee('alert-danger', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
    }

    #[Test]
    public function it_renders_the_core_admin_pages(): void
    {
        foreach (['tax_rates', 'payment_methods', 'invoice_groups', 'users', 'custom_fields', 'email_templates', 'reports/invoice_aging'] as $path) {
            $this->assertPageOk($this->get($path), $path);
        }
    }
}
