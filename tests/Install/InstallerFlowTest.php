<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\InstallerFlow;
use Nene2\Install\InstallerStep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallerFlowTest extends TestCase
{
    public function testNavigatesAcrossSteps(): void
    {
        $flow = $this->flow();

        self::assertSame('requirements', $flow->first()->id);
        self::assertSame('complete', $flow->last()->id);
        self::assertSame(3, $flow->count());

        self::assertTrue($flow->has('database'));
        self::assertFalse($flow->has('nope'));

        self::assertSame('database', $flow->step('database')->id);
        self::assertSame(
            ['db_name', 'db_user'],
            array_map(static fn ($field): string => $field->name, $flow->step('database')->inputs),
        );

        self::assertSame('database', $flow->next('requirements')?->id);
        self::assertSame('complete', $flow->next('database')?->id);
        self::assertNull($flow->next('complete'), 'the last step has no next');
        self::assertNull($flow->next('nope'), 'an unknown step has no next');

        self::assertFalse($flow->isLast('requirements'));
        self::assertTrue($flow->isLast('complete'));

        self::assertSame(1, $flow->position('requirements'));
        self::assertSame(3, $flow->position('complete'));
    }

    public function testStepRejectsAnUnknownId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown installer step');
        $this->flow()->step('nope');
    }

    public function testPositionRejectsAnUnknownId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown installer step');
        $this->flow()->position('nope');
    }

    public function testRejectsAnEmptyFlow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one step');
        new InstallerFlow([]);
    }

    public function testRejectsDuplicateStepIds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unique');
        new InstallerFlow([new InstallerStep('database'), new InstallerStep('database')]);
    }

    private function flow(): InstallerFlow
    {
        return new InstallerFlow([
            new InstallerStep('requirements'),
            new InstallerStep('database', ['db_name', 'db_user']),
            new InstallerStep('complete'),
        ]);
    }
}
