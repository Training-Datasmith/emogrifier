<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Html_Processor;

use Pelago\Emogrifier\Utilities\Declaration_Block_Parser;
use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_split;
/**
 * This HtmlProcessor can convert style HTML attributes to the corresponding other visual HTML attributes,
 * e.g. it converts style="width: 100px" to width="100".
 *
 * It will only add attributes, but leaves the style attribute untouched.
 *
 * To trigger the conversion, call the `convertCssToVisualAttributes` method.
 */
final class Css_To_Attribute_Converter extends Abstract_Html_Processor
{
    /**
     * This multi-level array contains simple mappings of CSS properties to
     * HTML attributes. If a mapping only applies to certain HTML nodes or
     * only for certain values, the mapping is an object with an allowlist
     * of nodes and values.
     *
     * @var array<
     *        non-empty-string,
     *        array{
     *          attribute: non-empty-string,
     *          nodes?: list<non-empty-string>,
     *          values?: list<non-empty-string>
     *        }
     *      >
     */
    private $css_to_html_map = ['background-color' => ['attribute' => 'bgcolor'], 'text-align' => ['attribute' => 'align', 'nodes' => ['p', 'div', 'td', 'th'], 'values' => ['left', 'right', 'center', 'justify']], 'float' => ['attribute' => 'align', 'nodes' => ['table', 'img'], 'values' => ['left', 'right']], 'border-spacing' => ['attribute' => 'cellspacing', 'nodes' => ['table']]];
    /**
     * Maps the CSS from the style nodes to visual HTML attributes.
     *
     * @return $this
     */
    public function convert_css_to_visual_attributes(): self
    {
        foreach ($this->get_all_nodes_with_style_attribute() as $node) {
            $inline_style_declarations = Declaration_Block_Parser::parse($node->get_attribute('style'));
            $this->map_css_to_html_attributes($inline_style_declarations, $node);
        }
        return $this;
    }
    /**
     * Returns a list with all DOM nodes that have a style attribute.
     *
     * @return \DOMNodeList<\DOMElement>
     */
    private function get_all_nodes_with_style_attribute(): \Dom_Node_List
    {
        $result = $this->get_x_path()->query('//*[@style]');
        \assert($result instanceof \Dom_Node_List);
        /** @var \DOMNodeList<\DOMElement> $result */
        return $result;
    }
    /**
     * Applies `$styles` to `$node`.
     *
     * This method maps CSS styles to HTML attributes and adds those to the node.
     *
     * @param array<non-empty-string, string> $styles
     *        the new CSS styles taken from the global styles to be applied to this node
     */
    private function map_css_to_html_attributes(array $styles, \Dom_Element $node): void
    {
        foreach ($styles as $property => $value) {
            // Strip !important indicator
            $value = \trim(\str_replace('!important', '', $value));
            $this->map_css_to_html_attribute($property, $value, $node);
        }
    }
    /**
     * Tries to apply the CSS style to `$node` as an attribute.
     *
     * This method maps a CSS rule to HTML attributes and adds those to the node.
     *
     * @param non-empty-string $property
     */
    private function map_css_to_html_attribute(string $property, string $value, \Dom_Element $node): void
    {
        if (!$this->map_simple_css_property($property, $value, $node)) {
            $this->map_complex_css_property($property, $value, $node);
        }
    }
    /**
     * Looks up the CSS property in the mapping table and maps it if it matches the conditions.
     *
     * @param non-empty-string $property
     *
     * @return bool whether the property can be mapped using the simple mapping table
     */
    private function map_simple_css_property(string $property, string $value, \Dom_Element $node): bool
    {
        if (!isset($this->css_to_html_map[$property])) {
            return false;
        }
        $mapping = $this->css_to_html_map[$property];
        $nodes_match = !isset($mapping['nodes']) || \in_array($node->node_name, $mapping['nodes'], true);
        $values_match = !isset($mapping['values']) || \in_array($value, $mapping['values'], true);
        $can_be_mapped = $nodes_match && $values_match;
        if ($can_be_mapped) {
            $node->set_attribute($mapping['attribute'], $value);
        }
        return $can_be_mapped;
    }
    /**
     * Maps CSS properties that need special transformation to an HTML attribute.
     *
     * @param non-empty-string $property
     */
    private function map_complex_css_property(string $property, string $value, \Dom_Element $node): void
    {
        switch ($property) {
            case 'background':
                $this->map_background_property($node, $value);
                break;
            case 'width':
            // intentional fall-through
            case 'height':
                $this->map_width_or_height_property($node, $value, $property);
                break;
            case 'margin':
                $this->map_margin_property($node, $value);
                break;
            case 'border':
                $this->map_border_property($node, $value);
                break;
            default:
        }
    }
    /**
     * @param \DOMElement $node node to apply styles to
     * @param string $value the value of the style rule to map
     */
    private function map_background_property(\Dom_Element $node, string $value): void
    {
        // parse out the color, if any
        $styles = \explode(' ', $value, 2);
        $first = $styles[0];
        if (\is_numeric($first[0]) || \strncmp($first, 'url', 3) === 0) {
            return;
        }
        // as this is not a position or image, assume it's a color
        $node->set_attribute('bgcolor', $first);
    }
    /**
     * @param \DOMElement $node node to apply styles to
     * @param string $value the value of the style rule to map
     * @param non-empty-string $property the name of the CSS property to map
     */
    private function map_width_or_height_property(\Dom_Element $node, string $value, string $property): void
    {
        // only parse values in px and %, but not values like "auto"
        if (preg_match('/^(\d+)(\.(\d+))?(px|%)$/', $value) === 0) {
            return;
        }
        $number = preg_replace('/[^0-9.%]/', '', $value);
        $node->set_attribute($property, $number);
    }
    /**
     * @param \DOMElement $node node to apply styles to
     * @param string $value the value of the style rule to map
     */
    private function map_margin_property(\Dom_Element $node, string $value): void
    {
        if (!$this->is_table_or_image_node($node)) {
            return;
        }
        $margins = $this->parse_css_shorthand_value($value);
        if ($margins['left'] === 'auto' && $margins['right'] === 'auto') {
            $node->set_attribute('align', 'center');
        }
    }
    /**
     * @param \DOMElement $node node to apply styles to
     * @param string $value the value of the style rule to map
     */
    private function map_border_property(\Dom_Element $node, string $value): void
    {
        if (!$this->is_table_or_image_node($node)) {
            return;
        }
        if ($value === 'none' || $value === '0') {
            $node->set_attribute('border', '0');
        }
    }
    private function is_table_or_image_node(\Dom_Element $node): bool
    {
        return $node->node_name === 'table' || $node->node_name === 'img';
    }
    /**
     * Parses a shorthand CSS value and splits it into individual values.  For example: `padding: 0 auto;` - `0 auto` is
     * split into top: 0, left: auto, bottom: 0, right: auto.
     *
     * @param string $value a CSS property value with 1, 2, 3 or 4 sizes
     *
     * @return array{top: string, right: string, bottom: string, left: string}
     */
    private function parse_css_shorthand_value(string $value): array
    {
        $values = preg_split('/\s+/', $value);
        /** @var list<string> $values */
        $css = [];
        $css['top'] = $values[0];
        $css['right'] = \count($values) > 1 ? $values[1] : $css['top'];
        $css['bottom'] = \count($values) > 2 ? $values[2] : $css['top'];
        $css['left'] = \count($values) > 3 ? $values[3] : $css['right'];
        return $css;
    }
}