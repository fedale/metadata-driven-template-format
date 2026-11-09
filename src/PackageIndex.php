<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat;

/**
 * The ids and names of a package's documents, for resolving references; built from documents
 * that passed their schema. Duplicates are reported as problems.
 *
 * With the indexes of the templates the package requires (withRequired()), objects and fields
 * resolve in those too: a requiring template references their items. hasOwnObject() and
 * isOwnField() tell its own items apart.
 *
 * @internal used by PackageChecker
 */
final class PackageIndex
{
    /** @var list<Problem> */
    public readonly array $problems;

    /** @var array<string, true> object ids */
    private array $objects = [];

    /** @var array<string, string> field id => the id of its object */
    private array $fieldOwners = [];

    /** @var array<string, array<string, array<mixed>>> object id => field name => field */
    private array $fields = [];

    /** @var array<string, self> the indexes of the required templates, by template name */
    private array $required = [];

    /** @var list<string> the form ids */
    private array $forms = [];

    /**
     * @param list<Document> $documents
     */
    public function __construct(array $documents)
    {
        $problems = [];
        $ids = [];
        $names = [];
        foreach ($documents as $document) {
            // Ids are unique per kind: an object and a form may share one.
            $key = $document->kind->value.' '.$document->id;
            if (isset($ids[$key])) {
                $problems[] = new Problem($document->path, 'id', \sprintf('Already the id of %s.', $ids[$key]));
            }
            $ids[$key] ??= $document->path;

            $unique = self::uniqueName($document);
            if (null !== $unique) {
                [$name, $at] = $unique;
                if (isset($names[$name])) {
                    $problems[] = new Problem($document->path, $at, \sprintf('Same as in %s.', $names[$name]));
                }
                $names[$name] ??= $document->path;
            }

            if (DocumentKind::Form === $document->kind) {
                $this->forms[] = $document->id;
            }
            if (DocumentKind::Object === $document->kind) {
                $this->objects[$document->id] = true;
                $problems = [...$problems, ...$this->indexFields($document)];
            }
        }
        $this->problems = $problems;
    }

    /**
     * @param array<string, self> $required the indexes of the required templates, by name
     */
    public function withRequired(array $required): void
    {
        $this->required = $required;
    }

    /**
     * Whether the id names an object of the package or of a template it requires.
     */
    public function hasObject(string $id): bool
    {
        return $this->hasOwnObject($id) || null !== $this->requiredTemplateOf($id);
    }

    public function hasOwnObject(string $id): bool
    {
        return isset($this->objects[$id]);
    }

    /**
     * The required template defining the object; null for an object of the package, or none.
     */
    public function requiredTemplateOf(string $objectId): ?string
    {
        foreach ($this->required as $name => $index) {
            if ($index->hasOwnObject($objectId)) {
                return $name;
            }
        }

        return null;
    }

    public function ownerOf(string $fieldId): ?string
    {
        if (isset($this->fieldOwners[$fieldId])) {
            return $this->fieldOwners[$fieldId];
        }
        foreach ($this->required as $index) {
            if (null !== ($owner = $index->ownerOf($fieldId))) {
                return $owner;
            }
        }

        return null;
    }

    public function isOwnField(string $fieldId): bool
    {
        return isset($this->fieldOwners[$fieldId]);
    }

    /**
     * @return array<string, array<mixed>> the fields of an object, by name
     */
    public function fieldsOf(string $objectId): array
    {
        if (isset($this->fields[$objectId])) {
            return $this->fields[$objectId];
        }
        foreach ($this->required as $index) {
            if ($index->hasOwnObject($objectId)) {
                return $index->fieldsOf($objectId);
            }
        }

        return [];
    }

    /**
     * The package's own ids, by kind: objects, fields, forms.
     *
     * @return array{objects: list<string>, fields: list<string>, forms: list<string>}
     */
    public function ownIds(): array
    {
        return ['objects' => array_keys($this->objects), 'fields' => array_keys($this->fieldOwners), 'forms' => $this->forms];
    }

    /**
     * @return list<Problem>
     */
    private function indexFields(Document $object): array
    {
        $problems = [];
        $names = [];
        /** @var list<array<string, mixed>> $fields the schema guarantees a list of maps */
        $fields = $object->spec['fields'];
        foreach ($fields as $position => $field) {
            $id = \is_string($field['id']) ? $field['id'] : '';
            $name = \is_string($field['name']) ? $field['name'] : '';
            // Field ids are unique across the package: references name a field by its id alone.
            if (isset($this->fieldOwners[$id])) {
                $problems[] = new Problem($object->path, \sprintf('spec.fields.%d.id', $position), \sprintf('Already the id of a field of "%s".', $this->fieldOwners[$id]));
            }
            if (isset($names[$name])) {
                $problems[] = new Problem($object->path, \sprintf('spec.fields.%d.name', $position), 'Another field of the object has this name.');
            }
            $this->fieldOwners[$id] ??= $object->id;
            $this->fields[$object->id][$name] ??= $field;
            $names[$name] = true;
        }

        return $problems;
    }

    /**
     * What must be unique in a package beyond ids: object names, form names, a view's name per
     * object and kind, one Translations document per language, one Seed per object.
     *
     * @return array{string, string}|null the unique key, and where the document holds it
     */
    private static function uniqueName(Document $document): ?array
    {
        $spec = $document->spec;

        return match ($document->kind) {
            DocumentKind::Object, DocumentKind::Form => \is_string($spec['name'] ?? null) ? [$document->kind->value.' '.$spec['name'], 'spec.name'] : null,
            DocumentKind::View => \is_string($spec['object'] ?? null) && \is_string($spec['kind'] ?? null) && \is_string($spec['name'] ?? null)
                ? [\sprintf('View %s %s %s', $spec['object'], $spec['kind'], $spec['name']), 'spec.name']
                : null,
            DocumentKind::Translations => \is_string($spec['locale'] ?? null) ? ['Translations '.$spec['locale'], 'spec.locale'] : null,
            // One seed per object: references to its records go by its key.
            DocumentKind::Seed => \is_string($spec['object'] ?? null) ? ['Seed '.$spec['object'], 'spec.object'] : null,
            default => null,
        };
    }
}
