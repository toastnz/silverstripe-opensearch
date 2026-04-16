# Advanced Configuration

## Related Object Indexing

Related field paths are extracted into the indexed parent document using dot notation:

```php
$this->searchFields = [
    'Title^2',
    'Items.Title^3',
    'Items.SubItems.Title',
];
```

The module stores nested values under matching nested keys in the document source.
If multiple values are found, they are merged into arrays.

## Reindex Propagation for Related Records

`Toast\OpenSearch\Extensions\DataObjectExtension` only handles records relevant to the active index definition. Direct indexing runs for included classes, excluded classes are skipped, and related parent records are reindexed only for relation chains that appear in the index definition.

Example:

- your index definition contains `Items.Title`
- an `Item` record is written or published
- the module resolves reciprocal relations and reindexes the affected parent records

The same approach applies to delete and unpublish events.

## Silent Failure for Record Operations

Automatic record-driven indexing can fail silently when the index is missing, not configured, or unreachable:

```yml
Toast\OpenSearch\Helpers\OpenSearch:
  record_operation_fail_silently: true
```

This setting defaults to `true` and only applies to `Toast\OpenSearch\Extensions\DataObjectExtension` lifecycle hooks. It does not apply to direct index operations such as `/dev/tasks/OpenSearchManagerTask`, `/dev/tasks/OpenSearchReindexTask`, `initIndex()`, `clearIndex()`, or `deleteIndex()`.

## Included Class Query Modifiers

`includedClasses` entries can carry per-class query modifiers:

```php
$this->includedClasses = [
    MyPage::class => [
        'filter' => ['Status' => 'Published'],
        'exclude' => ['Legacy' => 1],
        'sort' => 'LastEdited DESC',
    ],
];
```

These modifiers are applied when the reindex task fetches records.

## Mappings and Implicit Field Expansion

The final mapping is built from:

1. explicit `fields`
2. `searchFields` not already present in `fields`, mapped as `text`
3. `filters` not already present in `fields`, mapped from filter config
4. implicit `ID` and `ClassName`

This means adding a field to `searchFields` or `filters` changes both the indexed document shape and the mapping.

## Index Operations

The helper supports these index operations:

- `initIndex()`
- `deleteIndex()`
- `clearIndex()`
- `updateIndex()`
- `deleteRecord()`
- `deleteFromIndex()`
- `updateRecords()`
- `updateRelatedRecords()`
- `reindexAll()`

`initIndex()` creates the index when missing, or updates settings, mappings, and aliases when it already exists.

## Manager Task

`/dev/tasks/OpenSearchManagerTask` supports these actions:

- `init`
- `reset`
- `clear`
- `delete`
- `status`

`status` prints:

- index name
- index definition
- included classes
- excluded classes
- filter field
- configured filters

## Using Multiple Indexes

You can work with more than one physical OpenSearch index in two ways.

Use multiple index-definition classes:

```php
$results = OpenSearch::singleton()->search('report', App\Search\NewsIndex::class);
```

Or reuse the configured index class with a different index name:

```php
$results = OpenSearch::singleton()->search('report', 'news_archive');
```

In the second case, the helper constructs the configured `index_class` with `'news_archive'` as the constructor argument.

## When to Reindex

Run a full reindex whenever you change:

- `fields`
- `searchFields`
- `filters`
- `includedClasses`
- `excludedClasses`
- `filterField`
- relation paths
- custom field extraction logic on your records
