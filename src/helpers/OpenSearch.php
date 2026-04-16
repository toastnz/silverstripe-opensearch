<?php

namespace Toast\OpenSearch\Helpers;

use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use Toast\OpenSearch\Search\OpenSearchIndex as OpenSearchIndexDefinition;

class OpenSearch
{
    use Configurable;
    use Injectable;

    public ?string $client_host = null;
    public ?string $client_username = null;
    public ?string $client_password = null;
    public bool|string|null $client_verify = null;
    public ?string $index_class = null;
    public mixed $default_search_index = null;
    public bool $record_operation_fail_silently = true;

    private ?Client $searchService = null;

    public function __construct(?Client $searchService = null)
    {
        if ($searchService) {
            $this->searchService = $searchService;
            return;
        }

        $clientOptions = $this->getClientOptions();

        if (!empty($clientOptions['base_uri'])) {
            $this->searchService = (new GuzzleClientFactory())->create($clientOptions);
        }        

        
    }

    public function injected(): void
    {
        $this->index_class = $this->resolveConfiguredIndexClass();
    }

    public function shouldRecordOperationsFailSilently(): bool
    {
        return (bool) $this->config()->get('record_operation_fail_silently');
    }

    public function search($searchTerm, $indexName = null, array $options = [])
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $params = $options;
        $params['index'] = $index->getIndexName();

        if (!isset($params['body'])) {
            $params['body'] = $index->buildSearchBody($searchTerm, $options);
        }

        unset($params['fields'], $params['filters'], $params['query'], $params['return_raw']);

        $response = $this->getSearchService()->search($params);

        if (!empty($options['return_raw'])) {
            return $response;
        }

