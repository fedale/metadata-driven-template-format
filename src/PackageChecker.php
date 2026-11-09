<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat;

use Fedale\TemplateFormat\FieldTypes\FieldOwner;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\FieldTypes\ReferenceKind;

/**
 * The structural checks of a package, without metadata-driven: each document against its schema, the
 * settings of every field against the field type catalog, unique ids and names, and every
 * reference naming an item of the template.
 *
 * A template may require others (`spec.requires`, `vendor/name: ^1.2`): given those templates,
 * its references may also name their objects and fields, and its seeds the records of their
 * seeds. It only references them: its ids must differ from theirs, and its views and seeds are
 * of its own objects.
 *
 * What only metadata-driven can tell (a composite form that does not fit its objects, a grid
 * view naming a field that cannot be shown) is checked when the template is installed.
 */
final class PackageChecker
{
    public function __construct(
        private readonly SchemaValidator $schemas,
        private readonly FieldTypeCatalog $fieldTypes,
        private readonly SeedRecords $seedRecords,
    ) {
    }

    /**
     * @param list<Package> $required the templates the package requires, as installed or
     *                                published; each must fit its constraint. A requirement not
     *                                given is a problem: the references to it cannot be checked
     *
     * @return list<Problem> every problem found; empty when the package is valid
     */
    public function check(Package $package, array $required = []): array
    {
        $problems = $this->schemas->validate($package->manifest);
        $valid = [];
        foreach ($package->documents as $document) {
            $found = DocumentKind::Template === $document->kind
                ? [new Problem($document->path, 'kind', 'A package has one manifest, '.Format::MANIFEST.'.')]
                : $this->schemas->validate($document);
            $problems = [...$problems, ...$found];
            // Documents that break their schema are not checked further: their shape is unknown.
            if ([] === $found) {
                $valid[] = $document;
            }
        }

        $index = new PackageIndex($valid);
        $problems = [...$problems, ...$index->problems];
        [$requirements, $requiredSeeds, $found] = $this->requirements($package, $required);
        $problems = [...$problems, ...$found];
        $index->withRequired($requirements);
        $problems = [...$problems, ...self::checkOwnIds($package, $index, $requirements)];
        foreach ($valid as $document) {
            $problems = [...$problems, ...match ($document->kind) {
                DocumentKind::Object => $this->checkObject($document, $index),
                DocumentKind::View => $this->checkView($document, $index),
                DocumentKind::Form => $this->checkForm($document, $index),
                default => [],
            }];
        }
        $seeds = array_values(array_filter($valid, static fn (Document $document): bool => DocumentKind::Seed === $document->kind));

        return [...$problems, ...$this->checkSeeds($package, $seeds, $index, $requiredSeeds), ...$this->checkMigrations($package->manifest, $index)];
    }

