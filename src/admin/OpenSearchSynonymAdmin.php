<?php

namespace Toast\OpenSearch\Admin;

use SilverStripe\Admin\ModelAdmin;
use Toast\OpenSearch\Models\OpenSearchSynonym;

class OpenSearchSynonymAdmin extends ModelAdmin
{
    private static $url_segment = 'opensearch-synonyms';

    private static $menu_title = 'OpenSearch Synonyms';

    private static $managed_models = [
        OpenSearchSynonym::class
    ];

}
