<?php

namespace Toast\OpenSearch\Extensions;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use Toast\OpenSearch\Helpers\OpenSearch;
use Toast\OpenSearch\Search\OpenSearchIndex;

class SiteConfigExplainLeftAndMainExtension extends Extension
{
    private const EXPLAIN_DEFAULT_RESULT_LIMIT = 25;
    private const EXPLAIN_RESULT_LIMITS = [10, 25, 50, 100];

    private static $allowed_actions = [
        'OpenSearchExplain',
    ];

    public function OpenSearchExplain(HTTPRequest $request): HTTPResponse
    {
        $siteConfig = $this->getCurrentSiteConfig();

        if (!$siteConfig || !$siteConfig->exists() || !$siteConfig->canEdit()) {
            return $this->jsonResponse([
                'error' => 'You do not have permission to explain OpenSearch results.',
            ], 403);
        }

        $searchTerm = trim((string) $request->getVar('Search'));

        if ($searchTerm === '') {
            return $this->jsonResponse([
                'error' => 'Enter a search term to explain.',
            ], 400);
        }

        try {
            $search = OpenSearch::singleton();
            $index = $search->getIndexDefinition();
            $options = $this->withCurrentSubsiteScope($index, $searchTerm, [
                'size' => $this->getExplainResultLimit($request),
                'track_total_hits' => true,
            ]);
            $response = $search->explainSearch($searchTerm, $index, $options);

            return $this->jsonResponse($this->formatExplainResponse($response));
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function getExplainResultLimit(HTTPRequest $request): int
    {
        $limit = (int) $request->getVar('Limit');

        if (!in_array($limit, self::EXPLAIN_RESULT_LIMITS, true)) {
            return self::EXPLAIN_DEFAULT_RESULT_LIMIT;
        }

        return $limit;
    }

    private function getCurrentSiteConfig(): ?SiteConfig
    {
        $record = $this->owner->currentRecord();

        if ($record instanceof SiteConfig && $record->exists()) {
            return $record;
        }

        $current = SiteConfig::current_site_config();

        return $current->exists() ? $current : null;
    }

    private function withCurrentSubsiteScope(OpenSearchIndex $index, string $searchTerm, array $options): array
    {
        if (!array_key_exists('SubsiteID', $index->getFilters())) {
            return $options;
        }

        $subsiteID = $this->getCurrentSubsiteID();

        if ($subsiteID === null) {
            return $options;
        }

        $options['query'] = [
            'bool' => [
                'must' => [
                    $index->buildSearchQuery($searchTerm, $options),
                ],
                'filter' => [
                    $this->buildSubsiteScopeQuery($subsiteID),
                ],
            ],
        ];

        return $options;
    }

    private function getCurrentSubsiteID(): ?int
    {
        try {
            if (!ModuleLoader::inst()->getManifest()->moduleExists('silverstripe/subsites')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $subsiteStateClass = 'SilverStripe\\Subsites\\State\\SubsiteState';

        if (!class_exists($subsiteStateClass) || !method_exists($subsiteStateClass, 'singleton')) {
            return null;
        }

        $state = $subsiteStateClass::singleton();

        if (!is_object($state) || !method_exists($state, 'getSubsiteId')) {
            return null;
        }

        $subsiteID = $state->getSubsiteId();

        return $subsiteID === null ? null : (int) $subsiteID;
    }

    private function buildSubsiteScopeQuery(int $subsiteID): array
    {
        $shouldQueries = [];

        foreach (array_unique([$subsiteID, 0]) as $id) {
            $shouldQueries[] = [
                'term' => [
                    'SubsiteID' => $id,
                ],
            ];
        }

        $shouldQueries[] = [
            'bool' => [
                'must_not' => [
                    [
                        'exists' => [
                            'field' => 'SubsiteID',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'bool' => [
                'should' => $shouldQueries,
                'minimum_should_match' => 1,
            ],
        ];
    }

    private function formatExplainResponse(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];
        $total = $response['hits']['total']['value'] ?? count($hits);

        return [
            'total' => $total,
            'returned' => count($hits),
            'took' => $response['took'] ?? null,
            'results' => array_map(function (array $hit): array {
                $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];

                return [
                    'title' => $this->getResultTitle($hit, $source),
                    'link' => $this->getResultLink($source),
                    'score' => $hit['_score'] ?? null,
                    'explanation' => $this->humaniseExplanation($hit['_explanation'] ?? null),
                    'summary' => $this->summariseExplanationCalculation($hit['_explanation'] ?? null),
                ];
            }, $hits),
        ];
    }

    private function getResultTitle(array $hit, array $source): string
    {
        foreach (['Title', 'MenuTitle', 'Name'] as $field) {
            $value = $source[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $record = $this->getRecordFromSource($source);

        if ($record && $record->hasMethod('Title') && trim((string) $record->Title()) !== '') {
            return trim((string) $record->Title());
        }

        if ($record && $record->hasField('Title') && trim((string) $record->getField('Title')) !== '') {
            return trim((string) $record->getField('Title'));
        }

        return (string) ($hit['_id'] ?? 'Untitled result');
    }

    private function getResultLink(array $source): ?string
    {
        foreach (['AbsoluteLink', 'Link', 'URL'] as $field) {
            $value = $source[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $record = $this->getRecordFromSource($source);

        if (!$record) {
            return null;
        }

        foreach (['AbsoluteLink', 'Link'] as $method) {
            if ($record->hasMethod($method)) {
                $link = $record->$method();

                if (is_string($link) && trim($link) !== '') {
                    return trim($link);
                }
            }
        }

        return null;
    }

    private function getRecordFromSource(array $source): ?DataObject
    {
        $className = $source['ClassName'] ?? null;
        $recordId = $source['ID'] ?? null;

        if (!is_string($className) || $className === '' || !class_exists($className)) {
            return null;
        }

        if (!is_a($className, DataObject::class, true) || $recordId === null || $recordId === '') {
            return null;
        }

        $record = $className::get()->byID($recordId);

        return $record instanceof DataObject && $record->exists() ? $record : null;
    }

    private function humaniseExplanation($explanation): array
    {
        if (!is_array($explanation)) {
            return [$this->plainLine('OpenSearch did not return an explanation for this result.')];
        }

        $score = isset($explanation['value']) && is_numeric($explanation['value'])
            ? $this->formatScore((float) $explanation['value'])
            : null;
        $matches = $this->extractExplanationMatches($explanation);
        $lines = [];

        if ($score !== null) {
            $lines[] = $this->line([
                $this->part('Final score: '),
                $this->part($score, 'score'),
                $this->part('.'),
            ]);
        }

        if ($matches !== []) {
            foreach (array_slice($matches, 0, 12) as $match) {
                $lines[] = $this->line([
                    $this->part($match['field'], 'field'),
                    $this->part(' matched "'),
                    $this->part($match['term'], 'term'),
                    $this->part('" with contribution '),
                    $this->part($this->formatScore($match['score']), 'score'),
                    $this->part('.'),
                ]);
            }

            if (count($matches) > 12) {
                $lines[] = $this->line([
                    $this->part((string) (count($matches) - 12), 'score'),
                    $this->part(' additional matching score details were omitted.'),
                ]);
            }
        } else {
            $summary = $this->getExplanationSummary($explanation);

            if ($summary !== null) {
                $lines[] = $this->plainLine($summary);
            }
        }

        return $lines === [] ? [$this->plainLine('OpenSearch returned this result, but no score details were available.')] : $lines;
    }

    private function summariseExplanationCalculation($explanation): array
    {
        if (!is_array($explanation)) {
            return [];
        }

        $score = isset($explanation['value']) && is_numeric($explanation['value'])
            ? $this->formatScore((float) $explanation['value'])
            : null;
        $matches = $this->extractExplanationCalculationMatches($explanation);
        $lines = [];

        if ($score !== null) {
            $lines[] = $this->line([
                $this->part('OpenSearch calculated a final relevance score of '),
                $this->part($score, 'score'),
                $this->part(' by adding together the scoring contributions from the matched fields and terms.'),
            ]);
        } else {
            $lines[] = $this->plainLine('OpenSearch ranked this result by adding together the scoring contributions from the matched fields and terms.');
        }

        if ($matches === []) {
            $summary = $this->getExplanationSummary($explanation);

            if ($summary !== null) {
                $lines[] = $this->plainLine($summary);
            }

            return $lines;
        }

        $strongestMatch = $matches[0];
        $lines[] = $this->line([
            $this->part('The strongest signal for this result was '),
            $this->part($strongestMatch['field'], 'field'),
            $this->part(' matching "'),
            $this->part($strongestMatch['term'], 'term'),
            $this->part('", which contributed '),
            $this->part($this->formatScore($strongestMatch['score']), 'score'),
            $this->part(' to this record on its own.'),
        ]);

        foreach (array_slice($matches, 0, 5) as $match) {
            $factors = [];

            if ($match['boost'] !== null) {
                $factors[] = [
                    $this->part('configured/search boost '),
                    $this->part($this->formatScore($match['boost']), 'score'),
                ];
            }

            if ($match['idf'] !== null) {
                $factors[] = [
                    $this->part('term rarity '),
                    $this->part($this->formatScore($match['idf']), 'score'),
                ];
            }

            if ($match['tf'] !== null) {
                $factors[] = [
                    $this->part('match strength in this document '),
                    $this->part($this->formatScore($match['tf']), 'score'),
                ];
            }

            if ($factors === []) {
                $lines[] = $this->line([
                    $this->part($match['field'], 'field'),
                    $this->part(' matched "'),
                    $this->part($match['term'], 'term'),
                    $this->part('" and added '),
                    $this->part($this->formatScore($match['score']), 'score'),
                    $this->part(' to the score.'),
                ]);
                continue;
            }

            $lines[] = $this->line(array_merge(
                [
                    $this->part($match['field'], 'field'),
                    $this->part(' matched "'),
                    $this->part($match['term'], 'term'),
                    $this->part('" and added '),
                    $this->part($this->formatScore($match['score']), 'score'),
                    $this->part('. Main factors: '),
                ],
                $this->joinPartLists($factors),
                [
                    $this->part('.'),
                ]
            ));
        }

        if (count($matches) > 5) {
            $lines[] = $this->line([
                $this->part('This record had '),
                $this->part((string) (count($matches) - 5), 'score'),
                $this->part(' additional lower-scoring field/term contributions.'),
            ]);
        }

        return $lines;
    }

    private function extractExplanationMatches(array $explanation): array
    {
        $matches = [];
        $this->collectExplanationMatches($explanation, $matches);

        usort($matches, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'];
        });

        return $matches;
    }

    private function collectExplanationMatches(array $node, array &$matches): void
    {
        $description = (string) ($node['description'] ?? '');

        if (preg_match('/weight\(([^:]+):(.+?) in \d+\)/', $description, $match)) {
            $matches[] = [
                'field' => $this->humaniseFieldName($match[1]),
                'term' => trim($match[2], '"'),
                'score' => (float) ($node['value'] ?? 0),
            ];
        }

        foreach (($node['details'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectExplanationMatches($child, $matches);
            }
        }
    }

    private function extractExplanationCalculationMatches(array $explanation): array
    {
        $matches = [];
        $this->collectExplanationCalculationMatches($explanation, $matches);

        usort($matches, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'];
        });

        return $matches;
    }

    private function collectExplanationCalculationMatches(array $node, array &$matches): void
    {
        $description = (string) ($node['description'] ?? '');

        if (preg_match('/weight\(([^:]+):(.+?) in \d+\)/', $description, $match)) {
            $matches[] = [
                'field' => $this->humaniseFieldName($match[1]),
                'term' => trim($match[2], '"'),
                'score' => (float) ($node['value'] ?? 0),
                'boost' => $this->findExplanationFactor($node, 'boost'),
                'idf' => $this->findExplanationFactor($node, 'idf'),
                'tf' => $this->findExplanationFactor($node, 'tf'),
            ];
        }

        foreach (($node['details'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectExplanationCalculationMatches($child, $matches);
            }
        }
    }

    private function findExplanationFactor(array $node, string $factor): ?float
    {
        $description = strtolower(trim((string) ($node['description'] ?? '')));

        if (
            $description === $factor
            || str_starts_with($description, $factor . ',')
            || str_starts_with($description, $factor . ' ')
        ) {
            return isset($node['value']) && is_numeric($node['value']) ? (float) $node['value'] : null;
        }

        foreach (($node['details'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $value = $this->findExplanationFactor($child, $factor);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function plainLine(string $text): array
    {
        return $this->line([
            $this->part($text),
        ]);
    }

    private function line(array $parts): array
    {
        return [
            'parts' => $parts,
        ];
    }

    private function part(string $text, ?string $style = null): array
    {
        $part = [
            'text' => $text,
        ];

        if ($style !== null) {
            $part['style'] = $style;
        }

        return $part;
    }

    private function joinPartLists(array $partLists): array
    {
        $joined = [];

        foreach (array_values($partLists) as $index => $parts) {
            if ($index > 0) {
                $joined[] = $this->part(', ');
            }

            foreach ($parts as $part) {
                $joined[] = $part;
            }
        }

        return $joined;
    }

    private function getExplanationSummary(array $explanation): ?string
    {
        $description = trim((string) ($explanation['description'] ?? ''));

        if ($description === '') {
            return null;
        }

        return sprintf('OpenSearch described this score as: %s.', rtrim($description, '.'));
    }

    private function humaniseFieldName(string $field): string
    {
        $segments = explode('.', $field);

        $segments = array_map(static function (string $segment): string {
            return preg_replace('/(?<!^)([A-Z])/', ' $1', $segment) ?? $segment;
        }, $segments);

        return implode(' > ', $segments);
    }

    private function formatScore(float $score): string
    {
        return rtrim(rtrim(number_format($score, 4, '.', ''), '0'), '.');
    }

    private function jsonResponse(array $data, int $statusCode = 200): HTTPResponse
    {
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            $body = '{"error":"Unable to encode OpenSearch response."}';
            $statusCode = 500;
        }

        $response = HTTPResponse::create($body, $statusCode);
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }
}
