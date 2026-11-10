# fedale/metadata-driven-template-format

The template format of [metadata-driven](../metadata-driven): what a template package looks
like, and the tools to read, check, pack and list one. metadata-driven installs and upgrades
templates with it; the template catalog checks them with it on publish.

It is data only: a template holds objects, forms, views, translations and seeds, never code.

## What it gives you

| Class | What it does |
|---|---|
| `PackageDirectory` | Reads a package from a directory into a `Package` |
| `Archive` | Packs a package as a reproducible `.tar.gz`, and unpacks one safely (no absolute paths, no `..`, no links, size limits) |
| `PackageChecker` | The structural checks: every document against its JSON schema, unique ids, references, seeds, files. Returns a list of `Problem` |
| `DocumentCodec` | A document's YAML envelope (`format`, `kind`, `id`, `spec`) |
| `TemplateIndex`, `IndexEntry` | The index of a template repository (`metadata-driven/index/v1`): versions, archive URLs, checksums, signatures, yanked versions |
| `Signature` | Ed25519 signatures of index entries (libsodium) |
| `SeedRecords` | The records of a seed, inline or from a CSV file |
| `FieldTypes\FieldTypeCatalog` | The field types of format v1 and their settings, so settings are checked without metadata-driven |
| `Constraint` | A version constraint of `spec.requires`: `^1.2`, `~1.2` or an exact `1.2.3` |
| `Versions\ItemStates`, `Versions\VersionCheck` | A package's items (each object, field, view, form, translation) in a form that compares equal when they mean the same, and whether a version's number fits what it changed against an earlier version: a patch changes labels, layouts, forms and translations; a minor adds and changes settings; removals, renames and migration steps need a major |

The schemas live in `src/Resources/schema/v1/`, the field types in
`src/Resources/field-types.v1.json`.

```php
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\SchemaValidator;
use Fedale\TemplateFormat\SeedRecords;

$package = (new PackageDirectory(new DocumentCodec()))->read('/srv/templates/shop');
$problems = (new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()))->check($package);
```

### Templates requiring templates

A template may require others, with a constraint each:

```yaml
spec:
  requires:
    format: metadata-driven/v1
    acme/places: ^1.0
```

It then references their objects and fields by id, as its own (a relation's `target`, a composite
form's `object` and `via`, a seed's references by the key of the required seed), and only
references them: its ids differ from theirs, its views and seeds are of its own objects. Check it
with the required templates, as installed or published:

```php
$problems = $checker->check($people, [$places]);   // acme/places in a version ^1.0 allows
```

A requirement not given is a problem: the references to it cannot be checked.

Checking a new version's number against the previous one:

```php
use Fedale\TemplateFormat\Versions\ItemStates;
use Fedale\TemplateFormat\Versions\VersionCheck;

$states = new ItemStates(new FieldTypeCatalog());
$problems = VersionCheck::problems($states->of($previous), $states->of($next), '1.2.0', '1.3.0', migrates: false);
// e.g. "objects/product.yaml: field acme.product.photo: a removal needs a new major version, 1.3.0 is not one."
```

Seed records are not compared: a version that only changes records fits any number.

What only metadata-driven can tell (a form that does not fit its objects, a value that does not
fit its field) is checked there, at install.

## Requirements

PHP 8.4 with `sodium` and `zlib`; `symfony/yaml` and `opis/json-schema`. Nothing else: no
framework, no database.

## Development

```bash
composer install
composer qa    # php-cs-fixer (dry run), PHPStan at level max, PHPUnit
```

The format is documented in metadata-driven's `docs/templates.md`.
