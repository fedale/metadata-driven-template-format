<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat\Tests\Versions;

use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Versions\ItemStates;
use Fedale\TemplateFormat\Versions\VersionCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a version's number fits what it changed, item by item, against an earlier version.
 */
final class VersionCheckTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../Fixtures/shop';

    /**
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $change   the new fields of objects/product.yaml
     * @param list<string>                                                     $expected
     */
    #[DataProvider('changes')]
    public function testANumberMustFitTheChanges(\Closure $change, string $to, array $expected): void
    {
        $base = self::fixture();
        $next = self::withProduct($base, $change);

        self::assertSame($expected, self::problems($base, $next, '1.0.0', $to));
    }

    /**
     * @return iterable<string, array{\Closure(list<array<string, mixed>>): list<array<string, mixed>>, string, list<string>}>
     */
    public static function changes(): iterable
    {
        $same = self::same(...);
        $relabel = self::relabel(...);
        $add = self::add(...);
        $setting = self::setting(...);
        $remove = self::remove(...);
        $rename = self::rename(...);

        yield 'nothing, a patch' => [$same, '1.0.1', []];
        yield 'a label, a patch' => [$relabel, '1.0.1', []];
        yield 'an added field, a patch' => [$add, '1.0.1', ['objects/product.yaml: field acme.product.notes: an addition needs a new minor version, 1.0.1 is not one.']];
        yield 'an added field, a minor' => [$add, '1.1.0', []];
        yield 'a setting, a patch' => [$setting, '1.0.1', ['objects/product.yaml: field acme.product.name: a change of settings needs a new minor version, 1.0.1 is not one.']];
        yield 'a setting, a minor' => [$setting, '1.1.0', []];
        yield 'a removed field, a minor' => [$remove, '1.1.0', ['objects/product.yaml: field acme.product.photo: a removal needs a new major version, 1.1.0 is not one.']];
        yield 'a renamed field, a minor' => [$rename, '1.1.0', ['objects/product.yaml: field acme.product.name: a rename needs a new major version, 1.1.0 is not one.']];
        yield 'a renamed field, a major' => [$rename, '2.0.0', []];
        yield 'a removed field, a major' => [$remove, '2.0.0', []];
    }

    public function testMigrationStepsNeedAMajorVersion(): void
    {
        $base = self::fixture();

        self::assertSame(
            ['template.yaml: spec.migrations: migration steps need a new major version, 1.1.0 is not one.'],
            VersionCheck::problems(self::states($base), self::states($base), '1.0.0', '1.1.0', true),
        );
    }

    public function testATranslationComesAndGoesInAPatch(): void
    {
        $base = self::fixture();
        $without = new Package($base->manifest, array_values(array_filter($base->documents, static fn (Document $document): bool => DocumentKind::Translations !== $document->kind)), $base->directory);

        self::assertSame([], self::problems($base, $without, '1.0.0', '1.0.1'));
        self::assertSame([], self::problems($without, $base, '1.0.0', '1.0.1'));
    }

    /**
     * A setting written with its default value is the same item as one leaving it out.
     */
    public function testADefaultWrittenOutIsNoChange(): void
    {
        $base = self::fixture();
        $next = self::withProduct($base, self::defaultsWrittenOut(...));

        self::assertSame([], self::problems($base, $next, '1.0.0', '1.0.1'));
    }

    /**
     * @return list<string>
     */
    private static function problems(Package $base, Package $next, string $from, string $to): array
    {
        return VersionCheck::problems(self::states($base), self::states($next), $from, $to, false);
    }

    /**
     * @return array<string, \Fedale\TemplateFormat\Versions\ItemState>
     */
    private static function states(Package $package): array
    {
        return (new ItemStates(new FieldTypeCatalog()))->of($package);
    }

    /**
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private static function withProduct(Package $package, \Closure $change): Package
    {
        return new Package($package->manifest, array_map(static function (Document $document) use ($change): Document {
            if ('objects/product.yaml' !== $document->path) {
                return $document;
            }
            $fields = [];
            foreach (\is_array($document->spec['fields'] ?? null) ? $document->spec['fields'] : [] as $field) {
                if (\is_array($field)) {
                    $fields[] = array_combine(array_map(strval(...), array_keys($field)), array_values($field));
                }
            }

            return new Document($document->kind, $document->id, ['fields' => $change($fields)] + $document->spec, $document->path);
        }, $package->documents), $package->directory);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function same(array $fields): array
    {
        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function relabel(array $fields): array
    {
        $fields[0]['label'] = 'Title';

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function add(array $fields): array
    {
        $fields[] = ['id' => 'acme.product.notes', 'name' => 'notes', 'type' => 'text', 'label' => 'Notes'];

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function setting(array $fields): array
    {
        $fields[0]['settings'] = ['maxLength' => 80];

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function remove(array $fields): array
    {
        array_pop($fields);

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function rename(array $fields): array
    {
        $fields[0]['name'] = 'title';

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function defaultsWrittenOut(array $fields): array
    {
        $fields[0]['settings'] = ['maxLength' => 255, 'multiline' => false];

        return $fields;
    }

    private static function fixture(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(self::FIXTURE);
    }
}
