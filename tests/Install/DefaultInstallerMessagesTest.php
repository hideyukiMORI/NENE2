<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\DefaultInstallerMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultInstallerMessagesTest extends TestCase
{
    public function testMapsKnownReasonCodesToWording(): void
    {
        $messages = new DefaultInstallerMessages();

        self::assertStringContainsString('PHP version', $messages->forReasonCode('php_too_old'));
        self::assertStringContainsString('base domain', $messages->forReasonCode('base_domain_required'));
        self::assertStringContainsString('already installed', $messages->forReasonCode('marker_present'));
    }

    public function testUnknownReasonCodesFallBackToAGenericMessage(): void
    {
        $messages = new DefaultInstallerMessages();

        $fallback = $messages->forReasonCode('something_new');
        self::assertNotSame('', $fallback);
        self::assertNotSame('something_new', $fallback, 'a raw code must never be shown');
        // Two different unknown codes get the same generic message.
        self::assertSame($fallback, $messages->forReasonCode('another_unknown'));
    }

    #[DataProvider('knownReasonCodes')]
    public function testEveryKnownMessageIsNonEmptyAndUnbranded(string $code): void
    {
        $message = (new DefaultInstallerMessages())->forReasonCode($code);

        self::assertNotSame('', $message);
        // The default catalogue must name no product; branding belongs to the product template.
        self::assertSame(false, stripos($message, 'nene'), 'the default messages must be brand-neutral');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownReasonCodes(): iterable
    {
        yield 'php_too_old' => ['php_too_old'];
        yield 'extension_missing' => ['extension_missing'];
        yield 'not_writable' => ['not_writable'];
        yield 'unknown_mode' => ['unknown_mode'];
        yield 'base_domain_required' => ['base_domain_required'];
        yield 'base_domain_invalid' => ['base_domain_invalid'];
        yield 'marker_present' => ['marker_present'];
        yield 'database_provisioned' => ['database_provisioned'];
    }
}
