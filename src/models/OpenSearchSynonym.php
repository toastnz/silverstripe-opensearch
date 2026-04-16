<?php

namespace Toast\OpenSearch\Models;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

class OpenSearchSynonym extends DataObject
{
    private static $table_name = 'ToastOpenSearchSynonym';

    private static $db = [
        'SearchTerms' => 'Text',
        'SynonymTerms' => 'Text',
        'SortOrder' => 'Int',
    ];

    private static $has_one = [
        'SiteConfig' => SiteConfig::class,
    ];

    private static $summary_fields = [
        'SearchTerms' => 'When searching for these',
        'SynonymTerms' => 'Also search for these',
    ];

    private static $default_sort = 'SortOrder ASC, ID ASC';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'SiteConfigID',
            'SortOrder',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            TextareaField::create('SearchTerms', 'When searching for these')
                ->setRows(6)
                ->setDescription('Add one word or phrase per line.'),
            TextareaField::create('SynonymTerms', 'Also search for these')
                ->setRows(6)
                ->setDescription('Add one word or phrase per line.'),
        ]);

        return $fields;
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $this->SearchTerms = $this->normaliseTermList($this->SearchTerms);
        $this->SynonymTerms = $this->normaliseTermList($this->SynonymTerms);
    }

    public function getSearchTermList(): array
    {
        return $this->splitTerms($this->SearchTerms);
    }

    public function getSynonymTermList(): array
    {
        return $this->splitTerms($this->SynonymTerms);
    }

    private function normaliseTermList($value): ?string
    {
        $terms = $this->splitTerms($value);

        return $terms === [] ? null : implode("\n", $terms);
    }

    private function splitTerms($value): array
    {
        $value = preg_replace("/\r\n?/", "\n", (string) $value);
        $parts = preg_split('/[\n,]+/', (string) $value) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $term = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? (string) $part);

            if ($term === '') {
                continue;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);

            if (isset($terms[$key])) {
                continue;
            }

            $terms[$key] = $term;
        }

        return array_values($terms);
    }
}
