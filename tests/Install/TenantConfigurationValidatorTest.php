<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\TenantConfigurationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantConfigurationValidatorTest extends TestCase
{
    public function testSingleModeNormalisesBaseDomainAway(): void
    {
        $result = $this->invoiceValidator()->validate('single');

        self::assertTrue($result->valid);
        self::assertNotNull($result->configuration);
        self::assertSame('single', $result->configuration->mode);
        self::assertSame('', $result->configuration->baseDomain);
        self::assertSame([], $result->errors);
    }

    #[DataProvider('modesThatIgnoreBaseDomain')]
    public function testModesThatDoNotNeedABaseDomainDiscardIt(string $mode): void
    {
        // Even if a base domain is supplied, a mode that does not need one drops it.
        $result = $this->invoiceValidator()->validate($mode, 'example.com');

        self::assertTrue($result->valid);
        self::assertNotNull($result->configuration);
        self::assertSame($mode, $result->configuration->mode);
        self::assertSame('', $result->configuration->baseDomain);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modesThatIgnoreBaseDomain(): iterable
    {
        yield 'single' => ['single'];
        yield 'path' => ['path'];
        yield 'custom_domain' => ['custom_domain'];
    }

    public function testSubdomainWithAValidBaseDomainIsAcceptedAndTrimmed(): void
    {
        $result = $this->invoiceValidator()->validate('subdomain', '  records.example.com  ');

        self::assertTrue($result->valid);
        self::assertNotNull($result->configuration);
        self::assertSame('subdomain', $result->configuration->mode);
        self::assertSame('records.example.com', $result->configuration->baseDomain);
    }

    #[DataProvider('blankBaseDomains')]
    public function testSubdomainRequiresANonEmptyBaseDomain(?string $baseDomain): void
    {
        $result = $this->invoiceValidator()->validate('subdomain', $baseDomain);

        self::assertFalse($result->valid);
        self::assertNull($result->configuration);
        self::assertSame(['base_domain_required'], $result->errors);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function blankBaseDomains(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('malformedBaseDomains')]
    public function testSubdomainRejectsAMalformedBaseDomain(string $baseDomain): void
    {
        $result = $this->invoiceValidator()->validate('subdomain', $baseDomain);

        self::assertFalse($result->valid);
        self::assertSame(['base_domain_invalid'], $result->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBaseDomains(): iterable
    {
        yield 'space' => ['bad domain.com'];
        yield 'underscore' => ['bad_domain.com'];
        yield 'quote' => ['x".com'];
        yield 'dollar' => ['x$.com'];
        yield 'newline' => ["x.com\nEVIL=1"];
        yield 'slash' => ['x.com/path'];
    }

    public function testUnknownModeIsRejected(): void
    {
        $result = $this->invoiceValidator()->validate('galaxy');

        self::assertFalse($result->valid);
        self::assertSame(['unknown_mode'], $result->errors);
    }

    /**
     * The invoice runtime vocabulary. There is no shared default factory — each product
     * declares its own set — so tests construct the one they exercise explicitly.
     */
    private function invoiceValidator(): TenantConfigurationValidator
    {
        return new TenantConfigurationValidator(
            ['single', 'path', 'subdomain', 'custom_domain'],
            ['subdomain'],
        );
    }

    public function testHonoursAnInjectedModeVocabulary(): void
    {
        $validator = new TenantConfigurationValidator(['a', 'b'], ['b']);

        self::assertTrue($validator->validate('a')->valid);
        self::assertTrue($validator->validate('b', 'host.example')->valid);

        // 'subdomain' is standard but not in this product's vocabulary.
        self::assertSame(['unknown_mode'], $validator->validate('subdomain', 'host.example')->errors);

        // 'b' needs a base domain here, unlike the standard set.
        self::assertSame(['base_domain_required'], $validator->validate('b')->errors);
    }
}
