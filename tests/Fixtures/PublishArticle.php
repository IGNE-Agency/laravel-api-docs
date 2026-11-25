<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

/**
 * An action class: an object that is called itself.
 */
final class PublishArticle
{
    /**
     * @throws ArticleLockedException
     */
    public function __invoke(bool $locked): bool
    {
        if ($locked) {
            throw new ArticleLockedException;
        }

        return true;
    }
}
