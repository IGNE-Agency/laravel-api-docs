<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

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

    public function create(StoreArticleRequest $request): Response
    {
        return response()->noContent();
    }

    /**
     * @throws ArticleLockedException
     */
    public function update(): Response
    {
        throw new ArticleLockedException;
    }

    public function destroy(): Response
    {
        abort_if(Article::query()->exists(), 409);

        return response()->noContent();
    }

    public function publish(PublishArticle $publish): ArticlePublishedResponse|ArticleNotPublishedResponse
    {
        return $publish(false) ? app(ArticlePublishedResponse::class) : app(ArticleNotPublishedResponse::class);
    }
}
