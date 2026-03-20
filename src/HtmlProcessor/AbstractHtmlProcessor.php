<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Html_Processor;

use function Safe\preg_match;
use function Safe\preg_replace;
/**
 * Base class for HTML processor that e.g., can remove, add or modify nodes or attributes.
 *
 * The "vanilla" subclass is the HtmlNormalizer.
 */
abstract class Abstract_Html_Processor
{
    protected const DEFAULT_DOCUMENT_TYPE = '<!DOCTYPE html>';
    protected const CONTENT_TYPE_META_TAG = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    /**
     * Regular expression part to match tag names that PHP's DOMDocument implementation is not
     * aware are self-closing. These are mostly HTML5 elements, but for completeness `<command>` (obsolete) and
     * `<keygen>` (deprecated) are also included.
     *
     * @see https://bugs.php.net/bug.php?id=73175
     */
    protected const PHP_UNRECOGNIZED_VOID_TAGNAME_MATCHER = '(?:command|embed|keygen|source|track|wbr)';
    /**
     * Regular expression part to match tag names that may appear before the start of the `<body>` element.  A start tag
     * for any other element would implicitly start the `<body>` element due to tag omission rules.
     */
    protected const TAGNAME_ALLOWED_BEFORE_BODY_MATCHER = '(?:html|head|base|command|link|meta|noscript|script|style|template|title)';
    /**
     * regular expression pattern to match an HTML comment, including delimiters and modifiers
     */
    protected const HTML_COMMENT_PATTERN = '/<!--[^-]*+(?:-(?!->)[^-]*+)*+(?:-->|$)/';
    /**
     * regular expression pattern to match an HTML `<template>` element, including delimiters and modifiers
     */
    protected const HTML_TEMPLATE_ELEMENT_PATTERN = '%<template[\s>][^<]*+(?:<(?!/template>)[^<]*+)*+(?:</template>|$)%i';
    /**
     * @var \DOMDocument|null
     */
    protected $dom_document;
    /**
     * @var \DOMXPath|null
     */
    private $x_path;
    /**
     * The constructor.
     *
     * Please use `::fromHtml` or `::fromDomDocument` instead.
     */
    final private function __construct()
    {
    }
    /**
     * Builds a new instance from the given HTML.
     *
     * @param non-empty-string $unprocessedHtml raw HTML, must be UTF-encoded
     *
     * @return static
     *
     * @throws \InvalidArgumentException if $unprocessedHtml is anything other than a non-empty string
     */
    public static function from_html(string $unprocessed_html): self
    {
        // @phpstan-ignore-next-line argument.type We're checking for a contract violation here.
        if ($unprocessed_html === '') {
            throw new \InvalidArgumentException('The provided HTML must not be empty.', 1515763647);
        }
        $instance = new static();
        $instance->set_html($unprocessed_html);
        return $instance;
    }
    /**
     * Builds a new instance from the given DOM document.
     *
     * @param \DOMDocument $document a DOM document returned by getDomDocument() of another instance
     *
     * @return static
     */
    public static function from_dom_document(\Dom_Document $document): self
    {
        $instance = new static();
        $instance->set_dom_document($document);
        return $instance;
    }
    /**
     * Sets the HTML to process.
     *
     * @param string $html the HTML to process, must be UTF-8-encoded
     */
    private function set_html(string $html): void
    {
        $this->create_unified_dom_document($html);
    }
    /**
     * Provides access to the internal DOMDocument representation of the HTML in its current state.
     *
     * @throws \UnexpectedValueException
     */
    public function get_dom_document(): \Dom_Document
    {
        if (!$this->dom_document instanceof \Dom_Document) {
            $message = self::class . '::setDomDocument() has not yet been called on ' . static::class;
            throw new \UnexpectedValueException($message, 1570472239);
        }
        return $this->dom_document;
    }
    private function set_dom_document(\Dom_Document $dom_document): void
    {
        $this->dom_document = $dom_document;
        $this->x_path = new \Domx_Path($this->dom_document);
    }
    /**
     * @throws \UnexpectedValueException
     */
    protected function get_x_path(): \Domx_Path
    {
        if (!$this->x_path instanceof \Domx_Path) {
            $message = self::class . '::setDomDocument() has not yet been called on ' . static::class;
            throw new \UnexpectedValueException($message, 1617819086);
        }
        return $this->x_path;
    }
    /**
     * Renders the normalized and processed HTML.
     *
     * @throws \RuntimeException if there is an internal error with `DOMDocument`
     */
    public function render(): string
    {
        return $this->get_html();
    }
    /**
     * Renders the content of the BODY element of the normalized and processed HTML.
     *
     * @throws \RuntimeException if there is an internal error with `DOMDocument`
     */
    public function render_body_content(): string
    {
        $body_node_html = $this->get_html($this->get_body_element());
        return preg_replace('%</?+body(?:\s[^>]*+)?+>%', '', $body_node_html);
    }
    /**
     * @param ?\DOMNode $node optional parameter to output a subset of the document
     *
     * @throws \RuntimeException if there is an internal error with `DOMDocument`
     */
    private function get_html(?\Dom_Node $node = null): string
    {
        $html = $this->get_dom_document()->save_html($node);
        if (!\is_string($html)) {
            throw new \RuntimeException('`DOMDocument::saveHTML()` failed.', 1773018082);
        }
        return $this->remove_self_closing_tags_closing_tags($html);
    }
    /**
     * Eliminates any invalid closing tags for void elements from the given HTML.
     */
    private function remove_self_closing_tags_closing_tags(string $html): string
    {
        return preg_replace('%</' . self::PHP_UNRECOGNIZED_VOID_TAGNAME_MATCHER . '>%', '', $html);
    }
    /**
     * Returns the HTML element.
     *
     * This method assumes that there always is an HTML element, throwing an exception otherwise.
     *
     * @throws \UnexpectedValueException
     */
    protected function get_html_element(): \Dom_Element
    {
        $html_element = $this->get_dom_document()->get_elements_by_tag_name('html')->item(0);
        if (!$html_element instanceof \Dom_Element) {
            throw new \UnexpectedValueException('There is no HTML element although there should be one.', 1569930853);
        }
        return $html_element;
    }
    /**
     * Returns the BODY element.
     *
     * This method assumes that there always is a BODY element.
     *
     * @throws \RuntimeException
     */
    private function get_body_element(): \Dom_Element
    {
        $node = $this->get_dom_document()->get_elements_by_tag_name('body')->item(0);
        if (!$node instanceof \Dom_Element) {
            throw new \RuntimeException('There is no body element.', 1617922607);
        }
        return $node;
    }
    /**
     * Creates a DOM document from the given HTML and stores it in $this->domDocument.
     *
     * The DOM document will always have a BODY element and a document type.
     */
    private function create_unified_dom_document(string $html): void
    {
        $this->create_raw_dom_document($html);
        $this->ensure_existence_of_body_element();
    }
    /**
     * Creates a DOMDocument instance from the given HTML and stores it in $this->domDocument.
     */
    private function create_raw_dom_document(string $html): void
    {
        $dom_document = new \Dom_Document();
        $dom_document->strict_error_checking = false;
        $dom_document->format_output = false;
        $lib_xml_state = \libxml_use_internal_errors(true);
        $dom_document->load_html($this->prepare_html_for_dom_conversion($html), LIBXML_PARSEHUGE);
        \libxml_clear_errors();
        \libxml_use_internal_errors($lib_xml_state);
        $this->set_dom_document($dom_document);
    }
    /**
     * Returns the HTML with added document type, Content-Type meta tag, and self-closing slashes, if needed,
     * ensuring that the HTML will be good for creating a DOM document from it.
     */
    private function prepare_html_for_dom_conversion(string $html): string
    {
        $html_with_self_closing_slashes = $this->ensure_php_unrecognized_self_closing_tags_are_xml($html);
        $html_with_document_type = $this->ensure_document_type($html_with_self_closing_slashes);
        return $this->add_content_type_meta_tag($html_with_document_type);
    }
    /**
     * Makes sure that the passed HTML has a document type, with lowercase "html".
     *
     * @return non-empty-string HTML with document type
     */
    private function ensure_document_type(string $html): string
    {
        $has_document_type = \stripos($html, '<!DOCTYPE') !== false;
        if ($has_document_type) {
            return $this->normalize_document_type($html);
        }
        return self::DEFAULT_DOCUMENT_TYPE . $html;
    }
    /**
     * Makes sure the document type in the passed HTML has lowercase `html`.
     *
     * @param non-empty-string $html
     *
     * @return non-empty-string HTML with normalized document type
     */
    private function normalize_document_type(string $html): string
    {
        // Limit to replacing the first occurrence: as an optimization; and in case an example exists as unescaped text.
        $result = preg_replace('/<!DOCTYPE\s++html(?=[\s>])/i', '<!DOCTYPE html', $html, 1);
        \assert($result !== '');
        return $result;
    }
    /**
     * Adds a Content-Type meta tag for the charset.
     *
     * This method also ensures that there is a HEAD element.
     *
     * @param non-empty-string $html
     *
     * @return non-empty-string
     */
    private function add_content_type_meta_tag(string $html): string
    {
        if ($this->has_content_type_meta_tag_in_head($html)) {
            return $html;
        }
        // We are trying to insert the meta tag to the right spot in the DOM.
        // If we just prepended it to the HTML, we would lose attributes set to the HTML tag.
        $has_head_tag = preg_match('/<head[\s>]/i', $html) !== 0;
        $has_html_tag = \stripos($html, '<html') !== false;
        if ($has_head_tag) {
            $reworked_html = preg_replace('/<head(?=[\s>])([^>]*+)>/i', '<head$1>' . self::CONTENT_TYPE_META_TAG, $html);
        } elseif ($has_html_tag) {
            $reworked_html = preg_replace('/<html(.*?)>/is', '<html$1><head>' . self::CONTENT_TYPE_META_TAG . '</head>', $html);
        } else {
            $reworked_html = self::CONTENT_TYPE_META_TAG . $html;
        }
        \assert($reworked_html !== '');
        return $reworked_html;
    }
    /**
     * Tests whether the given HTML has a valid `Content-Type` metadata element within the `<head>` element.  Due to tag
     * omission rules, HTML parsers are expected to end the `<head>` element and start the `<body>` element upon
     * encountering a start tag for any element which is permitted only within the `<body>`.
     */
    private function has_content_type_meta_tag_in_head(string $html): bool
    {
        preg_match('%
                (?(DEFINE)
                    # the target `http-equiv` attribute match
                    (?<target_attribute>
                        http-equiv=(["\']?+)Content-Type\g{-1}
                        # must be followed by one of these characters
                        [\s/>]
                    )
                    # the target `meta` element match without the opening `<`
                    (?<target>
                        meta(?=\s)
                        # one or other of these
                        (?:
                            # one or more characters other than `>` or space
                            [^>\s]++
                            |
                            # space not followed by the target `http-equiv` attribute
                            \s(?!(?&target_attribute))
                        )
                        # any number of times (including zero)
                        *+
                        \s(?&target_attribute)
                    )
                )
                # start of `subject`
                ^
                # one or other of these
                (?:
                    # one or more characters other than `<`
                    [^<]++
                    |
                    # `<` not followed by `target`
                    <(?!(?&target))
                )
                # any number of times (including zero)
                *+
                # followed by the target, not captured
                (?=<(?&target))
            %isx', $html, $matches);
        if (isset($matches[0])) {
            $html_before = $matches[0];
            try {
                $has_content_type_meta_tag_in_head = !$this->has_end_of_head_element($html_before);
            } catch (\RuntimeException $exception) {
                // If something unexpected occurs, assume the `Content-Type` that was found is valid.
                \trigger_error($exception->get_message());
                $has_content_type_meta_tag_in_head = true;
            }
        } else {
            $has_content_type_meta_tag_in_head = false;
        }
        return $has_content_type_meta_tag_in_head;
    }
    /**
     * Tests whether the `<head>` element ends within the given HTML.  Due to tag omission rules, HTML parsers are
     * expected to end the `<head>` element and start the `<body>` element upon encountering a start tag for any element
     * which is permitted only within the `<body>`.
     *
     * @throws \RuntimeException
     */
    private function has_end_of_head_element(string $html): bool
    {
        if (preg_match('%<(?!' . self::TAGNAME_ALLOWED_BEFORE_BODY_MATCHER . '[\s/>])\w|</head>%i', $html) !== 0) {
            // An exception to the implicit end of the `<head>` is any content within a `<template>` element, as well in
            // comments.  As an optimization, this is only checked for if a potential `<head>` end tag is found.
            $html_without_comments_or_templates = $this->remove_html_template_elements($this->remove_html_comments($html));
            return $html_without_comments_or_templates === $html || $this->has_end_of_head_element($html_without_comments_or_templates);
        }
        return false;
    }
    /**
     * Removes comments from the given HTML, including any which are unterminated, for which the remainder of the string
     * is removed.
     */
    private function remove_html_comments(string $html): string
    {
        return preg_replace(self::HTML_COMMENT_PATTERN, '', $html);
    }
    /**
     * Removes `<template>` elements from the given HTML, including any without an end tag, for which the remainder of
     * the string is removed.
     */
    private function remove_html_template_elements(string $html): string
    {
        return preg_replace(self::HTML_TEMPLATE_ELEMENT_PATTERN, '', $html);
    }
    /**
     * Makes sure that any self-closing tags not recognized as such by PHP's DOMDocument implementation have a
     * self-closing slash.
     */
    private function ensure_php_unrecognized_self_closing_tags_are_xml(string $html): string
    {
        return preg_replace('%<' . self::PHP_UNRECOGNIZED_VOID_TAGNAME_MATCHER . '\b[^>]*+(?<!/)(?=>)%', '$0/', $html);
    }
    /**
     * Checks that $this->domDocument has a BODY element and adds it if it is missing.
     *
     * @throws \UnexpectedValueException
     */
    private function ensure_existence_of_body_element(): void
    {
        if ($this->get_dom_document()->get_elements_by_tag_name('body')->item(0) instanceof \Dom_Element) {
            return;
        }
        $this->get_html_element()->append_child($this->get_dom_document()->create_element('body'));
    }
}