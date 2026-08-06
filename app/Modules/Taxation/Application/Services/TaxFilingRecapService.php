<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Models\TaxFiling;
use DOMDocument;
use DOMElement;

/**
 * Turns a stored filing into something a human can check before submitting
 * it.
 *
 * Read back out of the archived document rather than recomputed from the
 * account's current data: the point of the recap is to show *what will be
 * filed*, and a figure that has moved since generation is exactly what it
 * must not silently paper over.
 *
 * The flattening is deliberately generic — element paths and attributes as
 * they appear — instead of a second SK/CZ line-label mapping. There is
 * already one mapping between the ledger and the form; a second one for
 * display could drift from it, and a recap that disagrees with the filing is
 * worse than no recap.
 */
final readonly class TaxFilingRecapService
{
    /**
     * @return list<array{path: string, value: string}>
     */
    public function rows(TaxFiling $filing): array
    {
        $dom = new DOMDocument;

        $previousErrors = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            // Archived content, but hardened like any other parse — the same
            // XXE reasoning as the inbound UBL parser.
            if ($dom->loadXML($filing->content, LIBXML_NONET | LIBXML_NOENT) === false
                || $dom->documentElement === null) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($previousErrors);
        }

        $rows = [];
        $this->walk($dom->documentElement, '', $rows);

        return $rows;
    }

    /**
     * @param  list<array{path: string, value: string}>  $rows
     */
    private function walk(DOMElement $element, string $prefix, array &$rows): void
    {
        $path = $prefix === '' ? $element->nodeName : $prefix.' › '.$element->nodeName;

        foreach ($element->attributes ?? [] as $attribute) {
            $value = trim((string) $attribute->nodeValue);

            if ($value !== '') {
                $rows[] = ['path' => $path.' @'.$attribute->nodeName, 'value' => $value];
            }
        }

        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        if ($children === []) {
            $value = trim($element->textContent);

            // Empty lines are the norm on these forms and listing them all
            // would bury the handful that carry a number.
            if ($value !== '') {
                $rows[] = ['path' => $path, 'value' => $value];
            }

            return;
        }

        foreach ($children as $child) {
            $this->walk($child, $path, $rows);
        }
    }
}
