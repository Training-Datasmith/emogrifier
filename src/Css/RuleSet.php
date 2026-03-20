<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Css;

/**
 * This class represents a CSS rule set as defined in the specs: https://drafts.csswg.org/css2/#rule-sets
 *
 * @internal
 */
final class Rule_Set
{
    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private $selectors_as_keys;
    /**
     * @var string
     */
    private $declaration_block;
    /**
     * @param list<non-empty-string> $selectors
     */
    public function __construct(array $selectors, string $declaration_block)
    {
        $this->selectors_as_keys = \array_flip($selectors);
        $this->declaration_block = $declaration_block;
    }
    /**
     * @return list<non-empty-string>
     */
    public function get_selectors(): array
    {
        return \array_keys($this->selectors_as_keys);
    }
    /**
     * @param list<non-empty-string> $selectors
     */
    public function add_selectors(array $selectors): void
    {
        $this->selectors_as_keys += \array_flip($selectors);
    }
    /**
     * Tests if a set of selectors is equivalent to those currently represented by the object
     * (i.e. the same selectors, possibly in a different order).
     *
     * @param list<non-empty-string> $selectors
     */
    public function has_equivalent_selectors(array $selectors): bool
    {
        $selectors_as_keys = \array_flip($selectors);
        return \count($this->selectors_as_keys) === \count($selectors_as_keys) && \count($this->selectors_as_keys) === \count($this->selectors_as_keys + $selectors_as_keys);
    }
    public function get_declaration_block(): string
    {
        return $this->declaration_block;
    }
    public function set_declaration_block(string $declaration_block): void
    {
        $this->declaration_block = $declaration_block;
    }
}