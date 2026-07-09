<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use Nene2\Demo\DemoTemplateKeyInterface;

/**
 * Test fixture: the minimal string-backed enum shape a product uses to satisfy
 * {@see DemoTemplateKeyInterface}.
 */
enum FakeDemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';
    case Seisaku = 'seisaku';

    public function value(): string
    {
        return $this->value;
    }

    public static function tryFromValue(string $value): ?static
    {
        return self::tryFrom($value);
    }
}
