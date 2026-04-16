<?php

namespace Toast\OpenSearch\Search;

use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Traversable;

class OpenSearchIndex
{
    use Injectable;

    protected string $indexName;
    protected array $fields;
    protected array $settings;
    protected array $aliases;
    protected array $searchFields;
    protected array $filters;
    protected array $includedClasses;
    protected array $excludedClasses;
    protected string $filterField;

    public function __construct(?string $indexName = null)
    {
        $this->indexName = $this->indexName ?? $indexName ?? 'default_index';
        $this->fields = $this->fields ?? [
            'Title' => [
                'type' => 'text',
            ],
            'Content' => [
                'type' => 'text',
            ],
        ];
        $this->settings = $this->settings ?? [];
        $this->aliases = $this->aliases ?? [];
        $this->searchFields = $this->searchFields ?? ['Title^2', 'Content'];
        $this->filters = $this->filters ?? [];
        $this->includedClasses = $this->includedClasses ?? [SiteTree::class];
        $this->excludedClasses = $this->excludedClasses ?? [
            ErrorPage::class,
            RedirectorPage::class,
            VirtualPage::class,
        ];

        $this->filterField = $this->filterField ?? 'ShowInSearch';
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getMappings(): array
    {
        $properties = $this->buildMappingProperties();

        if ($properties === []) {
            return [];
        }

        return [
            'properties' => $properties,
        ];
    }

    public function getDefinition(): array
    {
        $definition = [];

        if ($this->settings !== []) {
            $definition['settings'] = $this->settings;
        }

        $mappings = $this->getMappings();
        if ($mappings !== []) {
            $definition['mappings'] = $mappings;
        }

        if ($this->aliases !== []) {
            $definition['aliases'] = $this->aliases;
        }

        return $definition;
    }

    public function getConfiguredSearchFields(): array
    {
        if ($this->searchFields !== []) {
            return $this->searchFields;
        }

        if ($this->fields !== []) {
            return array_keys($this->fields);
        }

        return ['*'];
    }

    public function getSearchFields(): array
    {
        return $this->applyConfiguredSearchFieldWeights($this->getConfiguredSearchFields());
    }

    public function getConfigurableSearchFields(): array
    {
        $fields = [];

        foreach ($this->getConfiguredSearchFields() as $field) {
            if (!is_string($field)) {
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField !== null) {
                $fields[$normalisedField] = $normalisedField;
            }
        }

        return array_values($fields);
    }

    public function getConfiguredSearchFieldWeights(): array
    {
        $weights = [];

        foreach ($this->getConfiguredSearchFields() as $field) {
            if (!is_string($field)) {
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField === null) {
                continue;
            }

            $weights[$normalisedField] = $this->extractConfiguredFieldWeight($field) ?? 1.0;
        }

        return $weights;
    }

    public function buildSearchQuery($searchTerm, array $options = []): array
    {
        $searchTerm = trim((string) $searchTerm);
        $filterQueries = $this->buildFilterQueries((array) ($options['filters'] ?? []));

        if ($searchTerm === '' && $filterQueries === []) {
            return [
                'match_all' => new \stdClass(),
            ];
        }

        $mustQueries = [];

        if ($searchTerm !== '') {
            $mustQueries[] = $this->buildSearchTermQuery($searchTerm, $options);
        }

        if ($mustQueries !== [] && $filterQueries === []) {
            return $mustQueries[0];
        }

        $query = [
            'bool' => [],
        ];

        if ($mustQueries !== []) {
            $query['bool']['must'] = $mustQueries;
        }

        if ($filterQueries !== []) {
            $query['bool']['filter'] = $filterQueries;
        }

        return $query;
    }

    public function buildSearchBody($searchTerm, array $options = []): array
    {
        $body = [
            'query' => $options['query'] ?? $this->buildSearchQuery($searchTerm, $options),
        ];

        $fineTuneSettings = $this->getCustomFineTuneSettings();

        if (
            array_key_exists('min_score', $fineTuneSettings)
            && (
                array_key_exists('query', $options)
                || trim((string) $searchTerm) !== ''
            )
        ) {
            $body['min_score'] = $fineTuneSettings['min_score'];
        }

        return $body;
    }

    public function getIncludedClasses(): array
    {
        $classes = [];

        foreach ($this->includedClasses as $key => $value) {
            $class = is_string($key) ? $key : $value;

            if (is_string($class) && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function getExcludedClasses(): array
    {
        $classes = [];

        foreach ($this->excludedClasses as $key => $value) {
            $class = is_string($key) ? $key : $value;

            if (is_string($class) && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function getIndexedClasses(): array
    {
        return $this->getIncludedClasses();
    }

    public function getFilterField(): string
    {
        return $this->filterField;
    }

    public function getFilters(): array
    {
        return $this->normaliseConfiguredFilters();
    }

    public function supportsRecord($record): bool
    {
        if (!is_object($record)) {
            return true;
        }

        $classes = $this->getIncludedClasses();

        if ($classes === []) {
            return false;
        }

        if ($this->isExcludedRecord($record)) {
            return false;
        }

        foreach ($classes as $class) {
            if (is_a($record, $class)) {
                return true;
            }
        }

        return false;
    }

    public function supportsRelatedRecordChanges(DataObject $record): bool
    {
        if ($this->isExcludedRecord($record)) {
            return false;
        }

        return $this->getRelationPrefixesForRecord($record) !== [];
    }

    public function shouldHandleRecordHooks(DataObject $record): bool
    {
        return $this->supportsRecord($record) || $this->supportsRelatedRecordChanges($record);
    }

    public function getDocumentId($record)
    {
        if (is_array($record)) {
            if (!empty($record['_id'])) {
                return $record['_id'];
            }

            return $this->buildDocumentIdentifier(
                $record['ClassName'] ?? null,
                $record['id'] ?? $record['ID'] ?? null
            );
        }

        if (is_object($record) && isset($record->ID) && $record->ID !== null && $record->ID !== '') {
            return $this->buildDocumentIdentifier($record->ClassName ?? $record::class, $record->ID);
        }

        return null;
    }

    public function shouldIndexRecord($record): bool
    {
        if (!is_object($record)) {
            return true;
        }

        if (!$this->supportsRecord($record)) {
            return false;
        }

        if (!$record instanceof DataObject) {
            return true;
        }

        $filterField = trim($this->getFilterField());

        if ($filterField === '') {
            return true;
        }

        $filterValue = $this->extractFilterFieldValue($record, $filterField);

        if ($filterValue === null) {
            return true;
        }

        return $this->normaliseFilterFieldValue($filterValue);
    }

    public function getDocument($record): array
    {
        if (is_array($record)) {
            return $this->filterDocumentFields($record);
        }

        if (!is_object($record)) {
            return [];
        }

        $document = [];
        $fieldNames = $this->getDocumentFieldNames();

        if ($fieldNames === []) {
            return $this->normaliseObjectDocument($record);
        }

        foreach ($fieldNames as $field) {
            $this->assignDocumentFieldValue($document, $field, $this->extractFieldValue($record, $field));
        }

        $this->appendImplicitDocumentFields($document, $record);

        return $document;
    }

    public function getRecordsForReindex(): iterable
    {
        foreach ($this->includedClasses as $key => $value) {
            $class = is_string($key) ? $key : $value;
            $config = is_string($key) && is_array($value) ? $value : [];

            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, DataObject::class)) {
                continue;
            }

            $list = $this->getClassRecords($class, $config);

            foreach ($list as $record) {
                if ($record instanceof DataObject && !$this->shouldIndexRecord($record)) {
                    continue;
                }

                yield $record;
            }
        }
    }

    public function getDependentRecords(DataObject $record): array
    {
        $dependentRecords = [];

        foreach ($this->getRelationPrefixesForRecord($record) as $relationPrefix) {
            foreach ($this->resolveRelationPrefix($record, $relationPrefix) as $dependentRecord) {
                if (!$dependentRecord instanceof DataObject || !$dependentRecord->ID) {
                    continue;
                }

                $dependentRecords[$this->buildRecordCacheKey($dependentRecord)] = $dependentRecord;
            }
        }

        return array_values($dependentRecords);
    }

    protected function getClassRecords(string $class, array $config)
    {
        if (class_exists(Versioned::class) && DataObject::singleton($class)->hasExtension(Versioned::class)) {
            $list = Versioned::get_by_stage($class, Versioned::LIVE);
        } else {
            $list = $class::get();
        }

        if (!empty($config['filter']) && is_array($config['filter'])) {
            $list = $list->filter($config['filter']);
        }

        if (!empty($config['exclude']) && is_array($config['exclude'])) {
            $list = $list->exclude($config['exclude']);
        }

        if (!empty($config['sort'])) {
            $list = $list->sort($config['sort']);
        }

        return $list;
    }

    protected function isExcludedRecord(object $record): bool
    {
        foreach ($this->getExcludedClasses() as $excludedClass) {
            if (is_a($record, $excludedClass)) {
                return true;
            }
        }

        return false;
    }

    protected function getRelationPrefixesForRecord(DataObject $record): array
    {
        $recordClass = $record::class;
        $relationPrefixes = [];

        foreach ($this->getIncludedClasses() as $indexedClass) {
            foreach ($this->getIndexedRelationChains($indexedClass) as $relationChain) {
                $currentClass = $indexedClass;
                $prefix = [];

                foreach ($relationChain as $relationName) {
                    $relationClass = $this->resolveRelationClass($currentClass, $relationName);

                    if ($relationClass === null) {
                        break;
                    }

                    $prefix[] = [
                        'parentClass' => $currentClass,
                        'relationName' => $relationName,
                    ];
                    $currentClass = $relationClass;

                    if (is_a($recordClass, $currentClass, true)) {
                        $relationPrefixes[$this->buildRelationPrefixKey($prefix)] = $prefix;
                    }
                }
            }
        }

        return array_values($relationPrefixes);
    }

    protected function getIndexedRelationChains(string $indexedClass): array
    {
        $relationChains = [];

        foreach ($this->getDocumentFieldNames() as $field) {
            $segments = explode('.', $field);

            if (count($segments) < 2) {
                continue;
            }

            array_pop($segments);

            if ($segments === []) {
                continue;
            }

            $currentClass = $indexedClass;
            $resolvedChain = [];
            $validChain = true;

            foreach ($segments as $segment) {
                $relationClass = $this->resolveRelationClass($currentClass, $segment);

                if ($relationClass === null) {
                    $validChain = false;
                    break;
                }

                $resolvedChain[] = $segment;
                $currentClass = $relationClass;
            }

            if ($validChain && $resolvedChain !== []) {
                $relationChains[implode('.', $resolvedChain)] = $resolvedChain;
            }
        }

        return array_values($relationChains);
    }

    protected function resolveRelationClass(string $class, string $relationName): ?string
    {
        if (!class_exists($class) || !is_subclass_of($class, DataObject::class)) {
            return null;
        }

        $relationClass = DataObject::singleton($class)->getRelationClass($relationName);

        if (!is_string($relationClass) || $relationClass === '' || $relationClass === DataObject::class) {
            return null;
        }

        return class_exists($relationClass) ? $relationClass : null;
    }

    protected function resolveRelationPrefix(DataObject $record, array $relationPrefix): array
    {
        $records = [$record];

        for ($index = count($relationPrefix) - 1; $index >= 0; $index--) {
            $step = $relationPrefix[$index];
            $resolvedRecords = [];

            foreach ($records as $candidate) {
                if (!$candidate instanceof DataObject || !$candidate->ID) {
                    continue;
                }

                $reciprocal = $candidate->inferReciprocalComponent($step['parentClass'], $step['relationName']);
                $this->appendRelatedRecords($resolvedRecords, $reciprocal);
            }

            if ($resolvedRecords === []) {
                return [];
            }

            $records = array_values($resolvedRecords);
        }

        return $records;
    }

    protected function appendRelatedRecords(array &$records, $related): void
    {
        if ($related instanceof DataObject) {
            if ($related->ID) {
                $records[$this->buildRecordCacheKey($related)] = $related;
            }

            return;
        }

        if (!$this->isIterableFieldTarget($related)) {
            return;
        }

        foreach ($related as $item) {
            if ($item instanceof DataObject && $item->ID) {
                $records[$this->buildRecordCacheKey($item)] = $item;
            }
        }
    }

    protected function buildRelationPrefixKey(array $relationPrefix): string
    {
        return implode('>', array_map(function (array $step): string {
            return $step['parentClass'] . '.' . $step['relationName'];
        }, $relationPrefix));
    }

    protected function buildRecordCacheKey(DataObject $record): string
    {
        return $record::class . ':' . $record->ID;
    }

    protected function buildDocumentIdentifier($className, $recordId): ?string
    {
        if ($recordId === null || $recordId === '') {
            return null;
        }

        if (is_string($className) && $className !== '') {
            return $className . ':' . $recordId;
        }

        return (string) $recordId;
    }

    protected function filterDocumentFields(array $document): array
    {
        $fieldNames = $this->getDocumentFieldNames();

        if ($fieldNames === []) {
            return $document;
        }

        $filtered = [];

        foreach ($fieldNames as $field) {
            $this->assignDocumentFieldValue($filtered, $field, $this->extractFieldValue($document, $field));
        }

        return $filtered;
    }

    protected function extractFilterFieldValue(DataObject $record, string $field)
    {
        $segments = explode('.', $field);
        $target = $record;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            $target = $this->extractSegmentValue($target, $segment);

            if ($target === null) {
                return null;
            }
        }

        return $this->extractFilterSegmentValue($target, array_shift($segments));
    }

    protected function extractFilterSegmentValue($target, ?string $segment)
    {
        if ($segment === null || $target === null) {
            return null;
        }

        if ($this->isIterableFieldTarget($target)) {
            $values = [];

            foreach ($target as $item) {
                $this->appendExtractedValue($values, $this->extractFilterSegmentValue($item, $segment));
            }

            return $values === [] ? null : $values;
        }

        if ($target instanceof DataObject) {
            if ($target->hasField($segment)) {
                return $target->getField($segment);
            }

            $getter = 'get' . $segment;

            if ($target->hasMethod($segment)) {
                return $target->$segment();
            }

            if ($target->hasMethod($getter)) {
                return $target->$getter();
            }
        }

        return $this->extractSegmentValue($target, $segment);
    }

    protected function normaliseFilterFieldValue($value): bool
    {
        $value = $this->normaliseExtractedValue($value);

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            if (in_array($value, ['', '0', 'false', 'no', 'off'], true)) {
                return false;
            }

            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    protected function normaliseObjectDocument(object $record): array
    {
        if (method_exists($record, 'toMap')) {
            return (array) $record->toMap();
        }

        if (method_exists($record, 'toArray')) {
            return (array) $record->toArray();
        }

        if ($record instanceof \JsonSerializable) {
            return (array) $record->jsonSerialize();
        }

        return get_object_vars($record);
    }

    protected function extractFieldValue($record, string $field)
    {
        return $this->extractPathValue($record, explode('.', $field));
    }

    protected function extractPathValue($target, array $segments)
    {
        if ($target === null) {
            return null;
        }

        if ($segments === []) {
            return $this->normaliseExtractedValue($target);
        }

        if ($this->isIterableFieldTarget($target)) {
            $values = [];

            foreach ($target as $item) {
                $this->appendExtractedValue($values, $this->extractPathValue($item, $segments));
            }

            return $values === [] ? null : $values;
        }

        $segment = array_shift($segments);
        $value = $this->extractSegmentValue($target, $segment);

        return $this->extractPathValue($value, $segments);
    }

    protected function normaliseExtractedValue($value)
    {
        if ($value instanceof DBField) {
            if (method_exists($value, 'Plain')) {
                return $this->normaliseStringValue((string) $value->Plain());
            }

            $value = $value->getValue();
        }

        if ($value instanceof DataObject) {
            return $value->ID;
        }

        if ($this->isIterableFieldTarget($value)) {
            $values = [];

            foreach ($value as $item) {
                $this->appendExtractedValue($values, $this->normaliseExtractedValue($item));
            }

            return $values === [] ? null : $values;
        }

        if (is_string($value)) {
            return $this->normaliseStringValue($value);
        }

        return $value;
    }

    protected function normaliseStringValue(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
    }

    protected function appendExtractedValue(array &$values, $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $this->appendExtractedValue($values, $item);
            }

            return;
        }

        $values[] = $value;
    }

    protected function isIterableFieldTarget($target): bool
    {
        if (is_array($target)) {
            return array_is_list($target);
        }

        return $target instanceof Traversable;
    }

    protected function getDocumentFieldNames(): array
    {
        $fieldNames = [];

        foreach (array_keys($this->fields) as $field) {
            $normalisedField = $this->normaliseFieldReference((string) $field);

            if ($normalisedField !== null) {
                $fieldNames[$normalisedField] = $normalisedField;
            }
        }

        foreach ($this->getConfiguredSearchFields() as $field) {
            if (!is_string($field)) {
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField !== null) {
                $fieldNames[$normalisedField] = $normalisedField;
            }
        }

        foreach (array_keys($this->getFilters()) as $field) {
            $fieldNames[$field] = $field;
        }

        return array_values($fieldNames);
    }

    protected function normaliseFieldReference(string $field): ?string
    {
        $field = trim(preg_replace('/\\^[0-9]+(?:\\.[0-9]+)?$/', '', $field) ?? '');

        if ($field === '' || $field === '*') {
            return null;
        }

        return $field;
    }

    protected function assignDocumentFieldValue(array &$document, string $field, $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (!str_contains($field, '.')) {
            $document[$field] = $value;
            return;
        }

        $segments = explode('.', $field);
        $lastSegment = array_pop($segments);
        $cursor = &$document;

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        if (array_key_exists($lastSegment, $cursor)) {
            $cursor[$lastSegment] = $this->mergeDocumentFieldValues($cursor[$lastSegment], $value);
            return;
        }

        $cursor[$lastSegment] = $value;
    }

    protected function mergeDocumentFieldValues($existingValue, $newValue)
    {
        if ($existingValue === $newValue) {
            return $existingValue;
        }

        $mergedValues = [];
        $this->appendExtractedValue($mergedValues, $existingValue);
        $this->appendExtractedValue($mergedValues, $newValue);

        return $mergedValues;
    }

    protected function buildMappingProperties(): array
    {
        $properties = [];

        foreach ($this->getConfiguredMappingFields() as $field => $config) {
            $this->assignMappingField($properties, explode('.', $field), $config);
        }

        foreach ($this->getImplicitMappingFields() as $field => $config) {
            if (!array_key_exists($field, $properties)) {
                $properties[$field] = $config;
            }
        }

        return $properties;
    }

    protected function getImplicitMappingFields(): array
    {
        return [
            'ID' => ['type' => 'integer'],
            'ClassName' => ['type' => 'keyword'],
        ];
    }

    protected function appendImplicitDocumentFields(array &$document, object $record): void
    {
        if (!$record instanceof DataObject) {
            return;
        }

        if (!array_key_exists('ID', $document) || !$document['ID']) {
            $document['ID'] = (int) $record->ID;
        }

        if (!array_key_exists('ClassName', $document) || !$document['ClassName']) {
            $document['ClassName'] = $record->ClassName;
        }
    }

    protected function getConfiguredMappingFields(): array
    {
        $mappingFields = [];

        foreach ($this->fields as $field => $config) {
            if (!is_string($field)) {
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField !== null) {
                $mappingFields[$normalisedField] = is_array($config) ? $config : [];
            }
        }

        foreach ($this->getConfiguredSearchFields() as $field) {
            if (!is_string($field)) {
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField !== null && !array_key_exists($normalisedField, $mappingFields)) {
                $mappingFields[$normalisedField] = ['type' => 'text'];
            }
        }

        foreach ($this->getFilters() as $field => $config) {
            if (!array_key_exists($field, $mappingFields)) {
                $mappingFields[$field] = $config;
            }
        }

        return $mappingFields;
    }

    protected function normaliseConfiguredFilters(): array
    {
        $normalisedFilters = [];

        foreach ($this->filters as $field => $config) {
            if (is_int($field)) {
                $field = is_string($config) ? $config : null;
                $config = [];
            }

            if (!is_string($field)) {
                continue;
            }

            $field = $this->normaliseFieldReference($field);

            if ($field === null) {
                continue;
            }

            if (is_string($config)) {
                $config = ['type' => $config];
            }

            if (!is_array($config)) {
                $config = [];
            }

            $config['type'] = $config['type'] ?? 'keyword';
            $normalisedFilters[$field] = $config;
        }

        return $normalisedFilters;
    }

    protected function buildFilterQueries(array $filters): array
    {
        $configuredFilters = $this->getFilters();
        $queries = [];

        foreach ($filters as $field => $value) {
            if (!is_string($field) || !array_key_exists($field, $configuredFilters)) {
                continue;
            }

            $query = $this->buildFilterQuery($field, $value, $configuredFilters[$field]);

            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    protected function buildFilterQuery(string $field, $value, array $config): ?array
    {
        $type = strtolower((string) ($config['type'] ?? 'keyword'));

        if (is_array($value) && !array_is_list($value)) {
            return $this->buildAssociativeFilterQuery($field, $value, $type);
        }

        if (is_array($value)) {
            return $value === [] ? null : ['terms' => [$field => $value]];
        }

        if (is_string($value)) {
            $parsedRange = $this->parseStringRangeFilter($value);

            if ($parsedRange !== null) {
                return ['range' => [$field => $parsedRange]];
            }
        }

        return $this->buildExactFilterQuery($field, $value, $type);
    }

    protected function buildAssociativeFilterQuery(string $field, array $value, string $type): ?array
    {
        if (array_key_exists('between', $value) && is_array($value['between']) && count($value['between']) === 2) {
            return [
                'range' => [
                    $field => [
                        'gte' => $value['between'][0],
                        'lte' => $value['between'][1],
                    ],
                ],
            ];
        }

        if (array_key_exists('in', $value) && is_array($value['in'])) {
            return $value['in'] === [] ? null : ['terms' => [$field => array_values($value['in'])]];
        }

        if (array_key_exists('not_in', $value) && is_array($value['not_in']) && $value['not_in'] !== []) {
            return [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                $field => array_values($value['not_in']),
                            ],
                        ],
                    ],
                ],
            ];
        }

        if (array_key_exists('neq', $value)) {
            $exactQuery = $this->buildExactFilterQuery($field, $value['neq'], $type);

            if ($exactQuery === null) {
                return null;
            }

            return [
                'bool' => [
                    'must_not' => [
                        $exactQuery,
                    ],
                ],
            ];
        }

        $range = [];

        foreach (['gt', 'gte', 'lt', 'lte'] as $operator) {
            if (array_key_exists($operator, $value)) {
                $range[$operator] = $value[$operator];
            }
        }

        if ($range !== []) {
            return ['range' => [$field => $range]];
        }

        if (array_key_exists('value', $value)) {
            return $this->buildExactFilterQuery($field, $value['value'], $type);
        }

        return null;
    }

    protected function buildExactFilterQuery(string $field, $value, string $type): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($type === 'text') {
            return ['match_phrase' => [$field => $value]];
        }

        return ['term' => [$field => $value]];
    }

