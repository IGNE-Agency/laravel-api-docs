<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests;

use Igne\ScrambleExtensions\Tests\Fixtures\CodedErrorsServiceProvider;

/**
 * The application with an error format of its own (see {@see CodedErrorsServiceProvider}).
 */
abstract class CodedErrorsTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CodedErrorsServiceProvider::class];
    }
}
