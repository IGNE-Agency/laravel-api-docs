<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Packages;

use ReflectionClass;

/**
 * Where a class comes from. A package is told by the first two parts of a class name (`Igne\Auth`).
 */
final class Package
{
    /**
     * Null for a class without a vendor and a package part.
     */
    public static function of(string $class): ?string
    {
        $segments = explode('\\', ltrim($class, '\\'));

        return count($segments) > 2 ? $segments[0].'\\'.$segments[1] : null;
    }

    /**
     * Null for a class that does not exist or has no file.
     */
    public static function isInVendor(string $class): ?bool
    {
        if (! class_exists($class)) {
            return null;
        }

        $path = (new ReflectionClass($class))->getFileName();

        return $path === false ? null : str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }
}
