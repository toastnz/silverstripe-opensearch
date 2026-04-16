# OpenSearch for SilverStripe

A wrapper around the OpenSearch PHP client.

## Requirements

See the root `composer.json`.

## Installation

```bash
composer require toastnz/opensearch
```

## Helper Configuration

Configure the helper through Injector properties:

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

Supported helper properties:

- `client_host`
- `client_username`
- `client_password`
- `client_verify`
- `index_class`
- `default_search_index`
- `record_operation_fail_silently`

`record_operation_fail_silently` defaults to `true`. It only affects automatic record-driven indexing through `Toast\OpenSearch\Extensions\DataObjectExtension`, where insert, update, delete, publish, and unpublish operations fail quietly if the index is missing, not configured, or unreachable. It does not apply to direct index operations such as `OpenSearchManagerTask`, `OpenSearchReindexTask`, `initIndex()`, `clearIndex()`, or `deleteIndex()`.

## Defining an Index

Create a subclass of `Toast\OpenSearch\Search\OpenSearchIndex` and override protected properties before calling `parent::__construct()`.

```php
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\ErrorPage\ErrorPage;
use Toast\OpenSearch\Search\OpenSearchIndex;

class SiteSearchIndex extends OpenSearchIndex
{
    public function __construct(?string $indexName = null)
    {
        $this->indexName = $indexName ?? 'default_index';
        $this->fields = [
            'Title' => ['type' => 'text'],
            'Content' => ['type' => 'text'],
        ];
        $this->searchFields = [
            'Title^2',
            'Content',
            'SearchContent',
            'ElementalArea.Elements.Title',
        ];
        $this->filters = [
            'SubsiteID' => ['type' => 'integer'],
            'PublishDate' => ['type' => 'date'],
        ];
        $this->includedClasses = [SiteTree::class, File::class];
        $this->excludedClasses = [
            ErrorPage::class,
            RedirectorPage::class,
            VirtualPage::class,
        ];
        $this->filterField = 'ShowInSearch';

        parent::__construct($indexName);
    }
}
```

Important: the base constructor only accepts an optional index name. It does not accept named arguments such as `fields`, `searchFields`, or `filters`.

## Querying

```php
use Toast\OpenSearch\Helpers\OpenSearch;

$results = OpenSearch::singleton()->search('annual report', null, [
    'filters' => [
        'SubsiteID' => 2,
        'PublishDate' => ['gte' => '2026-01-01'],
    ],
]);
```

The second argument can be:

- an `OpenSearchIndex` instance
- an index-definition class name
- a literal OpenSearch index name
- `null` for the configured default

## SiteConfig Search Tuning

The bundled `SiteConfigExtension` adds three CMS tabs under `Root.OpenSearch`:

- `Weights` for per-field search weights
- `FineTune` for search-time query behaviour
- `Synonyms` for runtime query expansion rules

`FineTune` stores non-default values in `OpenSearchFineTuneSettings` and applies them to generated searches at runtime. The available controls are:

- `type` (`multi_match.type`)
- `operator`
- `minimum_should_match`
- `fuzziness`
- `min_score`

Defaults preserve the module's existing behaviour:

- `type`: omitted, which matches the current `multi_match` default behaviour
- `operator`: omitted, which matches the current `multi_match` default behaviour
- `minimum_should_match`: omitted
- `fuzziness`: omitted
- `min_score`: omitted

When `type` is `cross_fields`, `phrase`, or `phrase_prefix`, configured `fuzziness` is ignored because OpenSearch does not allow that combination.

`min_score` is only added to generated request bodies when a search term is present or an explicit `query` option is supplied, so empty filter-only searches continue to behave as they do today.

`Synonyms` stores rules as related `OpenSearchSynonym` records on the current `SiteConfig` and applies them to generated searches at runtime, so changes take effect without reindexing or recreating the index.

In the CMS, editors manage synonyms with a GridField under `Root.OpenSearch.Synonyms`.
Each row has two simple lists:

- `When searching for these`
- `Also search for these`

## Search Form

The module ships with:

- `Toast\OpenSearch\Forms\SearchForm`
- `Toast\OpenSearch\Extensions\ContentControllerExtension`

The controller extension adds `SearchForm()` and `searchResults()` to `ContentController`.
The form reads the `Search` query parameter, runs the helper query, and hydrates matches back into `DataObject` records when possible.

Search form pagination config:

```yml
Toast\OpenSearch\Forms\SearchForm:
  enable_pagination: true
  results_per_page: 10
```

## Tasks

Index manager:

```text
/dev/tasks/OpenSearchManagerTask?action=status
```

Supported actions:

- `init`
- `reset`
- `clear`
- `delete`
- `status`

Full reindex:

```text
/dev/tasks/OpenSearchReindexTask
```

## Notes

- `searchFields` entries not declared in `fields` are mapped as `text`
- `filters` are both mapped and included in indexed documents
- `ID` and `ClassName` are always indexed
- versioned classes are reindexed from `Live`
- undeclared runtime filters are ignored
- `Toast\OpenSearch\Extensions\DataObjectExtension` only performs automatic indexing for records included by the active `OpenSearchIndex`, while still reindexing dependent parent records for declared relation chains and respecting `excludedClasses`
