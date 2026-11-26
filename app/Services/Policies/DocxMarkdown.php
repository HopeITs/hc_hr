<?php

namespace App\Services\Policies;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * A Word (.docx) policy as Markdown, from Word's own structure: heading styles
 * (by their style name, so an Arabic Word's localized ids still count) and
 * outline levels become #, numbered and bulleted paragraphs become lists,
 * bold runs **bold**, and tables Markdown tables. Text is never re-ordered:
 * Arabic stays exactly as typed.
 */
class DocxMarkdown
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, int> styleId => heading level */
    private array $headingStyles = [];

    /** @var array<string, array<int, bool>> numId => [ilvl => ordered] */
    private array $ordered = [];

    /** @var array<string, array<int, int>> numId => [ilvl => items so far] */
    private array $counters = [];

    public function convert(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a readable .docx file.');
        }

        $document = $zip->getFromName('word/document.xml');
        $styles = $zip->getFromName('word/styles.xml');
        $numbering = $zip->getFromName('word/numbering.xml');
        $zip->close();

        if ($document === false) {
            throw new RuntimeException('Not a readable .docx file.');
        }

        $this->headingStyles = $styles !== false ? $this->headingStyles($styles) : [];
        $this->ordered = $numbering !== false ? $this->numbering($numbering) : [];
        $this->counters = [];

        $xpath = $this->xpath($document);
        $body = $xpath->query('//w:body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $blocks = $this->blocks($body, $xpath);

        return trim(implode("\n\n", array_filter($blocks, fn (string $b) => trim($b) !== '')));
    }

    /** @return list<string> */
    private function blocks(DOMElement $parent, DOMXPath $xpath): array
    {
        $blocks = [];
        $list = [];

        $flush = function () use (&$blocks, &$list): void {
            if ($list !== []) {
                $blocks[] = implode("\n", $list);
                $list = [];
            }
        };

        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::W) {
                continue;
            }

            if ($node->localName === 'sdt') {
                $content = $xpath->query('w:sdtContent', $node)->item(0);

                if ($content instanceof DOMElement) {
                    $flush();
                    array_push($blocks, ...$this->blocks($content, $xpath));
                }

                continue;
            }

            if ($node->localName === 'tbl') {
                $flush();
                $blocks[] = $this->table($node, $xpath);

                continue;
            }

            if ($node->localName !== 'p') {
                continue;
            }

            $text = $this->runs($node, $xpath, true);

            if (trim($text) === '') {
                $flush();

                continue;
            }

            if (($level = $this->headingLevel($node, $xpath)) !== null) {
                $flush();
                $blocks[] = str_repeat('#', $level).' '.trim(str_replace('**', '', $text));

                continue;
            }

            $numId = $xpath->query('w:pPr/w:numPr/w:numId/@w:val', $node)->item(0)?->nodeValue;
            $ilvl = (int) ($xpath->query('w:pPr/w:numPr/w:ilvl/@w:val', $node)->item(0)?->nodeValue ?? 0);

            if ($numId !== null && $numId !== '0') {
                $ordered = $this->ordered[$numId][$ilvl] ?? true;

                // Word numbers the items itself; written out, a list split between two passages
                // still goes on from where it was. A shallower item restarts the deeper levels.
                $this->counters[$numId][$ilvl] = ($this->counters[$numId][$ilvl] ?? 0) + 1;

                foreach (array_keys($this->counters[$numId]) as $level) {
                    if ($level > $ilvl) {
                        unset($this->counters[$numId][$level]);
                    }
                }

                $marker = $ordered ? $this->counters[$numId][$ilvl].'. ' : '- ';
                $list[] = str_repeat('   ', min($ilvl, 3)).$marker.trim($text);

                continue;
            }

            $flush();
            $blocks[] = $this->escapeStart(trim($text));
        }

        $flush();

        return $blocks;
    }

    private function table(DOMElement $table, DOMXPath $xpath): string
    {
        $rows = [];

        foreach ($xpath->query('w:tr', $table) as $tr) {
            $cells = [];

            foreach ($xpath->query('w:tc', $tr) as $tc) {
                $parts = [];

                foreach ($xpath->query('.//w:p', $tc) as $p) {
                    $parts[] = trim($this->runs($p, $xpath, false));
                }

                $cells[] = str_replace('|', '\\|', trim(implode(' ', array_filter($parts, fn ($s) => $s !== ''))));
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = fn (array $cells): string => '| '.implode(' | ', array_pad($cells, $width, '')).' |';
        $out = [$line($rows[0]), '| '.implode(' | ', array_fill(0, $width, '---')).' |'];

        foreach (array_slice($rows, 1) as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }

    /** The paragraph's text, bold runs marked when $bold. */
    private function runs(DOMElement $paragraph, DOMXPath $xpath, bool $bold): string
    {
        $pieces = [];

        foreach ($xpath->query('.//w:r', $paragraph) as $run) {
            $text = '';

            foreach ($run->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $text .= match ($child->localName) {
                    't' => $child->textContent,
                    'tab' => ' ',
                    'br', 'cr' => ' ',
                    default => '',
                };
            }

            if ($text === '') {
                continue;
            }

            $isBold = $bold && $this->isBold($run, $xpath);
            $last = array_key_last($pieces);

            if ($last !== null && $pieces[$last][1] === $isBold) {
                $pieces[$last][0] .= $text;
            } else {
                $pieces[] = [$text, $isBold];
            }
        }

        $out = '';

        foreach ($pieces as [$text, $isBold]) {
            $escaped = $this->escape($text);

            if ($isBold && trim($text) !== '') {
                // The spaces stay outside the markers, or Markdown does not see them.
                preg_match('/^(\s*)(.*?)(\s*)$/su', $escaped, $m);
                $out .= $m[1].'**'.$m[2].'**'.$m[3];
            } else {
                $out .= $escaped;
            }
        }

        return $out;
    }

    private function isBold(DOMElement $run, DOMXPath $xpath): bool
    {
        foreach (['w:rPr/w:b', 'w:rPr/w:bCs'] as $path) {
            $b = $xpath->query($path, $run)->item(0);

            if ($b instanceof DOMElement) {
                $val = $b->getAttributeNS(self::W, 'val');

                return ! in_array($val, ['0', 'false', 'off'], true);
            }
        }

        return false;
    }

    private function headingLevel(DOMElement $paragraph, DOMXPath $xpath): ?int
    {
        $style = $xpath->query('w:pPr/w:pStyle/@w:val', $paragraph)->item(0)?->nodeValue;

        if ($style !== null && isset($this->headingStyles[$style])) {
            return $this->headingStyles[$style];
        }

        $outline = $xpath->query('w:pPr/w:outlineLvl/@w:val', $paragraph)->item(0)?->nodeValue;

        return $outline !== null && (int) $outline < 6 ? min((int) $outline + 1, 4) : null;
    }

    /** @return array<string, int> */
    private function headingStyles(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $levels = [];

        foreach ($xpath->query('//w:style[@w:type="paragraph"]') as $style) {
            $id = $style->getAttributeNS(self::W, 'styleId');
            $name = strtolower((string) $xpath->query('w:name/@w:val', $style)->item(0)?->nodeValue);
            $outline = $xpath->query('w:pPr/w:outlineLvl/@w:val', $style)->item(0)?->nodeValue;

            if ($name === 'title') {
                $levels[$id] = 1;
            } elseif (preg_match('/^heading\s*([1-6])$/', $name, $m)) {
                $levels[$id] = min((int) $m[1], 4);
            } elseif ($outline !== null && (int) $outline < 6) {
                $levels[$id] = min((int) $outline + 1, 4);
            }
        }

        return $levels;
    }

    /** @return array<string, array<int, bool>> */
    private function numbering(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $abstract = [];

        foreach ($xpath->query('//w:abstractNum') as $node) {
            $id = $node->getAttributeNS(self::W, 'abstractNumId');

            foreach ($xpath->query('w:lvl', $node) as $lvl) {
                $format = (string) $xpath->query('w:numFmt/@w:val', $lvl)->item(0)?->nodeValue;
                $abstract[$id][(int) $lvl->getAttributeNS(self::W, 'ilvl')] = ! in_array($format, ['bullet', 'none', ''], true);
            }
        }

        $map = [];

        foreach ($xpath->query('//w:num') as $num) {
            $abstractId = (string) $xpath->query('w:abstractNumId/@w:val', $num)->item(0)?->nodeValue;
            $map[$num->getAttributeNS(self::W, 'numId')] = $abstract[$abstractId] ?? [];
        }

        return $map;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }

    /** Characters Markdown would read as formatting inside a line. */
    private function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]<>])/u', '\\\\$1', $text);
    }

    /** A paragraph that starts like a heading, list or quote stays a paragraph. */
    private function escapeStart(string $text): string
    {
        if (preg_match('/^\d+[.)]\s/u', $text)) {
            return preg_replace('/^(\d+)([.)])/u', '$1\\\\$2', $text);
        }

        return preg_match('/^(#|>|[-+]\s)/u', $text) ? '\\'.$text : $text;
    }
}
