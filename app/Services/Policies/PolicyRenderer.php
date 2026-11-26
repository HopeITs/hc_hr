<?php

namespace App\Services\Policies;

use App\Support\Search\Highlight;
use DOMDocument;
use DOMText;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * A passage of a policy's Markdown as HTML for its page: headings, lists,
 * bold and tables (GitHub-flavoured), with any HTML in the text escaped and
 * unsafe links dropped, and the searched words marked in the text only.
 */
class PolicyRenderer
{
    /** @param  list<string>  $terms */
    public function html(string $markdown, array $terms = []): string
    {
        $markdown = self::withoutCodeBlocks($markdown);

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return $terms === [] ? $html : $this->mark($html, $terms);
    }

    /**
     * Text that never holds code, ready for Markdown: a line indented by a tab or four spaces (Word's
     * indentation, or text kept as it was) would otherwise turn into a monospace, left-to-right code
     * block. Indentation stays only in front of a list marker, where it nests the item, and a code
     * fence is shown as text.
     */
    public static function withoutCodeBlocks(string $markdown): string
    {
        $markdown = preg_replace('/^[ \t]+(?!(?:[-*+]|\d+[.)])[ \t])/mu', '', $markdown) ?? $markdown;

        return preg_replace('/^(\s*)(`{3,}|~{3,})/mu', '$1\\\\$2', $markdown) ?? $markdown;
    }

    /** @param  list<string>  $terms */
    private function mark(string $html, array $terms): string
    {
        $dom = new DOMDocument;
        // The meta tag keeps Arabic as UTF-8; the wrapper is taken off again below.
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="policy-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);

        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//text()[not(ancestor::mark)]')) as $node) {
            /** @var DOMText $node */
            $marked = Highlight::html($node->nodeValue, $terms);

            if ($marked === e($node->nodeValue)) {
                continue;
            }

            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML($marked);
            $node->parentNode->replaceChild($fragment, $node);
        }

        $root = $dom->getElementById('policy-root') ?? $xpath->query('//div[@id="policy-root"]')->item(0);
        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}
