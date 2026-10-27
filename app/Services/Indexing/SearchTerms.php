<?php

namespace App\Services\Indexing;

/**
 * Makes code searchable by words: "createInvoice" is also indexed as "create" and "invoice".
 *
 * Postgres's parser already splits snake_case and kebab-case, so only camelCase needs help.
 */
class SearchTerms
{
    /**
     * Get the text to index for a document: the original plus the parts of its camelCase identifiers.
     */
    public static function forDocument(string $text): string
    {
        $parts = static::identifierParts($text);

        return $parts === [] ? $text : $text."\n".implode(' ', $parts);
    }

    /**
     * Build a Postgres tsquery that matches any of the query's words, or null when it has none.
     */
    public static function toTsQuery(string $query): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);

        $words = array_map(mb_strtolower(...), [...$matches[0], ...static::identifierParts($query)]);
        $words = array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) > 1)));

        return $words === [] ? null : implode(' | ', $words);
    }

    /**
     * Build a tsquery that only matches text containing every word of the query as written, or null when it has none.
     */
    public static function toAllWordsTsQuery(string $query): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);

        $words = array_values(array_unique(array_map(mb_strtolower(...), $matches[0])));

        return $words === [] ? null : implode(' & ', $words);
    }

    /**
     * @return list<string>
     */
    protected static function identifierParts(string $text): array
    {
        preg_match_all('/\b[a-z]+[A-Z][A-Za-z0-9]*\b|\b[A-Z][a-z0-9]+[A-Z][A-Za-z0-9]*\b/', $text, $matches);

        $parts = [];

        foreach (array_unique($matches[0]) as $identifier) {
            foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $identifier) ?: [] as $part) {
                if (strlen($part) > 1) {
                    $parts[strtolower($part)] = true;
                }
            }
        }

        return array_keys($parts);
    }
}
