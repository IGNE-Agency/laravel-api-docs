<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A response that only throws for a JSON client, as a failed confirmation of Fortify does.
 */
final class ArticleNotPublished implements ArticleNotPublishedResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            throw new ConflictHttpException('The article is not ready.');
        }

        return redirect('/');
    }
}
