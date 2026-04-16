<?php

namespace Toast\OpenSearch\Search;

class OpenSearchFineTuneSettings
{
    public const DEFAULT_OPTION_VALUE = '__DEFAULT__';

    private const DEFAULT_SEARCH_MODE = 'best_fields';
    private const DEFAULT_OPERATOR = 'or';
    private const MIN_SCORE_SCALE = 1;

    public static function getSearchModeFieldOptions(): array
    {
        return [
            self::DEFAULT_OPTION_VALUE => 'Default: Best match across fields',
            'most_fields' => 'Combine matches across fields',
            'cross_fields' => 'Treat fields like one combined field',
            'phrase' => 'Match an exact phrase',
            'phrase_prefix' => 'Match the start of a phrase',
        ];
    }

    public static function getSearchModeStoredValue(?string $value): string
    {
        return $value ?? self::DEFAULT_OPTION_VALUE;
    }

    public static function getOperatorFieldOptions(): array
    {
        return [
            self::DEFAULT_OPTION_VALUE => 'Default: Match any word',
            'and' => 'Match all words',
        ];
    }

    public static function getOperatorStoredValue(?string $value): string
    {
        return $value ?? self::DEFAULT_OPTION_VALUE;
    }

    public static function getMinimumShouldMatchFieldOptions(): array
    {
        return [
            self::DEFAULT_OPTION_VALUE => 'Default: OpenSearch default',
            '1' => 'At least 1 word',
            '2' => 'At least 2 words',
            '3' => 'At least 3 words',
            '50%' => 'At least 50% of words',
            '60%' => 'At least 60% of words',
            '70%' => 'At least 70% of words',
            '75%' => 'At least 75% of words',
            '80%' => 'At least 80% of words',
            '90%' => 'At least 90% of words',
            '100%' => 'All words',
        ];
    }

    public static function getMinimumShouldMatchStoredValue(?string $value): string
    {
        return $value ?? self::DEFAULT_OPTION_VALUE;
    }

    public static function getFuzzinessFieldOptions(): array
    {
        return [
            self::DEFAULT_OPTION_VALUE => 'Default: OpenSearch typo handling',
            '0' => 'Exact matches only',
            '1' => 'Allow minor typos',
            '2' => 'Allow more typos',
            'AUTO' => 'Automatic typo handling',
        ];
    }

    public static function getFuzzinessStoredValue($value): string
    {
        return $value === null ? self::DEFAULT_OPTION_VALUE : (string) $value;
    }

    public static function normalise(array $settings): array
    {
        $normalised = [];

        $searchMode = self::normaliseSearchMode($settings['type'] ?? null);
        if ($searchMode !== null && $searchMode !== self::DEFAULT_SEARCH_MODE) {
            $normalised['type'] = $searchMode;
        }

        $operator = self::normaliseOperator($settings['operator'] ?? null);
        if ($operator !== null && $operator !== self::DEFAULT_OPERATOR) {
            $normalised['operator'] = $operator;
        }

        $minimumShouldMatch = self::normaliseMinimumShouldMatch($settings['minimum_should_match'] ?? null);
        if ($minimumShouldMatch !== null) {
            $normalised['minimum_should_match'] = $minimumShouldMatch;
        }

        $fuzziness = self::normaliseFuzziness($settings['fuzziness'] ?? null);
        if ($fuzziness !== null) {
            $normalised['fuzziness'] = $fuzziness;
        }

        $minScore = self::normaliseMinScore($settings['min_score'] ?? null);
        if ($minScore !== null) {
            $normalised['min_score'] = $minScore;
        }

        return $normalised;
    }

    public static function formatMinScore(float $score): string
    {
        return number_format($score, self::MIN_SCORE_SCALE, '.', '');
    }

    public static function sanitiseForMultiMatch(array $settings): array
    {
        $type = $settings['type'] ?? self::DEFAULT_SEARCH_MODE;

        if (in_array($type, ['cross_fields', 'phrase', 'phrase_prefix'], true)) {
            unset($settings['fuzziness']);
        }

        return $settings;
    }

    private static function normaliseSearchMode($value): ?string
    {
        $value = self::normaliseString($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return array_key_exists($value, self::getSearchModeFieldOptions())
            && $value !== self::DEFAULT_OPTION_VALUE
            ? $value
            : null;
    }

    private static function normaliseOperator($value): ?string
    {
        $value = self::normaliseString($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return array_key_exists($value, self::getOperatorFieldOptions())
            && $value !== self::DEFAULT_OPTION_VALUE
            ? $value
            : null;
    }

    private static function normaliseMinimumShouldMatch($value): ?string
    {
        $value = self::normaliseString($value);

        if ($value === null) {
            return null;
        }

        if ($value === self::DEFAULT_OPTION_VALUE) {
            return null;
        }

        if (preg_match('/^[1-9][0-9]*$/', $value)) {
            return $value;
        }

        if (preg_match('/^(100|[1-9]?[0-9])%$/', $value)) {
            return $value;
        }

        return null;
    }

    private static function normaliseFuzziness($value)
    {
        $value = self::normaliseString($value);

        if ($value === null) {
            return null;
        }

        $upperValue = strtoupper($value);

        if ($upperValue === self::DEFAULT_OPTION_VALUE) {
            return null;
        }

        if ($upperValue === 'AUTO') {
            return 'AUTO';
        }

        if (preg_match('/^[0-2]$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    private static function normaliseMinScore($value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $value = round((float) $value, self::MIN_SCORE_SCALE);

        if ($value <= 0) {
            return null;
        }

        return $value;
    }

    private static function normaliseString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
