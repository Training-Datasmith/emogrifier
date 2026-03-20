<?php

declare (strict_types=1);
namespace Pelago\Emogrifier;

use Pelago\Emogrifier\Css\Css_Document;
use Pelago\Emogrifier\Html_Processor\Abstract_Html_Processor;
use Pelago\Emogrifier\Utilities\Css_Concatenator;
use Pelago\Emogrifier\Utilities\Declaration_Block_Parser;
use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_replace_callback;
use function Safe\preg_split;
use Symfony\Component\Css_Selector\Css_Selector_Converter;
use Symfony\Component\Css_Selector\Exception\Parse_Exception;
/**
 * This class provides functions for converting CSS styles into inline style attributes in your HTML code.
 */
final class Css_Inliner extends Abstract_Html_Processor
{
    private const CACHE_KEY_SELECTOR = 0;
    private const CACHE_KEY_COMBINED_STYLES = 1;
    /**
     * Regular expression component matching a static pseudo class in a selector, without the preceding ":",
     * for which the applicable elements can be determined (by converting the selector to an XPath expression).
     * (Contains alternation without a group and is intended to be placed within a capturing, non-capturing or lookahead
     * group, as appropriate for the usage context.)
     */
    private const PSEUDO_CLASS_MATCHER = 'empty|(?:first|last|nth(?:-last)?+|only)-(?:child|of-type)|not\([[:ascii:]]*\)|root';
    /**
     * This regular expression component matches an `...of-type` pseudo class name, without the preceding ":".  These
     * pseudo-classes can currently online be inlined if they have an associated type in the selector expression.
     */
    private const OF_TYPE_PSEUDO_CLASS_MATCHER = '(?:first|last|nth(?:-last)?+|only)-of-type';
    /**
     * regular expression component to match a selector combinator
     */
    private const COMBINATOR_MATCHER = '(?:\s++|\s*+[>+~]\s*+)(?=[[:alpha:]_\-.#*:\[])';
    /**
     * options array key for `querySelectorAll`
     */
    private const QSA_ALWAYS_THROW_PARSE_EXCEPTION = 'alwaysThrowParseException';
    /**
     * @var array<non-empty-string, true>
     */
    private $excluded_selectors = [];
    /**
     * @var array<non-empty-string, true>
     */
    private $excluded_css_selectors = [];
    /**
     * @var array<non-empty-string, true>
     */
    private $allowed_media_types = ['all' => true, 'screen' => true, 'print' => true];
    /**
     * @var array{
     *         0: array<non-empty-string, int<0, max>>,
     *         1: array<non-empty-string, string>
     *      }
     */
    private $caches = [self::CACHE_KEY_SELECTOR => [], self::CACHE_KEY_COMBINED_STYLES => []];
    /**
     * @var CssSelectorConverter|null
     */
    private $css_selector_converter;
    /**
     * the visited nodes with the XPath paths as array keys
     *
     * @var array<non-empty-string, \DOMElement>
     */
    private $visited_nodes = [];
    /**
     * the styles to apply to the nodes with the XPath paths as array keys for the outer array
     * and the attribute names/values as key/value pairs for the inner array
     *
     * @var array<non-empty-string, array<string, string>>
     */
    private $style_attributes_for_nodes = [];
    /**
     * Determines whether the "style" attributes of tags in the the HTML passed to this class should be preserved.
     * If set to false, the value of the style attributes will be discarded.
     *
     * @var bool
     */
    private $is_inline_style_attributes_parsing_enabled = true;
    /**
     * Determines whether the `<style>` blocks in the HTML passed to this class should be parsed.
     *
     * If set to true, the `<style>` blocks will be removed from the HTML and their contents will be applied to the HTML
     * via inline styles.
     *
     * If set to false, the `<style>` blocks will be left as they are in the HTML.
     *
     * @var bool
     */
    private $is_style_blocks_parsing_enabled = true;
    /**
     * For calculating selector precedence order.
     * Keys are a regular expression part to match before a CSS name.
     * Values are a multiplier factor per match to weight specificity.
     *
     * @var array<string, int<1, max>>
     */
    private $selector_precedence_matchers = [
        // IDs: worth 10000
        '\#' => 10000,
        // classes, attributes, pseudo-classes (not pseudo-elements) except `:not`: worth 100
        '(?:\.|\[|(?<!:):(?!not\())' => 100,
        // elements (not attribute values or `:not`), pseudo-elements: worth 1
        '(?:(?<![="\':\w\-])|::)' => 1,
    ];
    /**
     * array of data describing CSS rules which apply to the document but cannot be inlined, in the format returned by
     * {@see collateCssRules}
     *
     * @var array<array-key, array{
     *          media: string,
     *          selector: non-empty-string,
     *          hasUnmatchablePseudo: bool,
     *          declarationsBlock: string,
     *          line: int<0, max>
     *      }>|null
     */
    private $matching_uninlinable_css_rules;
    /**
     * Emogrifier will throw Exceptions when it encounters an error instead of silently ignoring them.
     *
     * @var bool
     */
    private $debug = false;
    /**
     * Inlines the given CSS into the existing HTML.
     *
     * @param string $css the CSS to inline, must be UTF-8-encoded
     *
     * @return $this
     *
     * @throws ParseException in debug mode, if an invalid selector is encountered
     * @throws \RuntimeException
     *         in debug mode, if an internal PCRE error occurs
     *         or `CssSelectorConverter::toXPath` returns an invalid XPath expression
     * @throws \UnexpectedValueException
     *         if a selector query result includes a node which is not a `DOMElement`
     */
    public function inline_css(string $css = ''): self
    {
        $this->clear_all_caches();
        $this->purge_visited_nodes();
        $this->normalize_style_attributes_of_all_nodes();
        $combined_css = $css;
        // grab any existing style blocks from the HTML and append them to the existing CSS
        // (these blocks should be appended so as to have precedence over conflicting styles in the existing CSS)
        if ($this->is_style_blocks_parsing_enabled) {
            $combined_css .= $this->get_css_from_all_style_nodes();
        }
        $parsed_css = new Css_Document($combined_css, $this->debug);
        $excluded_nodes = $this->get_nodes_to_exclude();
        $css_rules = $this->collate_css_rules($parsed_css);
        foreach ($css_rules['inlinable'] as $css_rule) {
            foreach ($this->query_selector_all($css_rule['selector']) as $node) {
                if (\in_array($node, $excluded_nodes, true)) {
                    continue;
                }
                $this->copy_inlinable_css_to_style_attribute($this->ensure_node_is_element($node), $css_rule);
            }
        }
        if ($this->is_inline_style_attributes_parsing_enabled) {
            $this->fill_style_attributes_with_merged_styles();
        }
        $this->remove_important_annotation_from_all_inline_styles();
        $this->determine_matching_uninlinable_css_rules($css_rules['uninlinable']);
        $this->copy_uninlinable_css_to_style_node($parsed_css);
        return $this;
    }
    /**
     * Disables the parsing of inline styles.
     *
     * @return $this
     */
    public function disable_inline_style_attributes_parsing(): self
    {
        $this->is_inline_style_attributes_parsing_enabled = false;
        return $this;
    }
    /**
     * Disables the parsing of `<style>` blocks.
     *
     * @return $this
     */
    public function disable_style_blocks_parsing(): self
    {
        $this->is_style_blocks_parsing_enabled = false;
        return $this;
    }
    /**
     * Marks a media query type to keep.
     *
     * @param non-empty-string $mediaName the media type name, e.g., "braille"
     *
     * @return $this
     */
    public function add_allowed_media_type(string $media_name): self
    {
        $this->allowed_media_types[$media_name] = true;
        return $this;
    }
    /**
     * Drops a media query type from the allowed list.
     *
     * @param non-empty-string $mediaName the tag name, e.g., "braille"
     *
     * @return $this
     */
    public function remove_allowed_media_type(string $media_name): self
    {
        if (isset($this->allowed_media_types[$media_name])) {
            unset($this->allowed_media_types[$media_name]);
        }
        return $this;
    }
    /**
     * Adds a selector to exclude nodes from emogrification.
     *
     * Any nodes that match the selector will not have their style altered.
     *
     * @param non-empty-string $selector the selector to exclude, e.g., ".editor"
     *
     * @return $this
     */
    public function add_excluded_selector(string $selector): self
    {
        $this->excluded_selectors[$selector] = true;
        return $this;
    }
    /**
     * No longer excludes the nodes matching this selector from emogrification.
     *
     * @param non-empty-string $selector the selector to no longer exclude, e.g., ".editor"
     *
     * @return $this
     */
    public function remove_excluded_selector(string $selector): self
    {
        if (isset($this->excluded_selectors[$selector])) {
            unset($this->excluded_selectors[$selector]);
        }
        return $this;
    }
    /**
     * Adds a selector to exclude CSS selector from emogrification.
     *
     * @param non-empty-string $selector the selector to exclude, e.g., `.editor`
     *
     * @return $this
     */
    public function add_excluded_css_selector(string $selector): self
    {
        $this->excluded_css_selectors[$selector] = true;
        return $this;
    }
    /**
     * No longer excludes the CSS selector from emogrification.
     *
     * @param non-empty-string $selector the selector to no longer exclude, e.g., `.editor`
     *
     * @return $this
     */
    public function remove_excluded_css_selector(string $selector): self
    {
        if (isset($this->excluded_css_selectors[$selector])) {
            unset($this->excluded_css_selectors[$selector]);
        }
        return $this;
    }
    /**
     * Sets the debug mode.
     *
     * @param bool $debug set to true to enable debug mode
     *
     * @return $this
     */
    public function set_debug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }
    /**
     * Gets the array of selectors present in the CSS provided to `inlineCss()` for which the declarations could not be
     * applied as inline styles, but which may affect elements in the HTML.  The relevant CSS will have been placed in a
     * `<style>` element.  The selectors may include those used within `@media` rules or those involving dynamic
     * pseudo-classes (such as `:hover`) or pseudo-elements (such as `::after`).
     *
     * @return array<array-key, string>
     *
     * @throws \BadMethodCallException if `inlineCss` has not been called first
     */
    public function get_matching_uninlinable_selectors(): array
    {
        return \array_column($this->get_matching_uninlinable_css_rules(), 'selector');
    }
    /**
     * @return array<array-key, array{
     *             media: string,
     *             selector: non-empty-string,
     *             hasUnmatchablePseudo: bool,
     *             declarationsBlock: string,
     *             line: int<0, max>
     *         }>
     *
     * @throws \BadMethodCallException if `inlineCss` has not been called first
     */
    private function get_matching_uninlinable_css_rules(): array
    {
        if (!\is_array($this->matching_uninlinable_css_rules)) {
            throw new \BadMethodCallException('inlineCss must be called first', 1568385221);
        }
        return $this->matching_uninlinable_css_rules;
    }
    /**
     * Clears all caches.
     */
    private function clear_all_caches(): void
    {
        $this->caches = [self::CACHE_KEY_SELECTOR => [], self::CACHE_KEY_COMBINED_STYLES => []];
        Declaration_Block_Parser::clear_cache();
    }
    /**
     * Purges the visited nodes.
     */
    private function purge_visited_nodes(): void
    {
        $this->visited_nodes = [];
        $this->style_attributes_for_nodes = [];
    }
    /**
     * Parses the document and normalizes all existing CSS attributes.
     * This changes 'DISPLAY: none' to 'display: none'.
     * We wouldn't have to do this if DOMXPath supported XPath 2.0.
     * Also stores a reference of nodes with existing inline styles so we don't overwrite them.
     */
    private function normalize_style_attributes_of_all_nodes(): void
    {
        foreach ($this->get_all_nodes_with_style_attribute() as $node) {
            if ($this->is_inline_style_attributes_parsing_enabled) {
                $this->normalize_style_attributes($node);
            }
            // Remove style attribute in every case, so we can add them back (if inline style attributes
            // parsing is enabled) to the end of the style list, thus keeping the right priority of CSS rules;
            // else original inline style rules may remain at the beginning of the final inline style definition
            // of a node, which may give not the desired results
            $node->remove_attribute('style');
        }
    }
    /**
     * Returns a list with all DOM nodes that have a style attribute.
     *
     * @return \DOMNodeList<\DOMElement>
     *
     * @throws \RuntimeException
     */
    private function get_all_nodes_with_style_attribute(): \Dom_Node_List
    {
        $query = '//*[@style]';
        $matches = $this->get_x_path()->query($query);
        if (!$matches instanceof \Dom_Node_List) {
            throw new \RuntimeException('XPatch query failed: ' . $query, 1618577797);
        }
        /** @var \DOMNodeList<\DOMElement> $matches */
        return $matches;
    }
    /**
     * Normalizes the value of the "style" attribute and saves it.
     */
    private function normalize_style_attributes(\Dom_Element $node): void
    {
        $pattern = '/-{0,2}+[_a-zA-Z][\w\-]*+(?=:)/S';
        $callback = \Closure::from_callable([self::class, 'normalizePropertyNameCallback']);
        if (\function_exists('Safe\preg_replace_callback')) {
            $normalized_original_style = preg_replace_callback($pattern, $callback, $node->get_attribute('style'));
        } else {
            // @phpstan-ignore-next-line The safe version is only available in "thecodingmachine/safe" for PHP >= 8.1.
            $normalized_original_style = \preg_replace_callback($pattern, $callback, $node->get_attribute('style'));
            \assert(\is_string($normalized_original_style));
        }
        // In order to not overwrite existing style attributes in the HTML, we have to save the original HTML styles.
        $node_path = $node->get_node_path();
        if (\is_string($node_path) && $node_path !== '' && !isset($this->style_attributes_for_nodes[$node_path])) {
            $this->style_attributes_for_nodes[$node_path] = Declaration_Block_Parser::parse($normalized_original_style);
            $this->visited_nodes[$node_path] = $node;
        }
        $node->set_attribute('style', $normalized_original_style);
    }
    /**
     * @param array<mixed> $matches
     *        A narrower type cannot be specified because it's a callback that may be passed different types in the
     *        array, depending on the flags provided to `preg_replace_callback()` (which are not actually used),
     *        and `Safe\preg_replace_callback()` does not have type annotations to cater for this.
     */
    private static function normalize_property_name_callback(array $matches): string
    {
        \assert(\is_string($matches[0] ?? null));
        \assert($matches[0] !== '');
        return Declaration_Block_Parser::normalize_property_name($matches[0]);
    }
    /**
     * Returns CSS content.
     */
    private function get_css_from_all_style_nodes(): string
    {
        $style_nodes = $this->get_x_path()->query('//style');
        if ($style_nodes === false) {
            return '';
        }
        $css = '';
        foreach ($style_nodes as $style_node) {
            \assert($style_node instanceof \Dom_Node);
            if (\is_string($style_node->node_value)) {
                $css .= "\n\n" . $style_node->node_value;
            }
            $parent_node = $style_node->parent_node;
            if ($parent_node instanceof \Dom_Node) {
                $parent_node->remove_child($style_node);
            }
        }
        return $css;
    }
    /**
     * Find the nodes that are not to be emogrified.
     *
     * @return list<\DOMElement>
     *
     * @throws ParseException in debug mode, if an invalid selector is encountered
     * @throws \RuntimeException in debug mode, if `CssSelectorConverter::toXPath` returns an invalid XPath expression
     * @throws \UnexpectedValueException if the selector query result includes a node which is not a `DOMElement`
     */
    private function get_nodes_to_exclude(): array
    {
        $excluded_nodes = [];
        foreach (\array_keys($this->excluded_selectors) as $selector_to_exclude) {
            foreach ($this->query_selector_all($selector_to_exclude) as $node) {
                $excluded_nodes[] = $this->ensure_node_is_element($node);
            }
        }
        return $excluded_nodes;
    }
    /**
     * @param array{alwaysThrowParseException?: bool} $options
     *        This is an array of option values to control behaviour:
     *        - `QSA_ALWAYS_THROW_PARSE_EXCEPTION` - `bool` - throw any `ParseException` regardless of debug setting.
     *
     * @return \DOMNodeList<\DOMElement> the HTML elements that match the provided CSS `$selectors`
     *
     * @throws ParseException
     *         in debug mode (or with `QSA_ALWAYS_THROW_PARSE_EXCEPTION` option), if an invalid selector is encountered
     * @throws \RuntimeException in debug mode, if `CssSelectorConverter::toXPath` returns an invalid XPath expression
     */
    private function query_selector_all(string $selectors, array $options = []): \Dom_Node_List
    {
        try {
            $result = $this->get_x_path()->query($this->get_css_selector_converter()->to_x_path($selectors));
            if ($result === false) {
                throw new \RuntimeException('query failed with selector \'' . $selectors . '\'', 1726533051);
            }
            /** @var \DOMNodeList<\DOMElement> $result */
            return $result;
        } catch (Parse_Exception $exception) {
            $always_throw_parse_exception = $options[self::QSA_ALWAYS_THROW_PARSE_EXCEPTION] ?? false;
            if ($this->debug || $always_throw_parse_exception) {
                throw $exception;
            }
            $list = new \Dom_Node_List();
            /** @var \DOMNodeList<\DOMElement> $list */
            return $list;
        } catch (\RuntimeException $exception) {
            if ($this->debug) {
                throw $exception;
            }
            // `RuntimeException` indicates a bug in CssSelector so pass the message to the error handler.
            \trigger_error($exception->get_message());
            $list = new \Dom_Node_List();
            /** @var \DOMNodeList<\DOMElement> $list */
            return $list;
        }
    }
    /**
     * @throws \UnexpectedValueException if `$node` is not a `DOMElement`
     */
    private function ensure_node_is_element(\Dom_Node $node): \Dom_Element
    {
        if (!$node instanceof \Dom_Element) {
            $path = $node->get_node_path() ?? '$node';
            throw new \UnexpectedValueException($path . ' is not a DOMElement.', 1617975914);
        }
        return $node;
    }
    private function get_css_selector_converter(): Css_Selector_Converter
    {
        if (!$this->css_selector_converter instanceof Css_Selector_Converter) {
            $this->css_selector_converter = new Css_Selector_Converter();
        }
        return $this->css_selector_converter;
    }
    /**
     * Collates the individual rules from a `CssDocument` object.
     *
     * @return array<string, array<array-key, array{
     *           media: string,
     *           selector: non-empty-string,
     *           hasUnmatchablePseudo: bool,
     *           declarationsBlock: string,
     *           line: int<0, max>
     *         }>>
     *         This 2-entry array has the key "inlinable" containing rules which can be inlined as `style` attributes
     *         and the key "uninlinable" containing rules which cannot.  Each value is an array of sub-arrays with the
     *         following keys:
     *         - "media" (the media query string, e.g. "@media screen and (max-width: 480px)",
     *           or an empty string if not from a `@media` rule);
     *         - "selector" (the CSS selector, e.g., "*" or "header h1");
     *         - "hasUnmatchablePseudo" (`true` if that selector contains pseudo-elements or dynamic pseudo-classes such
     *           that the declarations cannot be applied inline);
     *         - "declarationsBlock" (the semicolon-separated CSS declarations for that selector,
     *           e.g., `color: red; height: 4px;`);
     *         - "line" (the line number, e.g. 42).
     */
    private function collate_css_rules(Css_Document $parsed_css): array
    {
        $matches = $parsed_css->get_style_rules_data(\array_keys($this->allowed_media_types));
        $css_rules = ['inlinable' => [], 'uninlinable' => []];
        foreach ($matches as $key => $css_rule) {
            if (!$css_rule->has_at_least_one_declaration()) {
                continue;
            }
            $media_query = $css_rule->get_containing_at_rule();
            $declarations_block = $css_rule->get_declarations_as_text();
            $selectors = $css_rule->get_selectors();
            // Maybe exclude CSS selectors
            if (\count($this->excluded_css_selectors) > 0) {
                // Normalize spaces, line breaks & tabs
                $selectors_normalized = \array_map(static function (string $selector): string {
                    return preg_replace('@\s++@u', ' ', $selector);
                }, $selectors);
                /** @var array<non-empty-string> $selectors */
                $selectors = \array_filter($selectors_normalized, function (string $selector): bool {
                    return !isset($this->excluded_css_selectors[$selector]);
                });
            }
            foreach ($selectors as $selector) {
                // don't process pseudo-elements and behavioral (dynamic) pseudo-classes;
                // only allow structural pseudo-classes
                $has_pseudo_element = \strpos($selector, '::') !== false;
                $has_unmatchable_pseudo = $has_pseudo_element || $this->has_unsupported_pseudo_class($selector);
                $parsed_css_rule = [
                    'media' => $media_query,
                    'selector' => $selector,
                    'hasUnmatchablePseudo' => $has_unmatchable_pseudo,
                    'declarationsBlock' => $declarations_block,
                    // keep track of where it appears in the file, since order is important
                    'line' => $key,
                ];
                $rule_type = !$css_rule->has_containing_at_rule() && !$has_unmatchable_pseudo ? 'inlinable' : 'uninlinable';
                $css_rules[$rule_type][] = $parsed_css_rule;
            }
        }
        \usort(
            $css_rules['inlinable'],
            /**
             * @param array{selector: non-empty-string, line: int<0, max>} $first
             * @param array{selector: non-empty-string, line: int<0, max>} $second
             */
            function (array $first, array $second): int {
                return $this->sort_by_selector_precedence($first, $second);
            }
        );
        return $css_rules;
    }
    /**
     * Tests if a selector contains a pseudo-class which would mean it cannot be converted to an XPath expression for
     * inlining CSS declarations.
     *
     * Any pseudo class that does not match {@see PSEUDO_CLASS_MATCHER} cannot be converted.  Additionally, `...of-type`
     * pseudo-classes cannot be converted if they are not associated with a type selector.
     */
    private function has_unsupported_pseudo_class(string $selector): bool
    {
        if (preg_match('/:(?!' . self::PSEUDO_CLASS_MATCHER . ')[\w\-]/i', $selector) !== 0) {
            return true;
        }
        if (preg_match('/:(?:' . self::OF_TYPE_PSEUDO_CLASS_MATCHER . ')/i', $selector) === 0) {
            return false;
        }
        foreach (preg_split('/' . self::COMBINATOR_MATCHER . '/', $selector) as $selector_part) {
            \assert(\is_string($selector_part));
            if ($this->selector_part_has_unsupported_of_type_pseudo_class($selector_part)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Tests if part of a selector contains an `...of-type` pseudo-class such that it cannot be converted to an XPath
     * expression.
     *
     * @param string $selectorPart part of a selector which has been split up at combinators
     *
     * @return bool `true` if the selector part does not have a type but does have an `...of-type` pseudo-class
     */
    private function selector_part_has_unsupported_of_type_pseudo_class(string $selector_part): bool
    {
        if (preg_match('/^[\w\-]/', $selector_part) !== 0) {
            return false;
        }
        return preg_match('/:(?:' . self::OF_TYPE_PSEUDO_CLASS_MATCHER . ')/i', $selector_part) !== 0;
    }
    /**
     * @param array{selector: non-empty-string, line: int<0, max>} $first
     * @param array{selector: non-empty-string, line: int<0, max>} $second
     */
    private function sort_by_selector_precedence(array $first, array $second): int
    {
        $precedence_of_first = $this->get_css_selector_precedence($first['selector']);
        $precedence_of_second = $this->get_css_selector_precedence($second['selector']);
        // We want these sorted in ascending order so selectors with lesser precedence get processed first and
        // selectors with greater precedence get sorted last.
        $precedence_for_equals = $first['line'] < $second['line'] ? -1 : 1;
        $precedence_for_not_equals = $precedence_of_first < $precedence_of_second ? -1 : 1;
        return $precedence_of_first === $precedence_of_second ? $precedence_for_equals : $precedence_for_not_equals;
    }
    /**
     * @param non-empty-string $selector
     *
     * @return int<0, max>
     */
    private function get_css_selector_precedence(string $selector): int
    {
        $selector_key = $selector;
        if (isset($this->caches[self::CACHE_KEY_SELECTOR][$selector_key])) {
            return $this->caches[self::CACHE_KEY_SELECTOR][$selector_key];
        }
        $precedence = 0;
        foreach ($this->selector_precedence_matchers as $matcher => $value) {
            if (\trim($selector) === '') {
                break;
            }
            $count = 0;
            $selector = preg_replace('/' . $matcher . '\w+/', '', $selector, -1, $count);
            $precedence += $value * $count;
            \assert($precedence >= 0);
        }
        $this->caches[self::CACHE_KEY_SELECTOR][$selector_key] = $precedence;
        return $precedence;
    }
    /**
     * Copies `$cssRule` into the style attribute of `$node`.
     *
     * Note: This method does not check whether $cssRule matches $node.
     *
     * @param array{
     *            media: string,
     *            selector: non-empty-string,
     *            hasUnmatchablePseudo: bool,
     *            declarationsBlock: string,
     *            line: int<0, max>
     *        } $cssRule
     */
    private function copy_inlinable_css_to_style_attribute(\Dom_Element $node, array $css_rule): void
    {
        $declarations_block = $css_rule['declarationsBlock'];
        $new_style_declarations = Declaration_Block_Parser::parse($declarations_block);
        if ($new_style_declarations === []) {
            return;
        }
        // if it has a style attribute, get it, process it, and append (overwrite) new stuff
        if ($node->has_attribute('style')) {
            // break it up into an associative array
            $old_style_declarations = Declaration_Block_Parser::parse($node->get_attribute('style'));
        } else {
            $old_style_declarations = [];
        }
        $node->set_attribute('style', $this->generate_style_string_from_declarations_arrays($old_style_declarations, $new_style_declarations));
    }
    /**
     * This method merges old or existing name/value array with new name/value array
     * and then generates a string of the combined style suitable for placing inline.
     * This becomes the single point for CSS string generation allowing for consistent
     * CSS output no matter where the CSS originally came from.
     *
     * @param array<string, string> $oldStyles
     * @param array<string, string> $newStyles
     *
     * @throws \UnexpectedValueException if an empty property name is encountered (which should not happen)
     */
    private function generate_style_string_from_declarations_arrays(array $old_styles, array $new_styles): string
    {
        $cache_key = \serialize([$old_styles, $new_styles]);
        \assert($cache_key !== '');
        if (isset($this->caches[self::CACHE_KEY_COMBINED_STYLES][$cache_key])) {
            return $this->caches[self::CACHE_KEY_COMBINED_STYLES][$cache_key];
        }
        // Unset the overridden styles to preserve order, important if shorthand and individual properties are mixed
        foreach ($old_styles as $attribute_name => $attribute_value) {
            if (!isset($new_styles[$attribute_name])) {
                continue;
            }
            $new_attribute_value = $new_styles[$attribute_name];
            if ($this->attribute_value_is_important($attribute_value) && !$this->attribute_value_is_important($new_attribute_value)) {
                unset($new_styles[$attribute_name]);
            } else {
                unset($old_styles[$attribute_name]);
            }
        }
        $combined_styles = \array_merge($old_styles, $new_styles);
        $style = '';
        foreach ($combined_styles as $attribute_name => $attribute_value) {
            $trimmed_attribute_name = \trim($attribute_name);
            if ($trimmed_attribute_name === '') {
                throw new \UnexpectedValueException('An empty property name was encountered.', 1727046078);
            }
            $property_name = Declaration_Block_Parser::normalize_property_name($trimmed_attribute_name);
            $property_value = \trim($attribute_value);
            $style .= $property_name . ': ' . $property_value . '; ';
        }
        $trimmed_style = \rtrim($style);
        $this->caches[self::CACHE_KEY_COMBINED_STYLES][$cache_key] = $trimmed_style;
        return $trimmed_style;
    }
    /**
     * Checks whether `$attributeValue` is marked as `!important`.
     */
    private function attribute_value_is_important(string $attribute_value): bool
    {
        return preg_match('/!\s*+important$/i', $attribute_value) !== 0;
    }
    /**
     * Merges styles from styles attributes and style nodes and applies them to the attribute nodes
     */
    private function fill_style_attributes_with_merged_styles(): void
    {
        foreach ($this->style_attributes_for_nodes as $node_path => $style_attributes_for_node) {
            $node = $this->visited_nodes[$node_path];
            $current_style_attributes = Declaration_Block_Parser::parse($node->get_attribute('style'));
            $node->set_attribute('style', $this->generate_style_string_from_declarations_arrays($current_style_attributes, $style_attributes_for_node));
        }
    }
    /**
     * Searches for all nodes with a style attribute and removes the "!important" annotations out of
     * the inline style declarations, eventually by rearranging declarations.
     *
     * @throws \RuntimeException
     */
    private function remove_important_annotation_from_all_inline_styles(): void
    {
        foreach ($this->get_all_nodes_with_style_attribute() as $node) {
            $this->remove_important_annotation_from_node_inline_style($node);
        }
    }
    /**
     * Removes the "!important" annotations out of the inline style declarations,
     * eventually by rearranging declarations.
     * Rearranging needed when !important shorthand properties are followed by some of their
     * not !important expanded-version properties.
     * For example "font: 12px serif !important; font-size: 13px;" must be reordered
     * to "font-size: 13px; font: 12px serif;" in order to remain correct.
     *
     * @throws \RuntimeException
     */
    private function remove_important_annotation_from_node_inline_style(\Dom_Element $node): void
    {
        $style = $node->get_attribute('style');
        $inline_style_declarations = Declaration_Block_Parser::parse((bool) $style ? $style : '');
        /** @var array<string, string> $regularStyleDeclarations */
        $regular_style_declarations = [];
        /** @var array<string, string> $importantStyleDeclarations */
        $important_style_declarations = [];
        foreach ($inline_style_declarations as $property => $value) {
            if ($this->attribute_value_is_important($value)) {
                $declaration = preg_replace('/\s*+!\s*+important$/i', '', $value);
                $important_style_declarations[$property] = $declaration;
            } else {
                $regular_style_declarations[$property] = $value;
            }
        }
        $inline_style_declarations_in_new_order = \array_merge($regular_style_declarations, $important_style_declarations);
        $node->set_attribute('style', $this->generate_style_string_from_single_declarations_array($inline_style_declarations_in_new_order));
    }
    /**
     * Generates a CSS style string suitable to be used inline from the $styleDeclarations property => value array.
     *
     * @param array<string, string> $styleDeclarations
     */
    private function generate_style_string_from_single_declarations_array(array $style_declarations): string
    {
        return $this->generate_style_string_from_declarations_arrays([], $style_declarations);
    }
    /**
     * Determines which of `$cssRules` actually apply to `$this->domDocument`, and sets them in
     * `$this->matchingUninlinableCssRules`.
     *
     * @param array<array-key, array{
     *            media: string,
     *            selector: non-empty-string,
     *            hasUnmatchablePseudo: bool,
     *            declarationsBlock: string,
     *            line: int<0, max>
     *        }> $cssRules
     *        the "uninlinable" array of CSS rules returned by `collateCssRules`
     */
    private function determine_matching_uninlinable_css_rules(array $css_rules): void
    {
        $this->matching_uninlinable_css_rules = \array_filter($css_rules, function (array $css_rule): bool {
            return $this->exists_match_for_selector_in_css_rule($css_rule);
        });
    }
    /**
     * Checks whether there is at least one matching element for the CSS selector contained in the `selector` element
     * of the provided CSS rule.
     *
     * Any dynamic pseudo-classes will be assumed to apply. If the selector matches a pseudo-element,
     * it will test for a match with its originating element.
     *
     * @param array{
     *            media: string,
     *            selector: non-empty-string,
     *            hasUnmatchablePseudo: bool,
     *            declarationsBlock: string,
     *            line: int<0, max>
     *        } $cssRule
     *
     * @throws ParseException
     */
    private function exists_match_for_selector_in_css_rule(array $css_rule): bool
    {
        $selector = $css_rule['selector'];
        if ($css_rule['hasUnmatchablePseudo']) {
            $selector = $this->remove_unmatchable_pseudo_components($selector);
        }
        return $this->exists_match_for_css_selector($selector);
    }
    /**
     * Checks whether there is at least one matching element for $cssSelector.
     * When not in debug mode, it returns true also for invalid selectors (because they may be valid,
     * just not implemented/recognized yet by Emogrifier).
     *
     * @throws ParseException in debug mode, if an invalid selector is encountered
     * @throws \RuntimeException in debug mode, if `CssSelectorConverter::toXPath` returns an invalid XPath expression
     */
    private function exists_match_for_css_selector(string $css_selector): bool
    {
        try {
            $nodes_matching_selector = $this->query_selector_all($css_selector, [self::QSA_ALWAYS_THROW_PARSE_EXCEPTION => true]);
        } catch (Parse_Exception $e) {
            if ($this->debug) {
                throw $e;
            }
            return true;
        }
        return $nodes_matching_selector->length !== 0;
    }
    /**
     * Removes pseudo-elements and dynamic pseudo-classes from a CSS selector, replacing them with "*" if necessary.
     * If such a pseudo-component is within the argument of `:not`, the entire `:not` component is removed or replaced.
     *
     * @return string
     *         selector which will match the relevant DOM elements if the pseudo-classes are assumed to apply, or in the
     *         case of pseudo-elements will match their originating element
     */
    private function remove_unmatchable_pseudo_components(string $selector): string
    {
        // The regex allows nested brackets via `(?2)`.
        // A space is temporarily prepended because the callback can't determine if the match was at the very start.
        $pattern = '/([\s>+~]?+):not(\([^()]*+(?:(?2)[^()]*+)*+\))/i';
        $callback = \Closure::from_callable([$this, 'replaceUnmatchableNotComponent']);
        if (\function_exists('Safe\preg_replace_callback')) {
            $untrimmed_selector_without_nots = preg_replace_callback($pattern, $callback, ' ' . $selector);
        } else {
            // @phpstan-ignore-next-line The safe version is only available in "thecodingmachine/safe" for PHP >= 8.1.
            $untrimmed_selector_without_nots = \preg_replace_callback($pattern, $callback, ' ' . $selector);
            \assert(\is_string($untrimmed_selector_without_nots));
        }
        $selector_without_nots = \ltrim($untrimmed_selector_without_nots);
        $selector_without_unmatchable_pseudo_components = $this->remove_selector_components(':(?!' . self::PSEUDO_CLASS_MATCHER . '):?+[\w\-]++(?:\([^\)]*+\))?+', $selector_without_nots);
        if (preg_match('/:(?:' . self::OF_TYPE_PSEUDO_CLASS_MATCHER . ')/i', $selector_without_unmatchable_pseudo_components) === 0) {
            return $selector_without_unmatchable_pseudo_components;
        }
        $selector_parts = preg_split('/(' . self::COMBINATOR_MATCHER . ')/', $selector_without_unmatchable_pseudo_components, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        /** @var list<string> $selectorParts */
        return \implode('', \array_map(function (string $selector_part): string {
            return $this->remove_unsupported_of_type_pseudo_classes($selector_part);
        }, $selector_parts));
    }
    /**
     * Helps `removeUnmatchablePseudoComponents()` replace or remove a selector `:not(...)` component if its argument
     * contains pseudo-elements or dynamic pseudo-classes.
     *
     * @param array<mixed> $matches
     *        This is an array of elements matched by the regular expression.
     *        A narrower type cannot be specified because it's a callback that may be passed different types in the
     *        array, depending on the flags provided to `preg_replace_callback()` (which are not actually used),
     *        and `Safe\preg_replace_callback()` does not have type annotations to cater for this.
     *
     * @return string
     *         the full match if there were no unmatchable pseudo components within; otherwise, any preceding combinator
     *         followed by "*", or an empty string if there was no preceding combinator
     */
    private function replace_unmatchable_not_component(array $matches): string
    {
        [$not_component_with_any_preceding_combinator, $any_preceding_combinator, $not_argument_in_brackets] = $matches;
        \assert(\is_string($not_component_with_any_preceding_combinator));
        \assert(\is_string($any_preceding_combinator));
        \assert(\is_string($not_argument_in_brackets));
        if ($this->has_unsupported_pseudo_class($not_argument_in_brackets)) {
            return $any_preceding_combinator !== '' ? $any_preceding_combinator . '*' : '';
        }
        return $not_component_with_any_preceding_combinator;
    }
    /**
     * Removes components from a CSS selector, replacing them with "*" if necessary.
     *
     * @param string $matcher regular expression part to match the components to remove
     *
     * @return string
     *         selector which will match the relevant DOM elements if the removed components are assumed to apply (or in
     *         the case of pseudo-elements will match their originating element)
     */
    private function remove_selector_components(string $matcher, string $selector): string
    {
        return preg_replace(['/([\s>+~]|^)' . $matcher . '/i', '/' . $matcher . '/i'], ['$1*', ''], $selector);
    }
    /**
     * Removes any `...-of-type` pseudo-classes from part of a CSS selector, if it does not have a type, replacing them
     * with "*" if necessary.
     *
     * @param string $selectorPart part of a selector which has been split up at combinators
     *
     * @return string
     *         selector part which will match the relevant DOM elements if the pseudo-classes are assumed to apply
     */
    private function remove_unsupported_of_type_pseudo_classes(string $selector_part): string
    {
        if (!$this->selector_part_has_unsupported_of_type_pseudo_class($selector_part)) {
            return $selector_part;
        }
        return $this->remove_selector_components(':(?:' . self::OF_TYPE_PSEUDO_CLASS_MATCHER . ')(?:\([^\)]*+\))?+', $selector_part);
    }
    /**
     * Applies `$this->matchingUninlinableCssRules` to `$this->domDocument` by placing them as CSS in a `<style>`
     * element.
     * If there are no uninlinable CSS rules to copy there, a `<style>` element will be created containing only the
     * applicable at-rules from `$parsedCss`.
     * If there are none of either, an empty `<style>` element will not be created.
     *
     * @param CssDocument $parsedCss
     *        This may contain various at-rules whose content `CssInliner` does not currently attempt to inline or
     *        process in any other way, such as `@import`, `@font-face`, `@keyframes`, etc., and which should precede
     *        the processed but found-to-be-uninlinable CSS placed in the `<style>` element.
     *        Note that `CssInliner` processes `@media` rules so that they can be ordered correctly with respect to
     *        other uninlinable rules; these will not be duplicated from `$parsedCss`.
     */
    private function copy_uninlinable_css_to_style_node(Css_Document $parsed_css): void
    {
        $css = $parsed_css->render_non_conditional_at_rules();
        // avoid including unneeded class dependency if there are no rules
        if ($this->get_matching_uninlinable_css_rules() !== []) {
            $css_concatenator = new Css_Concatenator();
            foreach ($this->get_matching_uninlinable_css_rules() as $css_rule) {
                $css_concatenator->append([$css_rule['selector']], $css_rule['declarationsBlock'], $css_rule['media']);
            }
            $css .= $css_concatenator->get_css();
        }
        // avoid adding empty style element
        if ($css !== '') {
            $this->add_style_element_to_document($css);
        }
    }
    /**
     * Adds a style element with `$css` to `$this->domDocument`.
     *
     * This method is protected to allow overriding.
     *
     * @see https://github.com/MyIntervals/emogrifier/issues/103
     */
    protected function add_style_element_to_document(string $css): void
    {
        $dom_document = $this->get_dom_document();
        $style_element = $dom_document->create_element('style', $css);
        $style_attribute = $dom_document->create_attribute('type');
        $style_attribute->value = 'text/css';
        $style_element->append_child($style_attribute);
        $head_element = $this->get_head_element();
        $head_element->append_child($style_element);
    }
    /**
     * Returns the `HEAD` element.
     *
     * This method assumes that there always is a HEAD element.
     *
     * @throws \UnexpectedValueException
     */
    private function get_head_element(): \Dom_Element
    {
        $node = $this->get_dom_document()->get_elements_by_tag_name('head')->item(0);
        if (!$node instanceof \Dom_Element) {
            throw new \UnexpectedValueException('There is no HEAD element. This should never happen.', 1617923227);
        }
        return $node;
    }
}