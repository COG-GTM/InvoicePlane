<?php

declare(strict_types=1);

namespace Tests\Regression;

use PHPUnit\Framework\Attributes\Test;

final class RuntimeTest extends RegressionTestCase
{
    #[Test]
    public function it_reports_the_php_runtime_serving_the_app(): void
    {
        $response = $this->request('GET', 'sessions/login');
        $this->assertPageOk($response, 'login page');

        $poweredBy = $response->getHeaderLine('X-Powered-By');
        $this->assertMatchesRegularExpression('#^PHP/\d+\.\d+\.\d+#', $poweredBy, 'X-Powered-By header missing.');

        fwrite(STDERR, PHP_EOL . "Runtime under test: {$poweredBy}" . PHP_EOL);

        $expected = getenv('IP_TEST_EXPECTED_PHP');
        if ($expected !== false && $expected !== '') {
            $this->assertSame("PHP/{$expected}", $poweredBy, 'App is not running on the expected PHP version.');
        }
    }

    #[Test]
    public function it_runs_on_a_php_release_patched_for_cve_2026_109618_and_109619(): void
    {
        if (getenv('IP_TEST_REQUIRE_PATCHED_PHP') !== '1') {
            $this->markTestSkipped('Set IP_TEST_REQUIRE_PATCHED_PHP=1 to enforce the patched-runtime gate.');
        }

        $poweredBy = $this->request('GET', 'sessions/login')->getHeaderLine('X-Powered-By');
        preg_match('#^PHP/(\d+\.\d+\.\d+)#', $poweredBy, $m);
        $version = $m[1] ?? '0.0.0';

        $patched = match (true) {
            version_compare($version, '8.5.0', '>=') => false,
            version_compare($version, '8.4.0', '>=') => version_compare($version, '8.4.27', '>='),
            version_compare($version, '8.3.0', '>=') => false,
            version_compare($version, '8.2.0', '>=') => version_compare($version, '8.2.35', '>='),
            default                                   => false,
        };

        $this->assertTrue($patched, "PHP {$version} is not a patched release (need >= 8.4.27 or >= 8.2.35 on the 8.2 line).");
    }
}