    /**
     * The requirements of `spec.requires` against the templates given: each a valid constraint,
     * each given in a version it allows, none the template itself.
     *
     * @param list<Package> $required
     *
     * @return array{array<string, PackageIndex>, array<string, array<string, true>>, list<Problem>} the indexes of the required
     *                                                                                               templates by name, the keys of their seeds' records by object id, and the problems
     */
    private function requirements(Package $package, array $required): array
    {
        $given = [];
        foreach ($required as $requirement) {
            $given[$requirement->name()] = $requirement;
        }

        $indexes = [];
        $seeds = [];
        $problems = [];
        $requires = $package->manifest->spec['requires'] ?? null;
        foreach (\is_array($requires) ? $requires : [] as $name => $constraint) {
            $name = (string) $name;
            if ('format' === $name) {
                continue;
            }
            $at = 'spec.requires.'.$name;
            if ($name === $package->name()) {
                $problems[] = new Problem(Format::MANIFEST, $at, 'A template does not require itself.');
                continue;
            }
            try {
                $allowed = Constraint::parse(\is_scalar($constraint) ? (string) $constraint : '');
            } catch (\InvalidArgumentException $e) {
                $problems[] = new Problem(Format::MANIFEST, $at, $e->getMessage());
                continue;
            }
            $requirement = $given[$name] ?? null;
            if (null === $requirement) {
                $problems[] = new Problem(Format::MANIFEST, $at, \sprintf('%s is required: it must be installed, or given to check against.', $name));
                continue;
            }
            if (!$allowed->allows($requirement->version())) {
                $problems[] = new Problem(Format::MANIFEST, $at, \sprintf('%s %s does not fit %s.', $name, $requirement->version(), $allowed));
                continue;
            }
            $indexes[$name] = new PackageIndex($requirement->documents);
            foreach ($requirement->documentsOf(DocumentKind::Seed) as $seed) {
                $object = \is_string($seed->spec['object'] ?? null) ? $seed->spec['object'] : '';
                $key = \is_string($seed->spec['key'] ?? null) ? $seed->spec['key'] : '';
                try {
                    foreach ($this->seedRecords->of($requirement, $seed) as $record) {
                        $value = $record[$key] ?? null;
                        if (\is_scalar($value) && '' !== trim((string) $value)) {
                            $seeds[$object][trim((string) $value)] = true;
                        }
                    }
                } catch (InvalidPackage) {
                    // A required template was checked when it was published or installed.
                }
            }
        }

        return [$indexes, $seeds, $problems];
    }

