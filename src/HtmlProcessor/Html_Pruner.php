<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Html_Processor;

use Pelago\Emogrifier\Css_Inliner;
use Pelago\Emogrifier\Utilities\Array_Intersector;
use function Safe\preg_match_all;
use function Safe\preg_split;
/**
 * This class can remove things from HTML.
 */
final class Html_Pruner extends Abstract_Html_Processor
{
    /**
     * We need to look for display:none, but we need to do a case-insensitive search. Since DOMDocument only
     * supports XPath 1.0, lower-case() isn't available to us. We've thus far only set attributes to lowercase,
     * not attribute values. Consequently, we need to translate() the letters that would be in 'NONE' ("NOE")
     * to lowercase.
     */
    private const DISPLAY_NONE_MATCHER = '//*[@style and contains(translate(translate(@style," ",""),"NOE","noe"),"display:none")' . ' and not(@class and contains(concat(" ", normalize-space(@class), " "), " -emogrifier-keep "))]';
    /**
     * Removes elements that have a "display: none;" style.
     *
     * @return $this
     */
    public function remove_elements_with_display_none(): self
    {
        $elements_with_style_display_none = $this->get_x_path()->query(self::DISPLAY_NONE_MATCHER);
        \assert($elements_with_style_display_none instanceof \Dom_Node_List);
        if ($elements_with_style_display_none->length === 0) {
            return $this;
        }
        foreach ($elements_with_style_display_none as $element) {
            \assert($element instanceof \Dom_Element);
            $parent_node = $element->parent_node;
            if ($parent_node instanceof \Dom_Element) {
                $parent_node->remove_child($element);
            }
        }
        return $this;
    }
    /**
     * Removes classes that are no longer required (e.g. because there are no longer any CSS rules that reference them)
     * from `class` attributes.
     *
     * Note that this does not inspect the CSS, but expects to be provided with a list of classes that are still in use.
     *
     * This method also has the (presumably beneficial) side-effect of minifying (removing superfluous whitespace from)
     * `class` attributes.
     *
     * @param array<array-key, string> $classesToKeep names of classes that should not be removed
     *
     * @return $this
     */
    public function remove_redundant_classes(array $classes_to_keep = []): self
    {
        /** @var \DOMNodeList<\DOMElement> $elementsWithClassAttribute */
        $elements_with_class_attribute = $this->get_x_path()->query('//*[@class]');
        if ($classes_to_keep !== []) {
            $this->remove_classes_from_elements($elements_with_class_attribute, $classes_to_keep);
        } else {
            // Avoid unnecessary processing if there are no classes to keep.
            $this->remove_class_attribute_from_elements($elements_with_class_attribute);
        }
        return $this;
    }
    /**
     * Removes classes from the `class` attribute of each element in `$elements`, except any in `$classesToKeep`,
     * removing the `class` attribute itself if the resultant list is empty.
     *
     * @param \DOMNodeList<\DOMElement> $elements
     * @param array<array-key, string> $classesToKeep
     */
    private function remove_classes_from_elements(\Dom_Node_List $elements, array $classes_to_keep): void
    {
        $classes_to_keep_intersector = new Array_Intersector($classes_to_keep);
        foreach ($elements as $element) {
            /** @var list<string> $elementClasses */
            $element_classes = preg_split('/\s++/', \trim($element->get_attribute('class')));
            $element_classes_to_keep = $classes_to_keep_intersector->intersect_with($element_classes);
            if ($element_classes_to_keep !== []) {
                $element->set_attribute('class', \implode(' ', $element_classes_to_keep));
            } else {
                $element->remove_attribute('class');
            }
        }
    }
    /**
     * @param \DOMNodeList<\DOMElement> $elements
     */
    private function remove_class_attribute_from_elements(\Dom_Node_List $elements): void
    {
        foreach ($elements as $element) {
            $element->remove_attribute('class');
        }
    }
    /**
     * After CSS has been inlined, there will likely be some classes in `class` attributes that are no longer referenced
     * by any remaining (uninlinable) CSS.  This method removes such classes.
     *
     * Note that it does not inspect the remaining CSS, but uses information readily available from the `CssInliner`
     * instance about the CSS rules that could not be inlined.
     *
     * @param CssInliner $cssInliner object instance that performed the CSS inlining
     *
     * @return $this
     *
     * @throws \BadMethodCallException if `inlineCss` has not first been called on `$cssInliner`
     */
    public function remove_redundant_classes_after_css_inlined(Css_Inliner $css_inliner): self
    {
        $classes_to_keep_as_keys = [];
        foreach ($css_inliner->get_matching_uninlinable_selectors() as $selector) {
            preg_match_all('/\.(-?+[_a-zA-Z][\w\-]*+)/', $selector, $matches);
            $classes_to_keep_as_keys += \array_fill_keys($matches[1], true);
        }
        $this->remove_redundant_classes(\array_keys($classes_to_keep_as_keys));
        return $this;
    }
}