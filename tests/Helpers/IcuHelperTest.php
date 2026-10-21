<?php

declare(strict_types=1);

namespace Hirtz\Translation\Tests\Helpers;

use Hirtz\Skeleton\Test\TestCase;
use Hirtz\Translation\Helpers\IcuHelper;
use PHPUnit\Framework\Attributes\DataProvider;

class IcuHelperTest extends TestCase
{
    /**
     * @param list<string>|null $expected
     */
    #[DataProvider('provideArgumentNames')]
    public function testGetArgumentNames(string $message, ?array $expected): void
    {
        self::assertSame($expected, IcuHelper::getArgumentNames($message));
    }

    /**
     * @return array<string, array{string, list<string>|null}>
     */
    public static function provideArgumentNames(): array
    {
        return [
            'empty' => ['', []],
            'text' => ['Перевод 翻訳 🌍', []],
            'simple' => ['Hello {name}, {name}!', ['name']],
            'sorted' => ['{b} {a}', ['a', 'b']],
            'spaced' => ['{ name }', ['name']],
            'number' => ['{n, number} Å', ['n']],
            'plural' => ['{count, plural, one{# item} other{# items}}', ['count']],
            'nested' => ['{count, plural, =0{none} other{{name} has #}}', ['count', 'name']],
            'select' => ['{gender, select, female{she} other{they}}', ['gender']],
            'escaped apostrophe' => ["It''s {name}", ['name']],
            'quoted brace' => ["'{literal}' {name}", ['name']],
            'apostrophe' => ["l'entrée de {name}", ['name']],
            'unclosed' => ['Broken {name', null],
            'unopened' => ['Broken }', null],
            'empty argument' => ['{}', null],
            'plural without messages' => ['{count, plural, other}', null],
        ];
    }

    public function testHasSameArguments(): void
    {
        self::assertTrue(IcuHelper::hasSameArguments('Hello {name}', 'Hallo {name}'));
        self::assertTrue(IcuHelper::hasSameArguments('{n, plural, one{# file} other{# files}}', '{n, plural, one{# Datei} other{# Dateien}}'));
        self::assertTrue(IcuHelper::hasSameArguments('Hello {name}', ''));
        self::assertTrue(IcuHelper::hasSameArguments('', 'Hallo {name}'));
        self::assertTrue(IcuHelper::hasSameArguments('Broken {name', 'Kaputt'));

        self::assertFalse(IcuHelper::hasSameArguments('Hello {name}', 'Hallo {nom}'));
        self::assertFalse(IcuHelper::hasSameArguments('Hello {name}', 'Hallo'));
        self::assertFalse(IcuHelper::hasSameArguments('Hello', 'Hallo {name}'));
        self::assertFalse(IcuHelper::hasSameArguments('Hello {name}', 'Hallo {name'));
    }
}
