<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Html_Processor;

use Pelago\Emogrifier\Utilities\Declaration_Block_Parser;
use function Safe\preg_match;
use function Safe\preg_replace_callback;
/**
 * This class can evaluate CSS custom properties that are defined and used in inline style attributes.
 */
final class Css_Variable_Evaluator extends Abstract_Html_Processor
{
    /**
     * temporary collection used by {@see replaceVariablesInDeclarations} and callee methods
     *
     * @var array<non-empty-string, string>
     */
    private $current_variable_definitions = [];
    /**
     * Replaces all CSS custom property references in inline style attributes with their corresponding values where
     * defined in inline style attributes (either from the element itself or the nearest ancestor).
     *
     * @return $this
     *
     * @throws \UnexpectedValueException
     */
    public function evaluate_variables(): self
    {
        /**
         * @var list<array{element: \DOMElement, ancestorDefinitions: array<non-empty-string, string>}>
         *      $elementsToEvaluate
         */
        $elements_to_evaluate = [['element' => $this->get_html_element(), 'ancestorDefinitions' => []]];
        while (($current_element_data = \array_pop($elements_to_evaluate)) !== null) {
            $current_element = $current_element_data['element'];
            $current_ancestor_definitions = $current_element_data['ancestorDefinitions'];
            $style = $current_element->get_attribute('style');
            // Avoid parsing declarations if none use or define a variable
            if (preg_match('/(?<![\w\-])--[\w\-]/', $style) !== 0) {
                $declarations = Declaration_Block_Parser::parse($style);
                $variable_definitions = $this->get_variable_definitions_from_declarations($declarations) + $current_ancestor_definitions;
                $this->current_variable_definitions = $variable_definitions;
                $new_declarations = $this->replace_variables_in_declarations($declarations);
                if ($new_declarations !== null) {
                    $current_element->set_attribute('style', $this->get_declarations_as_string($new_declarations));
                }
            } else {
                $variable_definitions = $current_ancestor_definitions;
            }
            foreach ($current_element->child_nodes as $child) {
                if ($child instanceof \Dom_Element) {
                    $elements_to_evaluate[] = ['element' => $child, 'ancestorDefinitions' => $variable_definitions];
                }
            }
        }
        return $this;
    }
    /**
     * @param array<non-empty-string, string> $declarations
     *
     * @return array<non-empty-string, string>
     */
    private function get_variable_definitions_from_declarations(array $declarations): array
    {
        return \array_filter($declarations, static function (string $key): bool {
            return \substr($key, 0, 2) === '--';
        }, ARRAY_FILTER_USE_KEY);
    }
    /**
     * Callback function for {@see replaceVariablesInPropertyValue} performing regular expression replacement.
     *
     * @param array<mixed> $matches
     *        This will actaully be `non-empty-list<string>` but the type annotation cannot be any tighter due to use of
     *        `Safe\preg_replace_callback()` which does not precisely type the `$callback` parameter.
     */
    private function get_property_value_replacement(array $matches): string
    {
        \assert(\is_string($matches[1] ?? null));
        $variable_name = $matches[1];
        if (isset($this->current_variable_definitions[$variable_name])) {
            $variable_value = $this->current_variable_definitions[$variable_name];
        } else {
            $fallback_value_separator = $matches[2] ?? '';
            if ($fallback_value_separator !== '') {
                \assert(\is_string($matches[3] ?? null));
                $fallback_value = $matches[3];
                // The fallback value may use other CSS variables, so recurse
                $variable_value = $this->replace_variables_in_property_value($fallback_value);
            } else {
                \assert(\is_string($matches[0] ?? null));
                $variable_value = $matches[0];
            }
        }
        return $variable_value;
    }
    /**
     * Regular expression based on {@see https://stackoverflow.com/a/54143883/2511031 a StackOverflow answer}.
     */
    private function replace_variables_in_property_value(string $property_value): string
    {
        $pattern = '/
                var\(
                    \s*+
                    # capture variable name including `--` prefix
                    (
                        --[^\s\),]++
                    )
                    \s*+
                    # capture optional fallback value
                    (?:
                        # capture separator to confirm there is a fallback value
                        (,)\s*
                        # begin capture with named group that can be used recursively
                        (?<recursable>
                            # begin named group to match sequence without parentheses, except in strings
                            (?<noparentheses>
                                # repeated zero or more times:
                                (?:
                                    # sequence without parentheses or quotes
                                    [^\(\)\'"]++
                                    |
                                    # string in double quotes
                                    "(?>[^"\\\\]++|\\\\.)*"
                                    |
                                    # string in single quotes
                                    \'(?>[^\'\\\\]++|\\\\.)*\'
                                )*+
                            )
                            # repeated zero or more times:
                            (?:
                                # sequence in parentheses
                                \(
                                    # using the named recursable pattern
                                    (?&recursable)
                                \)
                                # sequence without parentheses, except in strings
                                (?&noparentheses)
                            )*+
                        )
                    )?+
                \)
            /x';
        $callable = \Closure::from_callable([$this, 'getPropertyValueReplacement']);
        if (\function_exists('Safe\preg_replace_callback')) {
            $result = preg_replace_callback($pattern, $callable, $property_value);
        } else {
            // @phpstan-ignore-next-line The safe version is only available in "thecodingmachine/safe" for PHP >= 8.1.
            $result = \preg_replace_callback($pattern, $callable, $property_value);
        }
        \assert(\is_string($result));
        return $result;
    }
    /**
     * @param array<non-empty-string, string> $declarations
     *
     * @return array<non-empty-string, string>|null `null` is returned if no substitutions were made.
     */
    private function replace_variables_in_declarations(array $declarations): ?array
    {
        $substitutions_made = false;
        $result = \array_map(function (string $property_value) use (&$substitutions_made): string {
            $new_property_value = $this->replace_variables_in_property_value($property_value);
            if ($new_property_value !== $property_value) {
                $substitutions_made = true;
            }
            return $new_property_value;
        }, $declarations);
        return $substitutions_made ? $result : null;
    }
    /**
     * @param array<non-empty-string, string> $declarations
     */
    private function get_declarations_as_string(array $declarations): string
    {
        $declaration_strings = \array_map(static function (string $key, string $value): string {
            return $key . ': ' . $value;
        }, \array_keys($declarations), \array_values($declarations));
        return \implode('; ', $declaration_strings) . ';';
    }
}