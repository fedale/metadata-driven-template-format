<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat;

/**
 * A version constraint of `spec.requires`, Composer's subset: `^1.2` (>=1.2.0, <2.0.0; for
 * `^0.3`, <0.4.0; for `^0.0.3`, only 0.0.3), `~1.2` (>=1.2.0, <1.3.0) and an exact `1.2.3`.
 * A missing part is 0: `^1` is `^1.0.0`.
 */
final readonly class Constraint
{
    private const string PATTERN = '#^([\^~]?)(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?(?:\.(0|[1-9]\d*))?$#';

    /**
     * @param string $lowest  the lowest version allowed
     * @param string $highest the first version not allowed; null for an exact version
     */
    private function __construct(
        public string $text,
        private string $lowest,
        private ?string $highest,
    ) {
    }

    /**
     * @throws \InvalidArgumentException not a constraint of this subset
     */
    public static function parse(string $constraint): self
    {
        $text = trim($constraint);
        if (1 !== preg_match(self::PATTERN, $text, $match)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a version constraint: ^1.2, ~1.2 or an exact 1.2.3.', $constraint));
        }
        $operator = $match[1];
        $major = (int) $match[2];
        $minor = (int) ($match[3] ?? 0);
        $patch = (int) ($match[4] ?? 0);
        $lowest = \sprintf('%d.%d.%d', $major, $minor, $patch);

        if ('' === $operator) {
            if (!isset($match[4])) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a version constraint: an exact version has three parts (1.2.3), or use ^ or ~.', $constraint));
            }

            return new self($text, $lowest, null);
        }
        $highest = match (true) {
            '~' === $operator => \sprintf('%d.%d.0', $major, $minor + 1),
            $major > 0 => \sprintf('%d.0.0', $major + 1),
            $minor > 0 => \sprintf('0.%d.0', $minor + 1),
            default => \sprintf('0.0.%d', $patch + 1),
        };

        return new self($text, $lowest, $highest);
    }

    public function allows(string $version): bool
    {
        if (1 !== preg_match(Format::VERSION_PATTERN, $version)) {
            return false;
        }
        if (null === $this->highest) {
            return $version === $this->lowest;
        }

        return version_compare($version, $this->lowest, '>=') && version_compare($version, $this->highest, '<');
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