        return $this->normaliseSearchResponse($response);
    }

    public function initIndex(array $fields = [], $indexName = null, array $options = [])
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $indices = $this->getSearchService()->indices();
        $body = array_replace_recursive(
            $index->getDefinition(),
            $this->buildIndexBody($fields ?: $index->getFields(), $options)
        );
        $params = $this->stripHelperOptions($options);
        $params['index'] = $index->getIndexName();

        if (!$indices->exists(['index' => $index->getIndexName()])) {
            if (!empty($body)) {
                $params['body'] = $body;
            }

            return $indices->create($params);
        }

        $response = [
            'acknowledged' => true,
            'index' => $index->getIndexName(),
            'exists' => true,
        ];

        if (!empty($body['settings'])) {
            $response['settings'] = $indices->putSettings($params + [
                'body' => $body['settings'],
            ]);
        }

        if (!empty($body['mappings'])) {
            $response['mappings'] = $indices->putMapping($params + [
                'body' => $body['mappings'],
            ]);
        }

        if (!empty($body['aliases'])) {
            $response['aliases'] = $indices->updateAliases([
                'body' => [
                    'actions' => $this->buildAliasActions($index->getIndexName(), $body['aliases']),
                ],
            ]);
        }

        return $response;
    }

    public function deleteFromIndex($id, $indexName = null, array $options = [])
    {
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException('A document ID is required to delete a record from an index.');
        }

        $index = $this->getIndexDefinition($indexName, $options);
        $params = $this->stripHelperOptions($options);
        $params['id'] = $id;
        $params['index'] = $index->getIndexName();

        return $this->getSearchService()->delete($params);
    }

    public function updateIndex($record, $id = null, $indexName = null, array $options = [])
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $documentId = $id ?? ($options['id'] ?? null) ?? $index->getDocumentId($record);

        if (is_object($record) && !$index->supportsRecord($record)) {
            if ($documentId === null || $documentId === '') {
                return [
                    'acknowledged' => false,
                    'skipped' => true,
                    'index' => $index->getIndexName(),
                    'reason' => 'record_not_supported_by_index',
                ];
            }

            $response = $this->deleteFromIndex($documentId, $index, $this->withIgnoredStatusCodes($options, [404]));
            $response['removed'] = true;
            $response['reason'] = 'record_not_supported_by_index';

            return $response;
        }

        if (is_object($record) && !$index->shouldIndexRecord($record)) {
            if ($documentId === null || $documentId === '') {
                return [
                    'acknowledged' => false,
                    'skipped' => true,
                    'index' => $index->getIndexName(),
                    'reason' => 'record_not_indexable_without_document_id',
                ];
            }

            return [
                ...$this->deleteFromIndex($documentId, $index, $this->withIgnoredStatusCodes($options, [404])),
                'removed' => true,
                'reason' => 'record_not_indexable_by_filter_field',
            ];
        }

        $document = isset($options['body']) && is_array($options['body'])
            ? $options['body']
            : $index->getDocument($record);
        $params = $this->stripHelperOptions($options);
        $params['index'] = $index->getIndexName();
        $params['body'] = $document;

        if ($documentId !== null && $documentId !== '') {
            $params['id'] = $documentId;
        }

        return $this->getSearchService()->index($params);
    }

    public function deleteRecord($record, $indexName = null, array $options = [])
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $documentId = $index->getDocumentId($record);

        if ($documentId === null || $documentId === '') {
            throw new \InvalidArgumentException('A document ID is required to delete a record from an index.');
        }

        return $this->deleteFromIndex($documentId, $index, $this->withIgnoredStatusCodes($options, [404]));
    }

    public function deleteIndex($name = null, array $options = [])
    {
        $index = $this->getIndexDefinition($name, $options);
        $params = $this->stripHelperOptions($options);
        $params['index'] = $index->getIndexName();

        return $this->getSearchService()->indices()->delete($params);
    }

    public function clearIndex($name = null, array $options = [])
    {
        $index = $this->getIndexDefinition($name, $options);
        $params = $this->stripHelperOptions($options);
        $params['index'] = $index->getIndexName();

        if (!isset($params['body'])) {
            $params['body'] = [
                'query' => [
                    'match_all' => new \stdClass(),
                ],
            ];
        }

        return $this->getSearchService()->deleteByQuery($params);
    }

    public function reindexAll($indexName = null, array $options = []): array
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $count = 0;

        $this->clearIndex($index, $options);

        foreach ($index->getRecordsForReindex() as $record) {
            $response = $this->updateIndex($record, null, $index, $options);

            if (empty($response['skipped']) && empty($response['removed'])) {
                $count++;
            }
        }

        return [
            'acknowledged' => true,
            'index' => $index->getIndexName(),
            'count' => $count,
        ];
    }

    public function updateRelatedRecords(DataObject $record, $indexName = null, array $options = []): array
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $count = 0;

        foreach ($index->getDependentRecords($record) as $dependentRecord) {
            $response = $this->updateIndex($dependentRecord, null, $index, $options);

            if (empty($response['skipped']) && empty($response['removed'])) {
                $count++;
            }
        }

        return [
            'acknowledged' => true,
            'index' => $index->getIndexName(),
            'count' => $count,
        ];
    }

    public function updateRecords(iterable $records, $indexName = null, array $options = []): array
    {
        $index = $this->getIndexDefinition($indexName, $options);
        $count = 0;

        foreach ($records as $record) {
            if (!$record instanceof DataObject) {
                continue;
            }

            $response = $this->updateIndex($record, null, $index, $options);

            if (empty($response['skipped']) && empty($response['removed'])) {
                $count++;
            }
        }

        return [
            'acknowledged' => true,
            'index' => $index->getIndexName(),
            'count' => $count,
        ];
    }

    public function getSearchService(): Client
    {
        if ($this->searchService instanceof Client) {
            return $this->searchService;
        }

        $clientOptions = $this->getClientOptions();

        if (empty($clientOptions['base_uri'])) {
            throw new \RuntimeException(
                'OpenSearch client is not configured. Define client_host for ' . __CLASS__ . '.'
            );
        }

        $this->searchService = (new GuzzleClientFactory())->create($clientOptions);

        return $this->searchService;
    }

    public function getIndexDefinition($indexName = null, array $options = []): OpenSearchIndexDefinition
    {
        $index = $indexName ?? $this->default_search_index ?? $this->resolveConfiguredIndexClass();

        if (!$index) {
            throw new \RuntimeException(
                'You must either define a default search index in your config or define one when calling this method.'
            );
        }

        if ($index instanceof OpenSearchIndexDefinition) {
            return $index;
        }

        if (is_string($index) && class_exists($index) && is_a($index, OpenSearchIndexDefinition::class, true)) {
            return Injector::inst()->get($index);
        }

        if (is_string($index) && $index !== '') {
            return Injector::inst()->create($this->resolveConfiguredIndexClass(), $index);
        }

        throw new \RuntimeException('The configured default search index must be an index class or a valid index name.');
    }

    protected function resolveConfiguredIndexClass(): string
    {
        $indexClass = $this->index_class ?? $this->config()->get('index_class');

        if (is_string($indexClass) && $indexClass !== '' && is_a($indexClass, OpenSearchIndexDefinition::class, true)) {
            return $indexClass;
        }

        return OpenSearchIndexDefinition::class;
    }

    protected function getClientOptions(): array
    {
        $clientOptions = [];
        $host = $this->client_host;
        $username = $this->client_username;
        $password = $this->client_password;

        if ($host) {
            $clientOptions['base_uri'] = $host;
        }

        if ($username !== null && $username !== '' && $password !== null) {
            $clientOptions['auth'] = [$username, $password];
        }

        $verify = $this->normaliseVerifyOption($this->client_verify);

        if ($verify !== null) {
            $clientOptions['verify'] = $verify;
        }

        return $clientOptions;
    }

    protected function normaliseVerifyOption(bool|string|null $verify): bool|string|null
    {
        if ($verify === null) {
            return null;
        }

        if (is_bool($verify)) {
            return $verify;
        }

        $trimmedVerify = trim($verify);

        if ($trimmedVerify === '') {
            return null;
        }

        $normalisedVerify = strtolower($trimmedVerify);

        if (in_array($normalisedVerify, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($normalisedVerify, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return $trimmedVerify;
    }

    protected function normaliseSearchResponse(array $response): ArrayData
    {
        $hits = $response['hits']['hits'] ?? [];
        $matches = ArrayList::create(array_map(function (array $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];

            return ArrayData::create(array_merge($source, [
                'DocumentID' => $hit['_id'] ?? null,
                'Index' => $hit['_index'] ?? null,
                'Score' => $hit['_score'] ?? null,
                'Fields' => $hit['fields'] ?? [],
                'Highlight' => $hit['highlight'] ?? [],
                'Source' => $source,
                'Raw' => $hit,
            ]));
        }, $hits));

        return ArrayData::create([
            'Matches' => $matches,
            'Total' => $response['hits']['total']['value'] ?? count($hits),
            'MaxScore' => $response['hits']['max_score'] ?? null,
            'Took' => $response['took'] ?? null,
            'TimedOut' => $response['timed_out'] ?? false,
            'Aggregations' => $response['aggregations'] ?? [],
            'Raw' => $response,
        ]);
    }

    protected function buildIndexBody(array $fields, array $options): array
    {
        $body = $options['body'] ?? [];

        if (isset($options['settings']) && !isset($body['settings'])) {
            $body['settings'] = $options['settings'];
        }

        if (isset($options['mappings']) && !isset($body['mappings'])) {
            $body['mappings'] = $options['mappings'];
        }

        if (isset($options['aliases']) && !isset($body['aliases'])) {
            $body['aliases'] = $options['aliases'];
        }

        if (!empty($fields)) {
            $body['mappings'] = $body['mappings'] ?? [];
            $body['mappings']['properties'] = array_replace(
                $body['mappings']['properties'] ?? [],
                $fields
            );
        }

        return $body;
    }

    protected function buildAliasActions(string $index, array $aliases): array
    {
        $actions = [];

        foreach ($aliases as $alias => $aliasConfig) {
            if (is_int($alias)) {
                $alias = $aliasConfig;
                $aliasConfig = [];
            }

            $actions[] = [
                'add' => array_merge(
                    [
                        'index' => $index,
                        'alias' => $alias,
                    ],
                    is_array($aliasConfig) ? $aliasConfig : []
                ),
            ];
        }

        return $actions;
    }

    protected function stripHelperOptions(array $options): array
    {
        unset(
            $options['fields'],
            $options['settings'],
            $options['mappings'],
            $options['aliases'],
            $options['filters'],
            $options['query'],
            $options['return_raw']
        );

        return $options;
    }

    protected function withIgnoredStatusCodes(array $options, array $statusCodes): array
    {
        $ignoredStatusCodes = $options['client']['ignore'] ?? [];

        if (!is_array($ignoredStatusCodes)) {
            $ignoredStatusCodes = [$ignoredStatusCodes];
        }

        foreach ($statusCodes as $statusCode) {
            if (!in_array($statusCode, $ignoredStatusCodes, true)) {
                $ignoredStatusCodes[] = $statusCode;
            }
        }

        $options['client']['ignore'] = $ignoredStatusCodes;

        return $options;
    }
}
