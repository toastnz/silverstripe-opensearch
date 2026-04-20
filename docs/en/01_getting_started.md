# Getting Started

## What the Module Does

The module indexes selected SilverStripe records into OpenSearch and exposes a helper for search and index management:

```php
use Toast\OpenSearch\Helpers\OpenSearch;

$results = OpenSearch::singleton()->search('annual report');
```

Out of the box, the module also applies these extensions from `opensearch/_config/config.yml`:

- `Toast\OpenSearch\Extensions\DataObjectExtension` on `SilverStripe\ORM\DataObject`
- `Toast\OpenSearch\Extensions\ContentControllerExtension` on `SilverStripe\CMS\Controllers\ContentController`

That means record lifecycle events can update the index automatically, and content controllers get a built-in search form endpoint.
Automatic record updates only run for records relevant to the configured `OpenSearchIndex`: included classes are indexed, excluded classes are skipped, and related child records only trigger parent reindexing when their relation path appears in the index definition.

## Requirements

You need:

- SilverStripe 6
- a running OpenSearch instance
- working OpenSearch connection details

## Installation

```bash
composer require toastnz/opensearch
```

## Configure the Helper

The helper reads client settings from `Toast\OpenSearch\Helpers\OpenSearch`.
The normal configuration path is Injector `properties`:

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

`index_class` is the default index-definition class. If it is not configured, the helper falls back to `Toast\OpenSearch\Search\OpenSearchIndex`.
`record_operation_fail_silently` defaults to `true` and only applies to automatic indexing triggered by `Toast\OpenSearch\Extensions\DataObjectExtension`. Direct index operations such as `/dev/tasks/OpenSearchManagerTask`, `/dev/tasks/OpenSearchReindexTask`, `initIndex()`, and `clearIndex()` still throw errors normally.

## Create a Custom Index Class

The base class is `Toast\OpenSearch\Search\OpenSearchIndex`.
To customise behavior, subclass it and set protected properties before calling `parent::__construct()`.

```php
namespace App\Search;

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
            'PublishDate' => 'Datetime',
        ];
        $this->searchFields = ['Title^2', 'Content'];
        $this->filters = [
            'SubsiteID' => ['type' => 'integer'],
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

The base constructor only accepts an optional index name. It does not accept named arguments for fields, filters, or class lists.

## Initialise the Index

Create or update the configured index:

```text
/dev/tasks/OpenSearchManagerTask?action=init
```

Useful status output:

```text
/dev/tasks/OpenSearchManagerTask?action=status
```

Full reset:

```text
/dev/tasks/OpenSearchManagerTask?action=reset
```

## Reindex Records

Populate the index from the configured included classes:

```text
/dev/tasks/OpenSearchReindexTask
```

Versioned classes are reindexed from the `Live` stage.

## Use the Built-in Search Form

`Toast\OpenSearch\Extensions\ContentControllerExtension` adds these controller actions:

- `SearchForm`
- `searchResults`

`SearchForm()` returns a GET form with a `Search` field.
`searchResults()` renders with these templates in order:

- `Page_results_opensearch`
- `Page_results`
- `Page`

You can also instantiate the form directly:

```php
use Toast\OpenSearch\Forms\SearchForm;

$form = SearchForm::create($this, 'SearchForm');
```

`SearchForm::getResults()` runs the search, hydrates matches back into `DataObject` records when possible, and paginates by default.

## Reindex After Definition Changes

Run a full reindex whenever you change:

- `fields`
- `searchFields`
- `filters`
- `includedClasses`
- `excludedClasses`
- `filterField`
- related field paths such as `ElementalArea.Elements.Title`
