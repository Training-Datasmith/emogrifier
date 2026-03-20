<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Html_Processor;

/**
 * Normalizes HTML:
 * - add a document type (HTML5) if missing
 * - disentangle incorrectly nested tags
 * - add HEAD and BODY elements (if they are missing)
 * - reformat the HTML
 */
final class Html_Normalizer extends Abstract_Html_Processor
{
}