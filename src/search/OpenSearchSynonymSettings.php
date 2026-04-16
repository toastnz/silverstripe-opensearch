<?php

namespace Toast\OpenSearch\Search;

use Toast\OpenSearch\Models\OpenSearchSynonym;

class OpenSearchSynonymSettings
{
    private const QUERY_VARIANT_LIMIT = 8;

    public static function buildRules(iterable $synonyms): array
    {
        $rulesByMatch = [];

        foreach ($synonyms as $synonym) {
            foreach (self::rulesFromSynonym($synonym) as $rule) {
                self::appendRule($rulesByMatch, $rule);
            }
        }

        $rules = array_values(array_map(static function (array $rule): array {
            $rule['replacements'] = array_values($rule['replacements']);

            return $rule;
        }, $rulesByMatch));

        usort($rules, static function (array $left, array $right): int {
            return strlen($right['match']) <=> strlen($left['match']);
        });

        return $rules;
    }

    public static function expandSearchTerm(string $searchTerm, array $rules, int $limit = self::QUERY_VARIANT_LIMIT): array
    {
        $searchTerm = self::normaliseTerm($searchTerm);

        if ($searchTerm === '' || $rules === []) {
            return $searchTerm === '' ? [] : [$searchTerm];
        }

        $limit = max(1, $limit);
        $variants = [
            self::normaliseKey($searchTerm) => $searchTerm,
        ];

        foreach ($rules as $rule) {
            $match = $rule['match'] ?? null;
            $replacements = $rule['replacements'] ?? null;

            if (!is_string($match) || !is_array($replacements) || $replacements === []) {
                continue;
            }

            $currentVariants = array_values($variants);

            foreach ($currentVariants as $variant) {
                if (!self::containsWholeTerm($variant, $match)) {
                    continue;
                }

                foreach ($replacements as $replacement) {
                    if (!is_string($replacement)) {
                        continue;
                    }

                    $expandedVariant = self::replaceWholeTerm($variant, $match, $replacement);
                    $expandedVariant = self::normaliseTerm($expandedVariant);

                    if ($expandedVariant === '') {
                        continue;
                    }

                    $variantKey = self::normaliseKey($expandedVariant);

                    if (isset($variants[$variantKey])) {
                        continue;
                    }

                    $variants[$variantKey] = $expandedVariant;

                    if (count($variants) >= $limit) {
                        return array_values($variants);
                    }
                }
            }
        }

        return array_values($variants);
    }

    private static function rulesFromSynonym($synonym): array
    {
        if (!$synonym instanceof OpenSearchSynonym) {
            return [];
        }

        $matches = $synonym->getSearchTermList();
        $replacements = $synonym->getSynonymTermList();

        if ($matches === [] || $replacements === []) {
            return [];
        }

        return array_map(static function (string $match) use ($replacements): array {
            return [
                'match' => $match,
                'replacements' => $replacements,
            ];
        }, $matches);
    }

    private static function appendRule(array &$rulesByMatch, array $rule): void
    {
        $match = $rule['match'] ?? null;
        $replacements = $rule['replacements'] ?? null;

        if (!is_string($match) || !is_array($replacements) || $replacements === []) {
            return;
        }

        $key = self::normaliseKey($match);

        if (!isset($rulesByMatch[$key])) {
            $rulesByMatch[$key] = [
                'match' => $match,
                'replacements' => [],
            ];
        }

        foreach ($replacements as $replacement) {
            if (!is_string($replacement)) {
                continue;
            }

            $replacementKey = self::normaliseKey($replacement);

            if (
                $replacementKey === ''
                || $replacementKey === $key
                || isset($rulesByMatch[$key]['replacements'][$replacementKey])
            ) {
                continue;
            }

            $rulesByMatch[$key]['replacements'][$replacementKey] = $replacement;
        }
    }

    private static function normaliseTerm(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function normaliseKey(string $value): string
    {
        $value = self::normaliseTerm($value);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function containsWholeTerm(string $value, string $term): bool
    {
        $pattern = self::buildWholeTermPattern($term);

        if ($pattern === null) {
            return false;
        }

        return preg_match($pattern, $value) === 1;
    }

    private static function replaceWholeTerm(string $value, string $search, string $replacement): string
    {
        $pattern = self::buildWholeTermPattern($search);

        if ($pattern === null) {
            return $value;
        }

        $replaced = preg_replace_callback(
            $pattern,
            static fn(): string => $replacement,
            $value
        );

        return is_string($replaced) ? $replaced : $value;
    }

    private static function buildWholeTermPattern(string $term): ?string
    {
        $term = self::normaliseTerm($term);

        if ($term === '') {
            return null;
        }

        return '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu';
    }
}
