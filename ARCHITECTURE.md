# Architecture: emogrifier

## Purpose

A PHP library that inlines CSS styles from `<style>` blocks into HTML element `style` attributes. This is essential for HTML emails, which cannot rely on external stylesheets and have limited support for `<style>` blocks in many email clients.

## Directory Structure

```
src/
  Css_Inliner.php                     — Primary API: parses CSS, matches selectors, inlines declarations
  Css/
    Css_Document.php                  — Parses a CSS stylesheet into a collection of rule sets
    Rule_Set.php                      — A CSS rule set (selector + declarations)
    Rule_Set_List.php                 — Ordered list of rule sets with specificity-aware merging
    Style_Rule.php                    — A single CSS selector + declaration block pair
  HtmlProcessor/
    Abstract_Html_Processor.php       — Base: wraps DOMDocument for HTML manipulation
    Css_To_Attribute_Converter.php    — Converts some CSS properties to deprecated HTML attributes (e.g., align)
    Css_Variable_Evaluator.php        — Evaluates CSS custom properties (var(--x)) before inlining
    Html_Normalizer.php               — Normalises HTML structure for consistent output
    Html_Pruner.php                   — Removes elements matching CSS selectors (e.g., :not rules)
  Caching/
    Simple_String_Cache.php           — Lightweight string → string cache for parsed CSS/selectors
  Utilities/
    Array_Intersector.php             — Set intersection utilities for declaration merging
    Css_Concatenator.php              — Concatenates CSS declaration blocks respecting specificity
    Declaration_Block_Parser.php      — Parses CSS declaration strings into property → value maps

tests/
  Unit/                               — Comprehensive unit tests per class
  Support/Constraint/                 — PHPUnit constraint helpers for asserting CSS content
```

## Key Design Decisions

- **DOMDocument-based** — uses PHP's native DOMDocument for HTML parsing and manipulation, giving precise control over element attributes.
- **Specificity-aware merging** — when multiple CSS rules match an element, declarations are merged in specificity order (lower specificity first, higher specificity wins on conflict).
- **CSS custom property evaluation** — `Css_Variable_Evaluator` resolves `var(--custom)` references before inlining, enabling modern CSS to be used in email templates.
- **Caching** — parsed CSS documents and computed per-element style strings are cached via `Simple_String_Cache` to avoid re-parsing on repeated identical inputs.
- **Attribute conversion** — `Css_To_Attribute_Converter` converts layout-related CSS (like `text-align: center`) to the equivalent deprecated HTML attributes, maximising compatibility with ancient email clients.

## Extension Points

- Inject a custom cache implementation by replacing the `Simple_String_Cache` passed to `Css_Inliner`.
- Subclass `Abstract_Html_Processor` to add custom HTML post-processing steps.

## Dependency Flow

```
Css_Inliner::inlineStylesOf(html)
  ├── Css_Document::parse(css_string)        → Rule_Set_List
  ├── DOMDocument::loadHTML(html)            → DOM tree
  ├── per element: match selectors → collect declarations
  │     └── Css_Concatenator::merge(declarations, specificity)
  ├── Css_Variable_Evaluator (resolve var())
  └── write style="" attribute to each matched element
```
