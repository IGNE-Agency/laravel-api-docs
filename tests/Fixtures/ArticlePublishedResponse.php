<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Illuminate\Contracts\Support\Responsable;

/**
 * A response contract, as Laravel Fortify has for each of its answers: the container decides which class answers.
 */
interface ArticlePublishedResponse extends Responsable {}
