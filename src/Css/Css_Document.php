<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Css;

use Sabberworm\CSS\Css_List\At_Rule_Block_List as CssAtRuleBlockList;
use Sabberworm\CSS\Css_List\Document as SabberwormCssDocument;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Property\At_Rule as CssAtRule;
use Sabberworm\CSS\Property\Charset as CssCharset;
use Sabberworm\CSS\Property\Import as CssImport;
use Sabberworm\CSS\Renderable as CssRenderable;
use Sabberworm\CSS\Rule_Set\Declaration_Block as CssDeclarationBlock;
use Sabberworm\CSS\Rule_Set\Rule_Set as CssRuleSet;
use Sabberworm\CSS\Settings as ParserSettings;
use function Safe\preg_match;
/**
 * Parses and stores a CSS document from a string of CSS, and provides methods to obtain the CSS in parts or as data
 * structures.
 *
 * @internal
 */
final class Css_Document
{
    /**
     * @var SabberwormCssDocument
     */
    private $sabberworm_css_document;
    /**
     * `@import` rules must precede all other types of rules, except `@charset` rules.  This property is used while
     * rendering at-rules to enforce that.
     *
     * @var bool
     */
    private $is_import_rule_allowed = true;
    /**
     * @param bool $debug
     *        If this is `true`, an exception will be thrown if invalid CSS is encountered.
     *        Otherwise the parser will try to do the best it can.
     */
    public function __construct(string $css, bool $debug)
    {
        // CSS Parser currently throws exception with nested at-rules (like `@media`) in strict parsing mode
        $parser_settings = Parser_Settings::create()->with_lenient_parsing(!$debug || $this->has_nested_at_rule($css));
        // CSS Parser currently throws exception with non-empty whitespace-only CSS in strict parsing mode, so `trim()`
        // @see https://github.com/sabberworm/PHP-CSS-Parser/issues/349
        $this->sabberworm_css_document = (new Css_Parser(\trim($css), $parser_settings))->parse();
    }
    /**
     * Tests if a string of CSS appears to contain an at-rule with nested rules
     * (`@media`, `@supports`, `@keyframes`, `@document`,
     * the latter two additionally with vendor prefixes that may commonly be used).
     *
     * @see https://github.com/sabberworm/PHP-CSS-Parser/issues/127
     */
    private function has_nested_at_rule(string $css): bool
    {
        return preg_match('/@(?:media|supports|(?:-webkit-|-moz-|-ms-|-o-)?+(keyframes|document))\b/', $css) !== 0;
    }
    /**
     * Collates the media query, selectors and declarations for individual rules from the parsed CSS, in order.
     *
     * @param list<non-empty-string> $allowedMediaTypes
     *
     * @return list<StyleRule>
     */
    public function get_style_rules_data(array $allowed_media_types): array
    {
        $rule_matches = [];
        foreach ($this->sabberworm_css_document->get_contents() as $rule) {
            if ($rule instanceof Css_At_Rule_Block_List) {
                $containing_at_rule = $this->get_filtered_at_identifier_and_rule($rule, $allowed_media_types);
                if (\is_string($containing_at_rule)) {
                    foreach ($rule->get_contents() as $nested_rule) {
                        if ($nested_rule instanceof Css_Declaration_Block) {
                            $rule_matches[] = new Style_Rule($nested_rule, $containing_at_rule);
                        }
                    }
                }
            } elseif ($rule instanceof Css_Declaration_Block) {
                $rule_matches[] = new Style_Rule($rule);
            }
        }
        return $rule_matches;
    }
    /**
     * Renders at-rules from the parsed CSS that are valid and not conditional group rules (i.e. not rules such as
     * `@media` which contain style rules whose data is returned by {@see getStyleRulesData}).  Also does not render
     * `@charset` rules; these are discarded (only UTF-8 is supported).
     */
    public function render_non_conditional_at_rules(): string
    {
        $this->is_import_rule_allowed = true;
        $css_contents = $this->sabberworm_css_document->get_contents();
        $at_rules = \array_filter($css_contents, [$this, 'isValidAtRuleToRender']);
        if ($at_rules === []) {
            return '';
        }
        $at_rules_document = new Sabberworm_Css_Document();
        $at_rules_document->set_contents($at_rules);
        return $at_rules_document->render();
    }
    /**
     * @param list<non-empty-string> $allowedMediaTypes
     *
     * @return string|null
     *         If the nested at-rule is supported, it's opening declaration (e.g. "@media (max-width: 768px)") is
     *         returned; otherwise the return value is null.
     */
    private function get_filtered_at_identifier_and_rule(Css_At_Rule_Block_List $rule, array $allowed_media_types): ?string
    {
        if ($rule->at_rule_name() !== 'media') {
            return null;
        }
        $media_query_list = $rule->at_rule_args();
        [$media_type] = \explode('(', $media_query_list, 2);
        if (\trim($media_type) !== '') {
            $escaped_allowed_media_types = \array_map(static function (string $allowed_media_type): string {
                return \preg_quote($allowed_media_type, '/');
            }, $allowed_media_types);
            $media_types_matcher = \implode('|', $escaped_allowed_media_types);
            $is_allowed = preg_match('/^\s*+(?:only\s++)?+(?:' . $media_types_matcher . ')/i', $media_type) !== 0;
        } else {
            $is_allowed = true;
        }
        return $is_allowed ? '@media ' . $media_query_list : null;
    }
    /**
     * Tests if a CSS rule is an at-rule that should be passed though and copied to a `<style>` element unmodified:
     * - `@charset` rules are discarded - only UTF-8 is supported - `false` is returned;
     * - `@import` rules are passed through only if they satisfy the specification ("user agents must ignore any
     *   '@import' rule that occurs inside a block or after any non-ignored statement other than an '@charset' or an
     *   '@import' rule");
     * - `@media` rules are processed separately to see if their nested rules apply - `false` is returned;
     * - `@font-face` rules are checked for validity - they must contain both a `src` and `font-family` property;
     * - other at-rules are assumed to be valid and treated as a black box - `true` is returned.
     */
    private function is_valid_at_rule_to_render(Css_Renderable $rule): bool
    {
        if ($rule instanceof Css_Charset) {
            return false;
        }
        if ($rule instanceof Css_Import) {
            return $this->is_import_rule_allowed;
        }
        $this->is_import_rule_allowed = false;
        if (!$rule instanceof Css_At_Rule) {
            return false;
        }
        switch ($rule->at_rule_name()) {
            case 'media':
                $result = false;
                break;
            case 'font-face':
                $result = $rule instanceof Css_Rule_Set && $rule->get_declarations('font-family') !== [] && $rule->get_declarations('src') !== [];
                break;
            default:
                $result = true;
        }
        return $result;
    }
}