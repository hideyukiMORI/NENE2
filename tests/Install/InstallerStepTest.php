<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\InstallerField;
use Nene2\Install\InstallerInputType;
use Nene2\Install\InstallerStep;
use PHPUnit\Framework\TestCase;

final class InstallerStepTest extends TestCase
{
    public function testBareNamesBecomeTextFields(): void
    {
        $step = new InstallerStep('database', ['db_name', 'db_user']);

        self::assertSame(['db_name', 'db_user'], array_map(static fn (InstallerField $f): string => $f->name, $step->inputs));
        self::assertSame(
            [InstallerInputType::Text, InstallerInputType::Text],
            array_map(static fn (InstallerField $f): InstallerInputType => $f->type, $step->inputs),
        );
    }

    public function testDeclaredFieldsKeepTheirType(): void
    {
        $step = new InstallerStep('database', ['db_name', InstallerField::password('db_password')]);

        self::assertSame('db_name', $step->inputs[0]->name);
        self::assertSame(InstallerInputType::Text, $step->inputs[0]->type);
        self::assertSame('db_password', $step->inputs[1]->name);
        self::assertSame(InstallerInputType::Password, $step->inputs[1]->type);
    }

    public function testDisplayOnlyStepHasNoInputs(): void
    {
        self::assertSame([], (new InstallerStep('complete'))->inputs);
    }

    public function testInputTypeControlsWhetherAValueIsReflected(): void
    {
        self::assertTrue(InstallerInputType::Text->reflectsValue());
        self::assertFalse(InstallerInputType::Password->reflectsValue());
    }

    public function testFieldFactoriesProduceTheRightType(): void
    {
        self::assertSame(InstallerInputType::Text, InstallerField::text('a')->type);
        self::assertSame(InstallerInputType::Password, InstallerField::password('b')->type);
    }
}
