<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The article still has comments. Delete them first. */
final class ArticleHasCommentsException extends ConflictHttpException {}
