<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full-text indexes for the long prose the trigram indexes are a poor fit for.
 *
 * Trigram is right for names and identifiers — short strings matched by
 * substring. It scales badly over paragraphs: the index carries every
 * three-character window of every document. tsvector indexes words instead,
 * which is what invoice notes, line item descriptions and OCR'd documents
 * are made of.
 *
 * The expressions must stay character-for-character identical to the ones
 * Shared\Support\Search::fullTextMatch() builds, or the index quietly stops
 * being used. FullTextSearchTest pins that pairing.
 *
 * to_tsvector is used in its two-argument form on purpose: naming the
 * configuration makes it IMMUTABLE, where the one-argument form depends on
 * default_text_search_config and so cannot appear in an index.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> index name => table and document expression
     */
    private const INDEXES = [
        'invoices_note_fts_idx' => "invoices USING gin (to_tsvector('simple', public.immutable_unaccent(coalesce(note, '') || ' ' || coalesce(note_above, ''))))",
        'invoice_items_description_fts_idx' => "invoice_items USING gin (to_tsvector('simple', public.immutable_unaccent(coalesce(description, ''))))",
        'invoice_inbox_items_ocr_text_fts_idx' => "invoice_inbox_items USING gin (to_tsvector('simple', public.immutable_unaccent(coalesce(ocr_text, ''))))",
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
