<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

use Symfony\Component\HttpKernel\Exception\LockedHttpException;

/**
 * The article is being edited by someone else. Try again once they are done.
 */
final class ArticleLockedException extends LockedHttpException
{
    public function __construct()
    {
        parent::__construct('Someone else is editing this article.');
    }
}
