<?php

declare(strict_types=1);

namespace Hirtz\Translation\Helpers;

class IcuHelper
{
    private const array SUB_MESSAGE_TYPES = ['plural', 'select', 'selectordinal'];

    /**
     * An empty source or translation has nothing to compare.
     */
    public static function hasSameArguments(string $source, string $translation): bool
    {
        if ($source === '' || $translation === '') {
            return true;
        }

        $expected = self::getArgumentNames($source);
        return $expected === null || self::getArgumentNames($translation) === $expected;
    }

    /**
     * @return list<string>|null
     */
    public static function getArgumentNames(string $message): ?array
    {
        $names = [];

        if (!self::parseMessage($message, $names)) {
            return null;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $names
     */
    private static function parseMessage(string $message, array &$names): bool
    {
        $length = strlen($message);

        for ($i = 0; $i < $length; $i++) {
            $i = self::skipQuote($message, $i);

            if ($i >= $length || $message[$i] === '}') {
                return $i >= $length;
            }

            if ($message[$i] === '{') {
                $end = self::findClosingBrace($message, $i);

                if ($end === null || !self::parseArgument(substr($message, $i + 1, $end - $i - 1), $names)) {
                    return false;
                }

                $i = $end;
            }
        }

        return true;
    }

    /**
     * @param list<string> $names
     */
    private static function parseArgument(string $argument, array &$names): bool
    {
        $parts = explode(',', $argument, 3);
        $name = trim($parts[0]);

        if ($name === '' || str_contains($name, '{')) {
            return false;
        }

        $names[] = $name;

        if (!in_array(strtolower(trim($parts[1] ?? '')), self::SUB_MESSAGE_TYPES, true)) {
            return true;
        }

        $style = $parts[2] ?? '';
        $offset = 0;

        while (($start = strpos($style, '{', $offset)) !== false) {
            $end = self::findClosingBrace($style, $start);

            if ($end === null || !self::parseMessage(substr($style, $start + 1, $end - $start - 1), $names)) {
                return false;
            }

            $offset = $end + 1;
        }

        return $offset > 0;
    }

    private static function findClosingBrace(string $message, int $start): ?int
    {
        $length = strlen($message);
        $depth = 0;

        for ($i = $start; $i < $length; $i++) {
            $i = self::skipQuote($message, $i);

            if ($i >= $length) {
                break;
            }

            if ($message[$i] === '{') {
                $depth++;
            } elseif ($message[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * ICU quotes literal text with an apostrophe only before a syntax character; `''` is an apostrophe itself.
     */
    private static function skipQuote(string $message, int $i): int
    {
        while (($message[$i] ?? '') === "'") {
            $next = $message[$i + 1] ?? '';

            if ($next === "'") {
                $i += 2;
            } elseif ($next !== '' && str_contains('{}#|', $next)) {
                $end = strpos($message, "'", $i + 1);
                $i = $end === false ? strlen($message) : $end + 1;
            } else {
                return $i + 1;
            }
        }

        return $i;
    }
}
