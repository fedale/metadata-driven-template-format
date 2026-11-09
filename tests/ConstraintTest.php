<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat\Tests;

use Fedale\TemplateFormat\Constraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
    /**
     * @param list<string> $allowed
     * @param list<string> $refused
     */
    #[DataProvider('constraints')]
    public function testAllowsTheVersionsOfItsRange(string $constraint, array $allowed, array $refused): void
    {
        $parsed = Constraint::parse($constraint);

        foreach ($allowed as $version) {
            self::assertTrue($parsed->allows($version), $constraint.' allows '.$version);
        }
        foreach ($refused as $version) {
            self::assertFalse($parsed->allows($version), $constraint.' refuses '.$version);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function constraints(): iterable
    {
        yield 'caret' => ['^1.2', ['1.2.0', '1.2.9', '1.9.0'], ['1.1.9', '2.0.0', '0.9.0']];
        yield 'caret, major only' => ['^1', ['1.0.0', '1.5.2'], ['2.0.0', '0.9.9']];
        yield 'caret below 1' => ['^0.3', ['0.3.0', '0.3.7'], ['0.4.0', '0.2.9', '1.0.0']];
        yield 'caret below 0.1' => ['^0.0.3', ['0.0.3'], ['0.0.4', '0.0.2']];
        yield 'tilde' => ['~1.2', ['1.2.0', '1.2.9'], ['1.3.0', '1.1.0']];
        yield 'exact' => ['1.2.3', ['1.2.3'], ['1.2.4', '1.2.2']];
        yield 'not a version' => ['^1.0', [], ['1.0', 'one']];
    }

    #[DataProvider('invalid')]
    public function testRefusesWhatIsNotInTheSubset(string $constraint): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Constraint::parse($constraint);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalid(): iterable
    {
        yield ['>=1.0'];
        yield ['1.0'];
        yield ['^1.0 || ^2.0'];
        yield ['*'];
        yield ['^01.0'];
        yield [''];
    }
}
