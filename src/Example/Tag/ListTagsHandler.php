<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\PaginationQueryParser;
use Nene2\Http\PaginationResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListTagsHandler
{
    public function __construct(
        private ListTagsUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = PaginationQueryParser::parse($request);

        $output = $this->useCase->execute(new ListTagsInput($pagination->limit, $pagination->offset));

        return $this->response->create(
            (new PaginationResponse(
                items: array_map(
                    static fn (ListTagItem $item) => ['id' => $item->id, 'name' => $item->name],
                    $output->items,
                ),
                limit:  $output->limit,
                offset: $output->offset,
            ))->toArray(),
        );
    }
}
