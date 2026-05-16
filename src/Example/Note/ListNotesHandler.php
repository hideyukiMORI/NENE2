<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Http\JsonResponseFactory;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListNotesHandler
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        private ListNotesUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int) $query['limit'] : 20;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;

        $errors = [];

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $errors[] = new ValidationError('limit', 'limit must be between 1 and ' . self::MAX_LIMIT . '.', 'out_of_range');
        }

        if ($offset < 0) {
            $errors[] = new ValidationError('offset', 'offset must be 0 or greater.', 'out_of_range');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $output = $this->useCase->execute(new ListNotesInput($limit, $offset));

        return $this->response->create([
            'items' => array_map(
                static fn (ListNoteItem $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'body' => $item->body,
                ],
                $output->items,
            ),
            'limit' => $output->limit,
            'offset' => $output->offset,
        ]);
    }
}