    protected function parseStringRangeFilter(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(>=|<=|>|<)\s*(.+)$/', $value, $matches)) {
            return [
                $this->normaliseRangeOperator($matches[1]) => trim($matches[2]),
            ];
        }

        if (preg_match('/^(.+)\.\.(.+)$/', $value, $matches)) {
            return [
                'gte' => trim($matches[1]),
                'lte' => trim($matches[2]),
            ];
        }

        return null;
    }

    protected function normaliseRangeOperator(string $operator): string
    {
        return match ($operator) {
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
            default => $operator,
        };
    }

    protected function assignMappingField(array &$properties, array $segments, array $config): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segments === []) {
            $properties[$segment] = array_replace_recursive($properties[$segment] ?? [], $config);
            return;
        }

        if (!isset($properties[$segment]) || !is_array($properties[$segment])) {
            $properties[$segment] = [];
        }

        $properties[$segment]['properties'] = $properties[$segment]['properties'] ?? [];

        $this->assignMappingField($properties[$segment]['properties'], $segments, $config);

        if (
            isset($properties[$segment]['type'])
            && !in_array($properties[$segment]['type'], ['object', 'nested'], true)
        ) {
            unset($properties[$segment]['type']);
        }
    }

    protected function extractSegmentValue($target, string $segment)
    {
        if ($target instanceof DataObject) {
            $getter = 'get' . $segment;

            if ($target->hasMethod($segment)) {
                return $target->$segment();
            }

            if ($target->hasMethod($getter)) {
                return $target->$getter();
            }

            if ($target->hasField($segment)) {
                return $target->getField($segment);
            }
        }

        if ($target instanceof DBField) {
            return $target->getValue();
        }

        if (is_array($target) && array_key_exists($segment, $target)) {
            return $target[$segment];
        }

        if (is_object($target) && isset($target->$segment)) {
            return $target->$segment;
        }

        return null;
    }

    protected function applyConfiguredSearchFieldWeights(array $fields): array
    {
        $customWeights = $this->getCustomSearchFieldWeights();

        if ($customWeights === []) {
            return $fields;
        }

        $weightedFields = [];

        foreach ($fields as $field) {
            if (!is_string($field)) {
                $weightedFields[] = $field;
                continue;
            }

            $normalisedField = $this->normaliseFieldReference($field);

            if ($normalisedField === null || !array_key_exists($normalisedField, $customWeights)) {
                $weightedFields[] = $field;
                continue;
            }

            $weightedFields[] = $this->formatWeightedSearchField($normalisedField, $customWeights[$normalisedField]);
        }

        return $weightedFields;
    }

    protected function applyConfiguredSearchFineTuneSettings(array $multiMatch): array
    {
        $fineTuneSettings = OpenSearchFineTuneSettings::sanitiseForMultiMatch($this->getCustomFineTuneSettings());

        if ($fineTuneSettings === []) {
            return $multiMatch;
        }

        foreach (['type', 'operator', 'minimum_should_match', 'fuzziness'] as $key) {
            if (array_key_exists($key, $fineTuneSettings)) {
                $multiMatch[$key] = $fineTuneSettings[$key];
            }
        }

        return $multiMatch;
    }

    protected function buildSearchTermQuery(string $searchTerm, array $options = []): array
    {
        $queries = OpenSearchSynonymSettings::expandSearchTerm($searchTerm, $this->getCustomSynonymRules());

        if ($queries === []) {
            $queries = [$searchTerm];
        }

        if (count($queries) === 1) {
            return [
                'multi_match' => $this->buildMultiMatchQuery($queries[0], $options),
            ];
        }

        $should = [];

        foreach ($queries as $index => $query) {
            $multiMatch = $this->buildMultiMatchQuery($query, $options);

            if ($index === 0) {
                $multiMatch['boost'] = 2.0;
            } else {
                $multiMatch['boost'] = 1.0;
            }

            $should[] = [
                'multi_match' => $multiMatch,
            ];
        }

        return [
            'bool' => [
                'should' => $should,
                'minimum_should_match' => 1,
            ],
        ];
    }

    protected function buildMultiMatchQuery(string $query, array $options = []): array
    {
        $multiMatch = [
            'query' => $query,
            'fields' => $options['fields'] ?? $this->getSearchFields(),
        ];

        return $this->applyConfiguredSearchFineTuneSettings($multiMatch);
    }

    protected function getCustomSearchFieldWeights(): array
    {
        try {
            $siteConfig = SiteConfig::current_site_config();
        } catch (\Throwable) {
            return [];
        }

        $configuredFields = array_flip($this->getConfigurableSearchFields());
        $storedSettings = json_decode((string) ($siteConfig->OpenSearchRelevanceSettings ?? ''), true);

        if (!is_array($storedSettings)) {
            return [];
        }

        $weights = [];

        foreach ($storedSettings as $field => $weight) {
            if (!is_string($field) || !isset($configuredFields[$field])) {
                continue;
            }

            $normalisedWeight = $this->normaliseSearchFieldWeight($weight);

            if ($normalisedWeight === null) {
                continue;
            }

            $weights[$field] = $normalisedWeight;
        }

        return $weights;
    }

    protected function getCustomFineTuneSettings(): array
    {
        try {
            $siteConfig = SiteConfig::current_site_config();
        } catch (\Throwable) {
            return [];
        }

        $storedSettings = json_decode((string) ($siteConfig->OpenSearchFineTuneSettings ?? ''), true);

        if (!is_array($storedSettings)) {
            return [];
        }

        return OpenSearchFineTuneSettings::normalise($storedSettings);
    }

    protected function getCustomSynonymRules(): array
    {
        try {
            $siteConfig = SiteConfig::current_site_config();
        } catch (\Throwable) {
            return [];
        }

        if (!$siteConfig || !$siteConfig->hasMethod('OpenSearchSynonyms')) {
            return [];
        }

        try {
            return OpenSearchSynonymSettings::buildRules($siteConfig->OpenSearchSynonyms());
        } catch (\Throwable) {
            return [];
        }
    }

    protected function extractConfiguredFieldWeight(string $field): ?float
    {
        if (!preg_match('/\^([0-9]+(?:\.[0-9]+)?)$/', trim($field), $matches)) {
            return null;
        }

        $weight = round((float) $matches[1], 2);

        return $weight > 0 ? $weight : null;
    }

    protected function normaliseSearchFieldWeight($weight): ?float
    {
        if (is_string($weight)) {
            $weight = trim($weight);
        }

        if ($weight === null || $weight === '' || !is_numeric($weight)) {
            return null;
        }

        $weight = round((float) $weight, 1);

        if ($weight < 1 || $weight > 10) {
            return null;
        }

        return $weight;
    }

    protected function formatWeightedSearchField(string $field, float $weight): string
    {
        return sprintf(
            '%s^%s',
            $field,
            rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.')
        );
    }
}