    /**
     * The package's own objects, fields and forms may not take an id a required template uses:
     * a reference would name two items.
     *
     * @param array<string, PackageIndex> $requirements
     *
     * @return list<Problem>
     */
    private static function checkOwnIds(Package $package, PackageIndex $index, array $requirements): array
    {
        if ([] === $requirements) {
            return [];
        }
        $problems = [];
        foreach ($package->documents as $document) {
            foreach ($requirements as $name => $required) {
                $taken = match ($document->kind) {
                    DocumentKind::Object => \in_array($document->id, $required->ownIds()['objects'], true),
                    DocumentKind::Form => \in_array($document->id, $required->ownIds()['forms'], true),
                    default => false,
                };
                if ($taken) {
                    $problems[] = new Problem($document->path, 'id', \sprintf('Already the id of an item of %s, which this template requires.', $name));
                }
            }
            if (DocumentKind::Object !== $document->kind) {
                continue;
            }
            foreach (\is_array($document->spec['fields'] ?? null) ? $document->spec['fields'] : [] as $position => $field) {
                $id = \is_array($field) && \is_string($field['id'] ?? null) ? $field['id'] : null;
                foreach ($requirements as $name => $required) {
                    if (null !== $id && \in_array($id, $required->ownIds()['fields'], true)) {
                        $problems[] = new Problem($document->path, \sprintf('spec.fields.%d.id', $position), \sprintf('Already the id of a field of %s, which this template requires.', $name));
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * A migration step copies into a field of the template. Where it copies from may be a field
     * this very version removes: the upgrade checks it against the version installed.
     *
     * @return list<Problem>
     */
    private function checkMigrations(Document $manifest, PackageIndex $index): array
    {
        $problems = [];
        foreach (\is_array($manifest->spec['migrations'] ?? null) ? $manifest->spec['migrations'] : [] as $version => $steps) {
            foreach (\is_array($steps) ? $steps : [] as $i => $step) {
                $to = \is_array($step) && \is_string($step['to'] ?? null) ? $step['to'] : null;
                if (null !== $to && !$index->isOwnField($to)) {
                    $problems[] = new Problem($manifest->path, \sprintf('spec.migrations.%s.%d.to', $version, $i), \sprintf('No field of the template has the id "%s".', $to));
                }
            }
        }

        return $problems;
    }

    /**
     * Each seed: its object, its key (a unique field), its records (fields of the object, a
     * key each, once); then what the records point at: the keys of the records of other seeds,
     * and files of the package. Whether a value fits its field is metadata-driven's business, at install.
     *
     * @param list<Document>                     $seeds
     * @param array<string, array<string, true>> $requiredSeeds the keys of the required templates' records, by object id
     *
     * @return list<Problem>
     */
    private function checkSeeds(Package $package, array $seeds, PackageIndex $index, array $requiredSeeds = []): array
    {
        $problems = [];
        $loaded = [];
        foreach ($seeds as $seed) {
            if (isset($seed->spec['records']) === isset($seed->spec['source'])) {
                $problems[] = new Problem($seed->path, 'spec', 'A seed has its records inline (records) or in a CSV file (source): one of the two.');
                continue;
            }
            $object = \is_string($seed->spec['object'] ?? null) ? $seed->spec['object'] : '';
            if (!$index->hasOwnObject($object)) {
                $problems[] = new Problem($seed->path, 'spec.object', null !== ($owner = $index->requiredTemplateOf($object))
                    ? \sprintf('"%s" is an object of %s: a template seeds its own objects only.', $object, $owner)
                    : \sprintf('No object of the template has the id "%s".', $object));
                continue;
            }
            $fields = $index->fieldsOf($object);
            $key = \is_string($seed->spec['key'] ?? null) ? $seed->spec['key'] : '';
            if (!isset($fields[$key])) {
                $problems[] = new Problem($seed->path, 'spec.key', \sprintf('The object has no field "%s".', $key));
                continue;
            }
            if (true !== ($fields[$key]['unique'] ?? false)) {
                $problems[] = new Problem($seed->path, 'spec.key', \sprintf('"%s" is not a unique field: a key names one record.', $key));
            }
            try {
                $records = $this->seedRecords->of($package, $seed);
            } catch (InvalidPackage $e) {
                $problems = [...$problems, ...$e->problems];
                continue;
            }

            $keys = [];
            foreach ($records as $i => $record) {
                foreach (array_keys(array_diff_key($record, $fields)) as $name) {
                    $problems[] = new Problem($seed->path, self::recordAt($seed, $i, $name), \sprintf('The object has no field "%s".', $name));
                }
                $value = $record[$key] ?? null;
                $value = \is_scalar($value) ? trim((string) $value) : '';
                if ('' === $value) {
                    $problems[] = new Problem($seed->path, self::recordAt($seed, $i, $key), 'The key is missing: every record has one.');
                } elseif (isset($keys[$value])) {
                    $problems[] = new Problem($seed->path, self::recordAt($seed, $i, $key), \sprintf('Another record has the key "%s".', $value));
                }
                $keys[$value] = true;
            }
            $loaded[$object] = [$seed, $records, $keys];
        }

        foreach ($loaded as $object => [$seed, $records]) {
            $fields = $index->fieldsOf($object);
            foreach ($records as $i => $record) {
                foreach ($record as $name => $value) {
                    $field = $fields[$name] ?? null;
                    if (null === $field || null === $value || '' === $value) {
                        continue;
                    }
                    $at = self::recordAt($seed, $i, $name);
                    $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
                    $problems = [...$problems, ...match ($field['type'] ?? null) {
                        'relation', 'many_to_many' => $this->referenceProblems($seed->path, $at, $field['type'], $value, \is_string($settings['target'] ?? null) ? $settings['target'] : '', $loaded, $requiredSeeds),
                        'file' => $this->fileProblems($package, $seed->path, $at, true === ($settings['multiple'] ?? false) ? SeedRecords::list($value) : [$value]),
                        default => [],
                    }];
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string, array{Document, list<array<string, mixed>>, array<string, true>}> $loaded        the seeds, by object id
     * @param array<string, array<string, true>>                                              $requiredSeeds the keys of the required templates' records, by object id
     *
     * @return list<Problem>
     */
    private function referenceProblems(string $path, string $at, string $type, mixed $value, string $target, array $loaded, array $requiredSeeds = []): array
    {
        $known = $loaded[$target][2] ?? $requiredSeeds[$target] ?? null;
        if (null === $known) {
            return [new Problem($path, $at, \sprintf('Points at records of "%s", and the template has no seed for it.', $target))];
        }
        $problems = [];
        // A relation holds one key, written whole; a many-to-many field a list of them.
        $keys = 'many_to_many' === $type ? SeedRecords::list($value) : (\is_scalar($value) ? [trim((string) $value)] : []);
        foreach ($keys as $key) {
            if (!isset($known[$key])) {
                $problems[] = new Problem($path, $at, \sprintf('No record of the seed of "%s" has the key "%s".', $target, $key));
            }
        }

        return $problems;
    }

    /**
     * @param list<mixed> $paths
     *
     * @return list<Problem>
     */
    private function fileProblems(Package $package, string $path, string $at, array $paths): array
    {
        $problems = [];
        foreach ($paths as $file) {
            if (!\is_string($file) || !$package->has($file)) {
                $problems[] = new Problem($path, $at, \sprintf('No file "%s" in the package.', \is_scalar($file) ? (string) $file : '?'));
            }
        }

        return $problems;
    }

    /**
     * Where a record's value is: "spec.records.0.name" inline, "seeds/products.csv:2.name" in a
     * CSV file (its line, the header being line 1).
     */
    private static function recordAt(Document $seed, int $i, string $field): string
    {
        return \is_string($seed->spec['source'] ?? null)
            ? \sprintf('%s:%d.%s', $seed->spec['source'], $i + 2, $field)
            : \sprintf('spec.records.%d.%s', $i, $field);
    }

    /**
     * @return list<Problem>
     */
    private function checkObject(Document $object, PackageIndex $index): array
    {
        $problems = [];
        /** @var list<array<string, mixed>> $fields the schema guarantees a list of maps */
        $fields = $object->spec['fields'];
        foreach ($fields as $position => $field) {
            $at = \sprintf('spec.fields.%d', $position);
            $type = \is_string($field['type']) ? $field['type'] : '';
            /** @var array<string, mixed> $settings */
            $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
            foreach ($this->fieldTypes->check($type, $settings) as $key => $messages) {
                foreach ($messages as $message) {
                    $problems[] = new Problem($object->path, '' === $key ? $at.'.type' : $at.'.settings.'.$key, $message);
                }
            }
            foreach ($this->fieldTypes->references($type, $settings) as $reference) {
                $message = ReferenceKind::Object === $reference->kind
                    ? ($index->hasObject($reference->id) ? null : \sprintf('No object of the template has the id "%s".', $reference->id))
                    : $this->fieldReferenceProblem($reference->id, $reference->of, $object->id, $settings, $index);
                if (null !== $message) {
                    $problems[] = new Problem($object->path, $at.'.settings.'.$reference->setting, $message);
                }
            }
            $condition = $field['condition'] ?? null;
            if (\is_array($condition) && \is_string($condition['field'] ?? null)) {
                $message = $condition['field'] === $field['id']
                    ? 'A field cannot depend on itself.'
                    : $this->ownFieldProblem($condition['field'], $object->id, $index);
                if (null !== $message) {
                    $problems[] = new Problem($object->path, $at.'.condition.field', $message);
                }
            }
        }

        $own = [];
        if (\is_string($object->spec['label_field'] ?? null)) {
            $own['spec.label_field'] = $object->spec['label_field'];
        }
        $search = $object->spec['search'] ?? null;
        foreach (\is_array($search) && \is_array($search['fields'] ?? null) ? $search['fields'] : [] as $i => $id) {
            $own['spec.search.fields.'.$i] = $id;
        }
        foreach (\is_array($object->spec['unique_together'] ?? null) ? $object->spec['unique_together'] : [] as $i => $set) {
            foreach (\is_array($set) ? $set : [] as $j => $id) {
                $own[\sprintf('spec.unique_together.%d.%d', $i, $j)] = $id;
            }
        }
        foreach ($own as $at => $id) {
            $message = \is_string($id) ? $this->ownFieldProblem($id, $object->id, $index) : null;
            if (null !== $message) {
                $problems[] = new Problem($object->path, $at, $message);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $settings the settings of the field holding the reference
     */
    private function fieldReferenceProblem(string $id, ?FieldOwner $of, string $objectId, array $settings, PackageIndex $index): ?string
    {
        $owner = match ($of) {
            FieldOwner::Own, null => $objectId,
            FieldOwner::Target => \is_string($settings['target'] ?? null) ? $settings['target'] : null,
            FieldOwner::Through => \is_string($settings['through'] ?? null) ? $settings['through'] : null,
        };
        if (null === $owner) {
            return \sprintf('Names a field of the object in the setting "%s", which is not set.', FieldOwner::Target === $of ? 'target' : 'through');
        }

        return $this->ownFieldProblem($id, $owner, $index);
    }

    private function ownFieldProblem(string $fieldId, string $objectId, PackageIndex $index): ?string
    {
        $owner = $index->ownerOf($fieldId);
        if (null === $owner) {
            return \sprintf('No field of the template has the id "%s".', $fieldId);
        }

        return $owner === $objectId ? null : \sprintf('"%s" is a field of "%s", not of "%s".', $fieldId, $owner, $objectId);
    }

    /**
     * @return list<Problem>
     */
    private function checkView(Document $view, PackageIndex $index): array
    {
        $object = $view->spec['object'];

        if (!\is_string($object) || $index->hasOwnObject($object)) {
            return [];
        }
        $owner = $index->requiredTemplateOf($object);

        return [new Problem($view->path, 'spec.object', null !== $owner
            ? \sprintf('"%s" is an object of %s: a template adds views to its own objects only.', $object, $owner)
            : \sprintf('No object of the template has the id "%s".', $object))];
    }

    /**
     * @return list<Problem>
     */
    private function checkForm(Document $form, PackageIndex $index): array
    {
        /** @var array<string, mixed> $definition the schema guarantees a map */
        $definition = $form->spec['definition'];

        return $this->checkFormNode($form->path, 'spec.definition', $definition, $index);
    }

    /**
     * A node of a composite form: its object, and for a section the relation field (via) of
     * that object pointing at the parent's.
     *
     * @param array<string, mixed> $node
     *
     * @return list<Problem>
     */
    private function checkFormNode(string $path, string $at, array $node, PackageIndex $index): array
    {
        $object = $node['object'] ?? null;
        if (!\is_string($object) || !$index->hasObject($object)) {
            return [new Problem($path, $at.'.object', \sprintf('Must be the id of an object of the template%s.', \is_string($object) ? \sprintf(', "%s" is none', $object) : ''))];
        }

        $problems = [];
        $via = $node['via'] ?? null;
        if (\is_string($via)) {
            $message = $this->ownFieldProblem($via, $object, $index);
            if (null !== $message) {
                $problems[] = new Problem($path, $at.'.via', $message);
            }
        }
        foreach (\is_array($node['sections'] ?? null) ? $node['sections'] : [] as $i => $section) {
            if (!\is_array($section)) {
                $problems[] = new Problem($path, $at.'.sections.'.$i, 'A section is a map.');
                continue;
            }
            if (!\is_string($section['via'] ?? null)) {
                $problems[] = new Problem($path, $at.'.sections.'.$i.'.via', 'Must be the id of the relation field of the section\'s object pointing at its parent.');
            }
            /** @var array<string, mixed> $section */
            $problems = [...$problems, ...$this->checkFormNode($path, $at.'.sections.'.$i, $section, $index)];
        }

        return $problems;
    }
}
