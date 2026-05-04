<?php

declare(strict_types=1);

namespace Nene2\Validation;

use InvalidArgumentException;

final readonly class ValidationError
{
    public function __construct(
        public string $field,
        public string $message,
        public string $code,
    ) {
        if ($this->field === '') {
            throw new InvalidArgumentException('Validation error field must not be empty.');
        }

        if ($this->message === '') {
            throw new InvalidArgumentException('Validation error message must not be empty.');
        }

        if ($this->code === '') {
            throw new InvalidArgumentException('Validation error code must not be empty.');
        }
    }

    /**
     * @return array{field: string, message: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
