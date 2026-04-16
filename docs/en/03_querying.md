# Querying

## Basic Search

```php
use Toast\OpenSearch\Helpers\OpenSearch;

$results = OpenSearch::singleton()->search('annual report');
```

The normalised result contains:

- `Matches`
- `Total`
- `MaxScore`
- `Took`
- `TimedOut`
- `Aggregations`
- `Raw`

Each match contains the indexed source fields plus:

- `DocumentID`
- `Index`
- `Score`
- `Fields`
- `Highlight`
- `Source`
- `Raw`

## Choosing an Index

The second argument accepted by helper methods such as `search()`, `initIndex()`, `clearIndex()`, and `deleteIndex()` can be:

- an `OpenSearchIndex` instance
- an index-definition class name
- a literal OpenSearch index name
- `null` to use the helper default

If you pass a literal index name, the helper creates a new instance of the configured `index_class` and passes that name into its constructor.

## Native Search Options

Most OpenSearch request options can be passed through directly:

```php
$results = OpenSearch::singleton()->search('annual report', null, [
    'size' => 20,
    'from' => 0,
    'sort' => [
        ['PublishDate' => ['order' => 'desc']],
    ],
]);
```

The helper reserves these option keys for its own behavior:

- `fields`
- `filters`
- `query`
- `return_raw`

## Override Search Fields

```php
$results = OpenSearch::singleton()->search('budget', null, [
    'fields' => ['Title^5', 'Summary', 'Content'],
]);
```

This only changes the query fields for that request. It does not change indexing or mappings.

## SiteConfig Fine-Tune Controls

If the bundled `Toast\OpenSearch\Extensions\SiteConfigExtension` is enabled, editors can also adjust the generated query from the CMS under `Root.OpenSearch.FineTune`.

Those controls map to:

- `multi_match.type`
- `operator`
- `minimum_should_match`
- `fuzziness`
- `min_score`

Only non-default values are stored. Leaving a control on its default keeps the generated request the same as the module's current behaviour.

When `multi_match.type` is `cross_fields`, `phrase`, or `phrase_prefix`, the module ignores configured `fuzziness` because OpenSearch does not allow that combination.

`min_score` is only added when the search includes a term or an explicit `query` option. Empty filter-only searches still generate a query with no score cutoff.

## SiteConfig Synonyms

If the bundled `Toast\OpenSearch\Extensions\SiteConfigExtension` is enabled, editors can manage runtime synonym rules under `Root.OpenSearch.Synonyms` using a GridField in the CMS.

Each synonym row has two simple lists:

- `When searching for these`
- `Also search for these`

At runtime, the module reads `SiteConfig::current_site_config()->OpenSearchSynonyms()` and generates additional `multi_match` clauses for expanded variants of the entered search term. Changes apply immediately and do not require reindexing or recreating the OpenSearch index.

## Runtime Filters

Runtime filters only apply to fields declared in the index definition's `filters`.

```php
$results = OpenSearch::singleton()->search('meeting room', null, [
    'filters' => [
        'SubsiteID' => 2,
        'Rooms' => ['gte' => 4],
        'Price' => ['between' => [100.0, 500.0]],
        'HasRoof' => true,
        'PublishDate' => ['gte' => '2026-01-01'],
    ],
]);
```

## Supported Filter Shapes

Exact match:

```php
'filters' => [
    'SubsiteID' => 2,
]
```

Multiple exact values:

```php
'filters' => [
    'SubsiteID' => [1, 2, 3],
]
```

Range operators:

```php
'filters' => [
    'Rooms' => ['gt' => 4],
    'Price' => ['lte' => 1000],
]
```

Between:

```php
'filters' => [
    'Rooms' => ['between' => [1, 6]],
]
```

Inclusion and exclusion:

```php
'filters' => [
    'SubsiteID' => ['in' => [1, 2]],
    'Rooms' => ['not_in' => [1, 2]],
]
```

Not equal:

```php
'filters' => [
    'Rooms' => ['neq' => 4],
]
```

Explicit exact value:

```php
'filters' => [
    'Status' => ['value' => 'published'],
]
```

String shorthand ranges:

```php
'filters' => [
    'Rooms' => '>4',
    'Price' => '100..500',
]
```

If a filter field is mapped as `text`, exact matches use `match_phrase`. All other filter types use `term`.

## Empty Search Terms

An empty search term is valid.

With no filters, the helper generates a `match_all` query:

```php
$results = OpenSearch::singleton()->search('');
```

With filters, the helper generates a `bool` query containing only `filter` clauses:

```php
$results = OpenSearch::singleton()->search('', null, [
    'filters' => [
        'SubsiteID' => 2,
    ],
]);
```

## Custom Query or Full Body

If `body` is not provided, the helper builds one automatically.
You can override that by supplying either `query` or a full `body`.

Custom query:

```php
$results = OpenSearch::singleton()->search('', null, [
    'query' => [
        'term' => [
            'SubsiteID' => 2,
        ],
    ],
]);
```

Full body:

```php
$results = OpenSearch::singleton()->search('', null, [
    'body' => [
        'query' => [
            'match_all' => new stdClass(),
        ],
        'sort' => [
            ['PublishDate' => ['order' => 'desc']],
        ],
    ],
]);
```

## Raw Response

```php
$response = OpenSearch::singleton()->search('report', null, [
    'return_raw' => true,
]);
```

## Using `SearchForm`

```php
use Toast\OpenSearch\Forms\SearchForm;

$form = SearchForm::create($this, 'SearchForm');
```

`SearchForm::getResults()`:

- reads the `Search` request variable
- runs the helper search
- hydrates `Matches` back into real `DataObject` records when possible
- returns a `PaginatedList` by default

Pagination is controlled by class config on `Toast\OpenSearch\Forms\SearchForm`:

```yml
Toast\OpenSearch\Forms\SearchForm:
  enable_pagination: true
  results_per_page: 10
```
