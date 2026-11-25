<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Testing;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

/**
 * The document of the application under test, generated once per test class instead of once per
 * test: generating it is the slow part of an assertion.
 *
 * @internal
 */
final class GeneratedDocument
{
    /**
     * @var array{0: class-string, 1: array<string, mixed>}|null
     */
    private static ?array $last = null;

    /**
     * @param  class-string  $testClass  a test class sets its application up, so each gets a document of its own
     * @return array<string, mixed>
     */
    public static function of(string $testClass): array
    {
        if ((self::$last[0] ?? null) !== $testClass) {
            // Generated here, not fetched: the request of the test may be one the documentation route refuses.
            $document = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

            // Encoded and decoded, so the document is the plain JSON a client reads.
            self::$last = [$testClass, json_decode((string) json_encode($document), true)];
        }

        return self::$last[1];
    }
}
