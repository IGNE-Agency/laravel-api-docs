<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ArticleController
{
    public function index(): AnonymousResourceCollection
    {
        return ArticleResource::collection(Article::query()->paginate());
    }

    public function store(): Response
    {
        return response()->noContent();
    }

    public function archive(ArticleArchive $archive): Response
    {
        $archive->archive();

        return response()->noContent();
    }
}
