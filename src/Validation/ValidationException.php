<?php

declare(strict_types=1);

namespace Nene2\Validation;

use InvalidArgumentException;
use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @var non-empty-list<ValidationError> */
    private readonly array $errors;

    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(
        array $errors,
        string $message = 'The request contains invalid values.',
    ) {
        if ($errors === []) {
            throw new InvalidArgumentException('ValidationException requires at least one error.');
        }

        parent::__construct($message);

        $this->errors = $errors;
    }

    /**
     * @return non-empty-list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return non-empty-list<array{field: string, message: string, code: string}>
     */
    public function errorsForResponse(): array
    {
        return array_map(
            static fn (ValidationError $error): array => $error->toArray(),
            $this->errors,
        );
    }
}
