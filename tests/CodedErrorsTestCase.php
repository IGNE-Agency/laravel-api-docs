<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests;

use Igne\ApiDocs\Tests\Fixtures\CodedErrorsServiceProvider;

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
