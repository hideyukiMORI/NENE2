<?php

declare(strict_types=1);

namespace Nene2\Tests\Validation;

use InvalidArgumentException;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    public function testValidationErrorExportsPublicShape(): void
    {
        $error = new ValidationError('email', 'Email is required.', 'required');

        self::assertSame(
            [
                'field' => 'email',
                'message' => 'Email is required.',
                'code' => 'required',
            ],
            $error->toArray(),
        );
    }

    public function testValidationExceptionRequiresErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValidationException([]);
    }

    public function testValidationExceptionExportsErrorsForResponse(): void
    {
        $exception = new ValidationException([
            new ValidationError('name', 'Name is required.', 'required'),
        ]);

        self::assertSame(
            [
                [
                    'field' => 'name',
                    'message' => 'Name is required.',
                    'code' => 'required',
                ],
            ],
            $exception->errorsForResponse(),
        );
    }
}
