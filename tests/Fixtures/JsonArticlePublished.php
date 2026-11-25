<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Illuminate\Http\JsonResponse;

/**
 * The class bound to the contract: a redirect for a browser, an empty 202 for a JSON client.
 */
final class JsonArticlePublished implements ArticlePublishedResponse
{
    public function toResponse($request)
    {
        return $request->wantsJson() ? new JsonResponse('', 202) : redirect('/');
    }
}
