<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\PaginationQueryParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListNotesHandler
{
    public function __construct(
        private ListNotesUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = PaginationQueryParser::parse($request);

        $output = $this->useCase->execute(new ListNotesInput($pagination->limit, $pagination->offset));

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
