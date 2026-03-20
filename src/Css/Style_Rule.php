<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Css;

use Sabberworm\CSS\Output_Format;
use Sabberworm\CSS\Property\Selector;
use Sabberworm\CSS\Rule_Set\Declaration_Block;
/**
 * This class represents a CSS style rule, including selectors, a declaration block, and an optional containing at-rule.
 *
 * @internal
 */
final class Style_Rule
{
    /**
     * @var DeclarationBlock
     */
    private $declaration_block;
    /**
     * @var string
     */
    private $containing_at_rule;
    /**
     * @param string $containingAtRule e.g. `@media screen and (max-width: 480px)`
     */
    public function __construct(Declaration_Block $declaration_block, string $containing_at_rule = '')
    {
        $this->declaration_block = $declaration_block;
        $this->containing_at_rule = \trim($containing_at_rule);
    }
    /**
     * @return list<non-empty-string> the selectors, e.g. `["h1", "p"]`
     */
    public function get_selectors(): array
    {
        $selectors = $this->declaration_block->get_selectors();
        return \array_map(static function (Selector $selector): string {
            $rendered_selector = $selector->render(Output_Format::create_compact());
            \assert($rendered_selector !== '');
            return $rendered_selector;
        }, $selectors);
    }
    /**
     * @return string the CSS declarations, separated and followed by a semicolon, e.g., `color: red; height: 4px;`
     */
    public function get_declarations_as_text(): string
    {
        $declarations = $this->declaration_block->get_declarations();
        $rendered_declarations = [];
        $output_format = Output_Format::create();
        foreach ($declarations as $declaration) {
            $rendered_declarations[] = $declaration->render($output_format);
        }
        return \implode(' ', $rendered_declarations);
    }
    /**
     * Checks whether the declaration block has at least one declaration.
     */
    public function has_at_least_one_declaration(): bool
    {
        return $this->declaration_block->get_declarations() !== [];
    }
    /**
     * @return string e.g. `@media screen and (max-width: 480px)`, or an empty string
     */
    public function get_containing_at_rule(): string
    {
        return $this->containing_at_rule;
    }
    /**
     * Checks whether the containing at-rule is non-empty and has any non-whitespace characters.
     */
    public function has_containing_at_rule(): bool
    {
        return $this->get_containing_at_rule() !== '';
    }
}