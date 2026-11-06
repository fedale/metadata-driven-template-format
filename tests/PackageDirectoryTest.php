<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat\Tests;

use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\TestCase;

final class PackageDirectoryTest extends TestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-template-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testReadsTheDocumentsTheManifestLists(): void
    {
        $package = PackageCheckerTest::fixture();

        self::assertSame('acme/shop', $package->name());
        self::assertSame('1.0.0', $package->version());
        self::assertSame([
            'forms/catalogue.yaml',
            'objects/category.yaml',
            'objects/product.yaml',
            'objects/tag.yaml',
            'seeds/category.yaml',
            'seeds/product.yaml',
            'seeds/tag.yaml',
            'translations/en.yaml',
            'translations/it.yaml',
            'views/product.form.main.yaml',
            'views/product.grid.main.yaml',
        ], array_map(static fn (Document $document): string => $document->path, $package->documents));
    }

    public function testWritesAPackageThatReadsBackTheSame(): void
    {
        $directories = new PackageDirectory(new DocumentCodec());
        $package = PackageCheckerTest::fixture();

        $directories->write($package, $this->directory);
        $read = $directories->read($this->directory);

        self::assertEquals([$package->manifest, $package->documents], [$read->manifest, $read->documents]);
        self::assertSame($this->directory, $read->directory);
    }

    public function testWritesIntoANewOrEmptyDirectoryOnly(): void
    {
        $directories = new PackageDirectory(new DocumentCodec());
        $directories->write(PackageCheckerTest::fixture(), $this->directory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not empty');
        $directories->write(PackageCheckerTest::fixture(), $this->directory);
    }

    public function testAResourceStaysInsideThePackage(): void
    {
        $package = PackageCheckerTest::fixture();
        $manifest = new Document($package->manifest->kind, $package->manifest->id, ['resources' => ['../secrets/*.yaml']] + $package->manifest->spec, $package->manifest->path);
        $directories = new PackageDirectory(new DocumentCodec());
        $directories->write(new Package($manifest, []), $this->directory);

        try {
            $directories->read($this->directory);
            self::fail('A resource outside the package must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame(['template.yaml: spec.resources.0: A resource stays inside the package.'], array_map(strval(...), $e->problems));
        }
    }

    public function testReadsNothingThroughASymbolicLink(): void
    {
        $directories = new PackageDirectory(new DocumentCodec());
        $directories->write(PackageCheckerTest::fixture(), $this->directory.'/package');
        // A linked document, and a linked directory of documents: both resolve outside.
        mkdir($this->directory.'/elsewhere');
        rename($this->directory.'/package/objects', $this->directory.'/elsewhere/objects');
        symlink($this->directory.'/elsewhere/objects', $this->directory.'/package/objects');
        rename($this->directory.'/package/forms/catalogue.yaml', $this->directory.'/elsewhere/catalogue.yaml');
        symlink($this->directory.'/elsewhere/catalogue.yaml', $this->directory.'/package/forms/catalogue.yaml');

        try {
            $directories->read($this->directory.'/package');
            self::fail('A document read through a link must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame([
                'objects/category.yaml: Not a regular file.',
                'objects/product.yaml: Not a regular file.',
                'objects/tag.yaml: Not a regular file.',
                'forms/catalogue.yaml: Not a regular file.',
            ], array_map(strval(...), $e->problems));
        }
    }

    public function testRefusesALinkedManifest(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/secret.yaml', 'runner secret: [');
        symlink($this->directory.'/secret.yaml', $this->directory.'/template.yaml');

        try {
            (new PackageDirectory(new DocumentCodec()))->read($this->directory);
            self::fail('A linked manifest must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame(['template.yaml: Not a regular file.'], array_map(strval(...), $e->problems));
        }
    }

    public function testAPackageHasAManifest(): void
    {
        $this->expectException(InvalidPackage::class);
        $this->expectExceptionMessage('No manifest in');

        (new PackageDirectory(new DocumentCodec()))->read(__DIR__);
    }
}
