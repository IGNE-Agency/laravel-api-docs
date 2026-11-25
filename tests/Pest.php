<?php

declare(strict_types=1);

use Igne\ScrambleExtensions\Tests\CodedErrorsFirstTestCase;
use Igne\ScrambleExtensions\Tests\CodedErrorsTestCase;
use Igne\ScrambleExtensions\Tests\SwitchedOffTestCase;
use Igne\ScrambleExtensions\Tests\TestCase;

uses(TestCase::class)->in('Feature/ExtensionsTest.php', 'Feature/RulesTest.php', 'Feature/LaravelErrorsTest.php');
uses(CodedErrorsTestCase::class)->in('Feature/CodedErrorsTest.php');
uses(CodedErrorsFirstTestCase::class)->in('Feature/BootOrderTest.php');
uses(SwitchedOffTestCase::class)->in('Feature/SwitchedOffTest.php');
