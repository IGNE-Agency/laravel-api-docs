<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ArticleArchive
{
    public function archive(): void
    {
        $this->ensureNotArchived();
    }

    private function ensureNotArchived(): void
    {
        throw new ConflictHttpException('The article is archived already.');
    }
}
