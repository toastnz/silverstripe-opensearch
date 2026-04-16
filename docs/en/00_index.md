# OpenSearch Module Documentation

This module wraps the OpenSearch PHP client for SilverStripe and ships with:

- an `OpenSearch` helper for querying and index operations
- an `OpenSearchIndex` base class for index definitions
- automatic `DataObject` hooks for indexing, deletion, publish, and unpublish events
- a `ContentController` extension that provides `SearchForm()` and `searchResults()`
- a built-in `SearchForm` that can hydrate hits back into `DataObject` records
- build tasks for index management and full reindexing

Documentation guide:

- [01 Getting Started](./01_getting_started.md)
- [02 Configuration](./02_configuration.md)
- [03 Querying](./03_querying.md)
- [04 Advanced Configuration](./04_advanced_configuration.md)
- [05 Troubleshooting](./05_troubleshooting.md)

Recommended reading order:

1. Configure the helper and a custom index class.
2. Initialise the index.
3. Run a full reindex.
4. Integrate the helper or built-in search form.
5. Add related fields, filters, and custom mappings as needed.
