<?php

declare(strict_types=1);

use Igne\ApiDocs\Tests\CodedErrorsFirstTestCase;
use Igne\ApiDocs\Tests\CodedErrorsTestCase;
use Igne\ApiDocs\Tests\Packages\LaravelAuthTestCase;
use Igne\ApiDocs\Tests\SwitchedOffTestCase;
use Igne\ApiDocs\Tests\TestCase;

uses(TestCase::class)->in('Feature/ExtensionsTest.php', 'Feature/RulesTest.php', 'Feature/LaravelErrorsTest.php');
uses(CodedErrorsTestCase::class)->in('Feature/CodedErrorsTest.php');
uses(CodedErrorsFirstTestCase::class)->in('Feature/BootOrderTest.php');
uses(SwitchedOffTestCase::class)->in('Feature/SwitchedOffTest.php');

// Against a real package, when it is installed: it needs Laravel 13.
uses(LaravelAuthTestCase::class)->in('Packages');
