# Configuration

## Helper Configuration

`Toast\OpenSearch\Helpers\OpenSearch` resolves its default index in this order:

1. the explicit `$indexName` argument passed to a helper method
2. the helper's `default_search_index` property
3. the helper's resolved `index_class`
4. `Toast\OpenSearch\Search\OpenSearchIndex`

Typical helper config:

```yml
SilverStripe\Core\Injector\Injector:
  Toast\OpenSearch\Helpers\OpenSearch:
    properties:
      client_host: '`OPENSEARCH_HOST`'
      client_username: '`OPENSEARCH_USERNAME`'
      client_password: '`OPENSEARCH_PASSWORD`'
      client_verify: false
      index_class: App\Search\SiteSearchIndex
      record_operation_fail_silently: true
```

`client_verify` accepts:

- `true`
- `false`
- a certificate path string
- string equivalents such as `'true'`, `'false'`, `'on'`, `'off'`

`record_operation_fail_silently` defaults to `true`. It only affects automatic record insert, update, delete, publish, and unpublish handling through `Toast\OpenSearch\Extensions\DataObjectExtension`. It does not affect direct index operations such as tasks or explicit helper calls like `initIndex()`, `clearIndex()`, or `deleteIndex()`.

## Index Definition Class

Index definitions live in PHP subclasses of `Toast\OpenSearch\Search\OpenSearchIndex`.
The base class sets these defaults:

- `indexName`: `default_index`
- `fields`: `Title` and `Content` as `text`
- `searchFields`: `Title^2`, `Content`
- `settings`: `[]`
- `aliases`: `[]`
- `filters`: `[]`
- `includedClasses`: `SilverStripe\CMS\Model\SiteTree`
- `excludedClasses`: `SilverStripe\ErrorPage\ErrorPage`, `SilverStripe\CMS\Model\RedirectorPage`, `SilverStripe\CMS\Model\VirtualPage`
- `filterField`: `ShowInSearch`

To customise them, override protected properties in your subclass constructor:

```php
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use Toast\OpenSearch\Search\OpenSearchIndex;

class SiteSearchIndex extends OpenSearchIndex
{
    public function __construct(?string $indexName = null)
    {
        $this->indexName = $indexName ?? 'default_index';
        $this->fields = [
            'Title' => ['type' => 'text'],
            'Content' => ['type' => 'text'],
            'PublishDate' => 'Datetime',
        ];
        $this->settings = [];
        $this->aliases = [];
        $this->searchFields = ['Title^2', 'Content', 'SearchContent', 'ElementalArea.Elements.Title'];
        $this->filters = [
            'SubsiteID' => ['type' => 'integer'],
            'PublishDate' => 'Datetime',
            'HasRoof' => ['type' => 'boolean'],
        ];
        $this->includedClasses = [SiteTree::class, File::class];
        $this->excludedClasses = [Image::class, RedirectorPage::class];
        $this->filterField = 'ShowInSearch';

        parent::__construct($indexName);
    }
}
```

## `fields`

`fields` defines explicit OpenSearch mappings for document properties.

```php
$this->fields = [
    'Title' => ['type' => 'text'],
    'PublishDate' => 'Datetime',
    'Price' => ['type' => 'float'],
    'Rooms' => ['type' => 'integer'],
    'HasRoof' => ['type' => 'boolean'],
];
```

SilverStripe DB type strings are supported for field mappings, including `Varchar`, `Text`, `HTMLText`, `Int`, `Boolean`, `Date`, and `Datetime`.

Nested field paths such as `ElementalArea.Elements.Title` are supported and become nested `properties` entries in the mapping.

## `searchFields`

`searchFields` controls which fields are queried by the generated `multi_match` query.

```php
$this->searchFields = [
    'Title^2',
    'Content',
    'MetaDescription',
];
```

Behavior:

- boost syntax like `Title^3` is supported
- `*` is ignored when building document fields and mappings
- any `searchFields` entry not already present in `fields` is mapped as `text`
- `searchFields` also decides which extra fields are extracted into documents during indexing

If `Toast\OpenSearch\Extensions\SiteConfigExtension` is enabled, editors can also define runtime synonym rules in the `SiteConfig.OpenSearchSynonyms()` relation. Those rules are applied to generated queries and do not change mappings, analyzers, or indexed documents.

## Related Fields

Dot notation can traverse relations and nested values:

```php
$this->searchFields = [
    'Title^2',
    'Items.Title',
    'Items.SubItems.Title',
];
```

Supported relation traversal depends on SilverStripe relation resolution for the indexed classes. Missing relation segments are ignored.

## Computed Fields

When extracting a document field from a `DataObject`, the module resolves each path segment in this order:

1. method with the same name
2. getter like `getFieldName()`
3. DB field

Example:

```php
$this->searchFields = ['Title^2', 'SearchContent'];
```

```php
public function SearchContent(): string
{
    return 'Extra text to include in the index';
}
```

## `filters`

`filters` declares runtime filterable fields and also ensures those fields are mapped and added to indexed documents.

```php
$this->filters = [
    'SubsiteID' => ['type' => 'integer'],
    'Status' => 'keyword',
    'PublishDate' => 'Datetime',
];
```

Rules:

- string shorthand like `'Status' => 'keyword'` is supported
- SilverStripe DB type strings like `'PublishDate' => 'Datetime'` are supported
- bare string entries like `'SubsiteID'` are supported and default to `keyword`
- omitted types default to `keyword`
- undeclared runtime filters are ignored

## `includedClasses`

`includedClasses` can be either a plain list of classes or an associative map with query modifiers.

Simple form:

```php
$this->includedClasses = [
    SiteTree::class,
];
```

Configured form:

```php
$this->includedClasses = [
    SiteTree::class => [
        'filter' => ['ShowInSearch' => 1],
        'exclude' => ['ClassName' => RedirectorPage::class],
        'sort' => 'LastEdited DESC',
    ],
];
```

For versioned classes, records are read from `Versioned::LIVE`.

## `excludedClasses`

`excludedClasses` removes classes from the supported set even if they match an included base class.

```php
$this->excludedClasses = [
    RedirectorPage::class,
];
```

## `filterField`

`filterField` controls whether an otherwise supported record should remain indexed.

```php
$this->filterField = 'ShowInSearch';
```

Behavior:

- if the resolved value is `null`, the record is treated as indexable
- if the resolved value is a list, any non-empty list is treated as true
- falsey strings such as `''`, `'0'`, `'false'`, `'no'`, and `'off'` are treated as false
- truthy strings such as `'1'`, `'true'`, `'yes'`, and `'on'` are treated as true

For the final segment of `filterField`, lookup order is:

1. DB field
2. method with the same name
3. getter like `getShowInSearch()`
4. generic segment extraction fallback

## Implicit Fields

The module always maps and stores:

- `ID` as `integer`
- `ClassName` as `keyword`
