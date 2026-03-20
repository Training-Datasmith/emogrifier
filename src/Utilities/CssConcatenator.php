<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Utilities;

use Pelago\Emogrifier\Css\Rule_Set;
use Pelago\Emogrifier\Css\Rule_Set_List;
/**
 * Facilitates building a CSS string by appending rulesets one at a time,
 * checking whether the enclosing at-rule (if any), selectors, or declaration block
 * are the same as those from the preceding rule and combining rules in such cases.
 *
 * Example:
 *
 * ```php
 * $concatenator = new CssConcatenator();
 * $concatenator->append(['body'], 'color: blue;');
 * $concatenator->append(['body'], 'font-size: 16px;');
 * $concatenator->append(['p'], 'margin: 1em 0;');
 * $concatenator->append(['ul', 'ol'], 'margin: 1em 0;');
 * $concatenator->append(['body'], 'font-size: 14px;', '@media screen and (max-width: 400px)');
 * $concatenator->append(['ul', 'ol'], 'margin: 0.75em 0;', '@media screen and (max-width: 400px)');
 * $css = $concatenator->getCss();
 * ```
 *
 * `$css` (if unminified) would contain the following CSS:
 *
 * ```css
 * body {
 *   color: blue;
 *   font-size: 16px;
 * }
 * p, ul, ol {
 *   margin: 1em 0;
 * }
 *
 * @media screen and (max-width: 400px) {
 *   body {
 *     font-size: 14px;
 *   }
 *   ul, ol {
 *     margin: 0.75em 0;
 *   }
 * }
 * ```
 *
 * @internal
 */
final class Css_Concatenator
{
    /**
     * Each ruleset list will have a different at-rule.
     * Within each list will be rulesets with different selectors or declaration blocks.
     *
     * @var list<RuleSetList>
     */
    private $rule_set_lists = [];
    /**
     * Appends a ruleset to the CSS.
     *
     * @param non-empty-list<non-empty-string> $selectors
     *        array of selectors for the rule, e.g. `["ul", "ol", "p:first-child"]`
     * @param string $declarationBlock
     *        the property declarations, e.g. `margin-top: 0.5em; padding: 0`
     * @param string $atRule
     *        optional name and parameter of an enclosing at-rule, e.g. `@media screen and (max-width:639px)`;
     *        an empty string if the ruleset is not within an at-rule
     */
    public function append(array $selectors, string $declaration_block, string $at_rule = ''): void
    {
        $rule_set_list = $this->get_or_create_rule_set_list_to_append_to($at_rule);
        $rule_sets = $rule_set_list->get_rule_sets();
        $last_rule_set = \end($rule_sets);
        $has_same_declarations_as_last_rule = $last_rule_set instanceof Rule_Set && $declaration_block === $last_rule_set->get_declaration_block();
        if ($has_same_declarations_as_last_rule) {
            $last_rule_set->add_selectors($selectors);
        } else {
            $has_same_selectors_as_last_rule = $last_rule_set instanceof Rule_Set && $last_rule_set->has_equivalent_selectors($selectors);
            if ($has_same_selectors_as_last_rule) {
                $last_declaration_block_without_semicolon = \rtrim(\rtrim($last_rule_set->get_declaration_block()), ';');
                $last_rule_set->set_declaration_block($last_declaration_block_without_semicolon . ';' . $declaration_block);
            } else {
                $rule_set_list->append_rule_set(new Rule_Set($selectors, $declaration_block));
            }
        }
    }
    public function get_css(): string
    {
        return \implode('', \array_map([self::class, 'getRuleSetListCss'], $this->rule_set_lists));
    }
    /**
     * @param string $atRule
     *        optional name and parameter of an enclosing at-rule, e.g. `@media screen and (max-width:639px)`;
     *        an empty string if the rulesets to be appended are not within an at-rule
     */
    private function get_or_create_rule_set_list_to_append_to(string $at_rule): Rule_Set_List
    {
        $last_rule_set_list = \end($this->rule_set_lists);
        if ($last_rule_set_list instanceof Rule_Set_List && $at_rule === $last_rule_set_list->get_at_rule()) {
            return $last_rule_set_list;
        }
        $new_rule_set_list = new Rule_Set_List($at_rule);
        $this->rule_set_lists[] = $new_rule_set_list;
        return $new_rule_set_list;
    }
    private static function get_rule_set_list_css(Rule_Set_List $rule_set_list): string
    {
        $rule_sets = $rule_set_list->get_rule_sets();
        $css = \implode('', \array_map([self::class, 'getRuleSetCss'], $rule_sets));
        $at_rule = $rule_set_list->get_at_rule();
        if ($at_rule !== '') {
            return $at_rule . '{' . $css . '}';
        }
        return $css;
    }
    private static function get_rule_set_css(Rule_Set $rule_set): string
    {
        $selectors = $rule_set->get_selectors();
        $declaration_block = $rule_set->get_declaration_block();
        return \implode(',', $selectors) . '{' . $declaration_block . '}';
    }
}