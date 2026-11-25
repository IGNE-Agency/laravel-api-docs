<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests;

use Igne\ApiDocs\Tests\Fixtures\CodedErrorsServiceProvider;

/**
 * The same application, with the provider of the error format booted before this package instead of after it.
 */
abstract class CodedErrorsFirstTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CodedErrorsServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
