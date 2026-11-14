<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Security\Keys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase
{
    public function testGeneratedKeysHaveTenAllowedLetters(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $key = Keys::generate();
            self::assertMatchesRegularExpression('/^[a-km-np-z]{10}$/', $key);
            self::assertStringNotContainsString('l', $key);
            self::assertStringNotContainsString('o', $key);
            self::assertFalse(Keys::isReserved($key));
            self::assertTrue(Keys::isValid($key));
            $seen[$key] = true;
        }
        self::assertCount(2000, $seen);
    }

    public function testEveryLetterOfTheAlphabetIsUsed(): void
    {
        $letters = [];
        for ($i = 0; $i < 300; $i++) {
            $letters += array_flip(str_split(Keys::generate()));
        }
        ksort($letters);

        self::assertSame(Keys::ALPHABET, implode('', array_keys($letters)));
        self::assertSame(24, strlen(Keys::ALPHABET));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function keys(): iterable
    {
        yield 'valid' => ['kfmxqtrwpz', true];
        yield 'nine letters' => ['kfmxqtrwp', false];
        yield 'eleven letters' => ['kfmxqtrwpzz', false];
        yield 'contains l' => ['kfmxqtrwpl', false];
        yield 'contains o' => ['kfmxqtrwpo', false];
        yield 'upper case' => ['KFMXQTRWPZ', false];
        yield 'digit' => ['kfmxqtrwp2', false];
        yield 'dash' => ['kfmxq-trwp', false];
        yield 'trailing newline' => ["kfmxqtrwpz\n", false];
        yield 'reserved: admin' => ['adminxqtrw', false];
        yield 'reserved: api' => ['apixqtrwpz', false];
        yield 'reserved: media' => ['mediaqtrwp', false];
        yield 'reserved: assets' => ['assetsqtrw', false];
        yield 'reserved: v' => ['vfmxqtrwpz', false];
    }

    #[DataProvider('keys')]
    public function testKeyValidation(string $key, bool $valid): void
    {
        self::assertSame($valid, Keys::isValid($key));
    }

    /**
     * @return iterable<string, array{string, array{number: int, key: string}|null}>
     */
    public static function videoRefs(): iterable
    {
        yield 'number then key' => ['1042kfmxqtrwpz', ['number' => 1042, 'key' => 'kfmxqtrwpz']];
        yield 'first number' => ['1001hbnwzcxqre', ['number' => 1001, 'key' => 'hbnwzcxqre']];
        yield 'large number' => ['123456hbnwzcxqre', ['number' => 123456, 'key' => 'hbnwzcxqre']];
        yield 'key too short' => ['1042kfmxqtrwp', null];
        yield 'key too long' => ['1042kfmxqtrwpzz', null];
        yield 'leading zero' => ['01042kfmxqtrwpz', null];
        yield 'number too short' => ['104kfmxqtrwpz', null];
        yield 'no number' => ['kfmxqtrwpz', null];
        yield 'no key' => ['1042', null];
        yield 'letter inside the number' => ['10a42kfmxqtrwpz', null];
        yield 'forbidden letter in key' => ['1042kfmxqtrwpl', null];
        yield 'upper-case key' => ['1042KFMXQTRWPZ', null];
        yield 'reserved key' => ['1042adminqtrwp', null];
    }

    /**
     * @param array{number: int, key: string}|null $expected
     */
    #[DataProvider('videoRefs')]
    public function testVideoRefIsSplitAtTheFirstLetter(string $ref, ?array $expected): void
    {
        self::assertSame($expected, Keys::parseVideoRef($ref));
    }
}
