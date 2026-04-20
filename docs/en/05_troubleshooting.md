# Troubleshooting

## No Search Results

Check:

- `client_host` points to a reachable OpenSearch instance
- credentials are correct if authentication is enabled
- the index exists
- the index has documents
- your index definition includes the classes you expect

Useful check:

```text
/dev/tasks/OpenSearchManagerTask?action=status
```

## The Wrong Index Class Is Being Used

Check the helper config for `Toast\OpenSearch\Helpers\OpenSearch`.
The module resolves `index_class` after dependency injection, so configure it on the helper itself, typically via Injector properties:

```yml
SilverStripe\Core\Injector\Injector:
  Toast\OpenSearch\Helpers\OpenSearch:
    properties:
      index_class: App\Search\SiteSearchIndex
```

If no valid class is configured, the helper falls back to `Toast\OpenSearch\Search\OpenSearchIndex`.

## Record Changes Do Not Raise OpenSearch Errors

Check `record_operation_fail_silently` on `Toast\OpenSearch\Helpers\OpenSearch`.

```yml
Toast\OpenSearch\Helpers\OpenSearch:
  record_operation_fail_silently: true
```

This setting defaults to `true`, so automatic indexing triggered by `Toast\OpenSearch\Extensions\DataObjectExtension` can fail quietly when the index is missing, not configured, or unreachable. It does not apply to direct index operations such as `/dev/tasks/OpenSearchManagerTask` or `/dev/tasks/OpenSearchReindexTask`.

## Records Are Missing After Reindex

Common causes:

- the record class is not in `includedClasses`
- the record class is in `excludedClasses`
- `filterField` resolves false
- the record is versioned and not present on `Live`
- per-class `filter` or `exclude` modifiers in `includedClasses` are removing it
- the record only affects indexed parents through relation chains that are not present in the index definition

## A Record Should Be Indexed But Is Not

Check `filterField`.

Example:

```php
$this->filterField = 'ShowInSearch';
```

For the final segment, the module checks:

1. DB field
2. same-named method
3. getter like `getShowInSearch()`
4. fallback extraction

If the resolved value is falsey, updates remove the record from the index instead of indexing it.

## Related Data Is Missing

Check the field path in `fields`, `searchFields`, or `filters`.

Example:

```php
$this->searchFields = [
    'Items.Title',
    'Items.SubItems.Title',
];
```

Also check:

- relation names are correct
- the terminal field or method name is correct
- you ran a full reindex after changing the definition

Missing relation segments are ignored, so typos usually fail quietly.

## Runtime Filters Do Nothing

Runtime filters only apply to fields declared in the index definition's `filters`.
Undeclared filters are ignored.

```php
$this->filters = [
    'SubsiteID' => ['type' => 'integer'],
];
```

Run a full reindex after adding filter fields.

## Numeric or Date Filters Behave Incorrectly

This usually means the field is mapped as the wrong type.

Examples:

- `Rooms` should usually be `integer`
- `Price` should usually be `float`
- SilverStripe `Date` and `Datetime` field strings can be used directly in `fields` or `filters`

If an existing field already has the wrong mapping, run the manager task with `--action=reset` and then reindex. OpenSearch cannot reliably replace an existing field mapping in place.
- `PublishDate` should usually be `date`

If you change filter or field mappings, recreate or update the index and then reindex the documents.

## Search Results Are Raw Hits Instead of Records

`OpenSearch::singleton()->search()` returns normalised OpenSearch hit data.
If you want hydrated `DataObject` records, use `Toast\OpenSearch\Forms\SearchForm::getResults()` or hydrate matches in your own code using `ClassName` and `ID`.

## Existing Documents Do Not Reflect New Fields

Changing mappings or PHP index definitions does not backfill existing documents.
Run:

1. `/dev/tasks/OpenSearchManagerTask?action=init` or `reset`
2. `/dev/tasks/OpenSearchReindexTask`

## Delete Operations Return 404 Internally

The helper intentionally ignores OpenSearch `404` responses for some delete flows when the target document is already missing.
That is expected.

## Debugging Tips

Return the raw OpenSearch response when debugging:

```php
$response = OpenSearch::singleton()->search('report', null, [
    'return_raw' => true,
]);
```

Check:

- generated query clauses
- `_source` field names
- filter field presence and mapping type
- `_id`, `ID`, and `ClassName` values
