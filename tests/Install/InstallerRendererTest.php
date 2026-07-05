<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\DefaultInstallerMessages;
use Nene2\Install\InstallerField;
use Nene2\Install\InstallerFlow;
use Nene2\Install\InstallerMessages;
use Nene2\Install\InstallerRenderer;
use Nene2\Install\InstallerStep;
use Nene2\Install\InstallerTemplate;
use Nene2\Install\ReInstallationGuard;
use PHPUnit\Framework\TestCase;

final class InstallerRendererTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->cleanup = [];
    }

    public function testRendersTheStepWithProgressAndFields(): void
    {
        $html = (new InstallerRenderer())->render($this->template($this->openGuard()), 'database');

        self::assertStringContainsString('data-step="database"', $html);
        self::assertStringContainsString('Step 1 of 2', $html);
        self::assertStringContainsString('name="db_name"', $html);
        self::assertStringContainsString('name="db_user"', $html);
        self::assertStringContainsString('<form method="post">', $html);
    }

    public function testEscapesInputValues(): void
    {
        $html = (new InstallerRenderer())->render(
            $this->template($this->openGuard()),
            'database',
            [],
            ['db_name' => '"><script>alert(1)</script>'],
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRendersDeclaredInputTypes(): void
    {
        $html = (new InstallerRenderer())->render($this->template($this->openGuard()), 'database');

        // A bare field name defaults to a text input.
        self::assertStringContainsString('type="text" name="db_name"', $html);
        // A declared password field renders as a password input.
        self::assertStringContainsString('type="password" name="db_password"', $html);
    }

    public function testNeverReflectsASubmittedPasswordValue(): void
    {
        $html = (new InstallerRenderer())->render(
            $this->template($this->openGuard()),
            'database',
            [],
            ['db_name' => 'shop', 'db_password' => 'hunter2'],
        );

        // The secret must never appear in the page source...
        self::assertStringNotContainsString('hunter2', $html);
        // ...and the password input must carry no value attribute at all.
        self::assertStringContainsString('type="password" name="db_password">', $html);
        // A non-secret field still reflects its submitted value.
        self::assertStringContainsString('value="shop"', $html);
    }

    public function testRendersErrorCodesThroughTheMessageCatalogue(): void
    {
        $html = (new InstallerRenderer())->render(
            $this->template($this->openGuard()),
            'database',
            ['php_too_old'],
        );

        self::assertStringContainsString('class="installer-errors"', $html);
        self::assertStringContainsString('PHP version', $html);
    }

    public function testUnknownErrorCodeFallsBackAndIsNeverEchoedRaw(): void
    {
        $html = (new InstallerRenderer())->render(
            $this->template($this->openGuard()),
            'database',
            ['download_failed_http_500_https://secret.internal/x'],
        );

        // The raw code (which here stands in for leaky internal detail) must not appear.
        self::assertStringNotContainsString('secret.internal', $html);
        self::assertStringContainsString('did not pass', $html);
    }

    public function testABlockedGuardRendersTheNoticeInsteadOfTheForm(): void
    {
        $marker = $this->markerPath();
        file_put_contents($marker, 'installed');

        $html = (new InstallerRenderer())->render($this->template(new ReInstallationGuard($marker)), 'database');

        self::assertStringContainsString('installer-blocked', $html);
        self::assertStringContainsString('already installed', $html);
        self::assertStringNotContainsString('<form', $html, 'a blocked installer must not render the form');
    }

    private function template(ReInstallationGuard $guard): InstallerTemplate
    {
        return new class ($guard) implements InstallerTemplate {
            public function __construct(private ReInstallationGuard $guard)
            {
            }

            public function flow(): InstallerFlow
            {
                return new InstallerFlow([
                    // Bare names default to text fields; the password field declares its type.
                    new InstallerStep('database', ['db_name', 'db_user', InstallerField::password('db_password')]),
                    new InstallerStep('complete'),
                ]);
            }

            public function messages(): InstallerMessages
            {
                return new DefaultInstallerMessages();
            }

            public function guard(): ReInstallationGuard
            {
                return $this->guard;
            }
        };
    }

    private function openGuard(): ReInstallationGuard
    {
        // A marker path that does not exist -> not blocked.
        return new ReInstallationGuard($this->markerPath());
    }

    private function markerPath(): string
    {
        $path = sys_get_temp_dir() . '/nene2-render-' . bin2hex(random_bytes(6)) . '.installed';
        $this->cleanup[] = $path;

        return $path;
    }
}
