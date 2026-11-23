<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests;

/**
 * The same application with every extension turned off in the configuration.
 */
abstract class SwitchedOffTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scramble-extensions', ['called_methods' => false, 'middleware' => false, 'pagination' => false]);
    }
}
