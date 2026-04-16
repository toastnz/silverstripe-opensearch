<?php

namespace Toast\OpenSearch\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use Toast\OpenSearch\Helpers\OpenSearch;
use Toast\OpenSearch\Forms\OpenSearchGridFieldExportButton;
use Toast\OpenSearch\Forms\OpenSearchFineTuneDropdownField;
use Toast\OpenSearch\Forms\OpenSearchFineTuneRangeField;
use Toast\OpenSearch\Forms\OpenSearchWeightField;
use Toast\OpenSearch\Models\OpenSearchSynonym;
use Toast\OpenSearch\Search\OpenSearchFineTuneSettings;
use Toast\OpenSearch\Search\OpenSearchIndex;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

class SiteConfigExtension extends Extension
{
    private const WEIGHT_MIN = 1;
    private const WEIGHT_MAX = 10;
    private const WEIGHT_SCALE = 1;

    private static $db = [
        'OpenSearchRelevanceSettings' => 'Text',
        'OpenSearchFineTuneSettings' => 'Text',
    ];

    private static $has_many = [
        'OpenSearchSynonyms' => OpenSearchSynonym::class,
    ];

    private static $cascade_deletes = [
        'OpenSearchSynonyms',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $index = $this->getIndexDefinition();

        if (!$index) {
            return;
        }

        $storedWeights = $this->getStoredSearchWeights();
        $defaultWeights = $index->getConfiguredSearchFieldWeights();
        $numericFields = [
            LiteralField::create(
                'OpenSearchWeightsHelp',
                '<p style="margin:0 0 1rem;">Use these sliders to tune relevance. Higher values make a field more important in search ranking, while lower values reduce its influence.</p>'
            ),
        ];

        foreach ($index->getConfigurableSearchFields() as $fieldName) {
            $defaultWeight = $this->normaliseWeight($defaultWeights[$fieldName] ?? self::WEIGHT_MIN) ?? self::WEIGHT_MIN;
            $value = $storedWeights[$fieldName] ?? $defaultWeight;
            $field = OpenSearchWeightField::create(
                sprintf('OpenSearchFields[%s]', $fieldName),
                $this->formatFieldLabel($fieldName)
            );

            $field
                ->setSearchFieldName($fieldName)
                ->setHTML5(true)
                ->setScale(self::WEIGHT_SCALE)
                ->setValue($this->formatWeight($value))
                ->setAttribute('type', 'range')
                ->setAttribute('min', (string) self::WEIGHT_MIN)
                ->setAttribute('max', (string) self::WEIGHT_MAX)
                ->setAttribute('step', $this->formatWeight(0.1))
                ->setAttribute('data-default-weight', $this->formatWeight($defaultWeight))
                ->setAttribute(
                    'oninput',
                    'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}'
                )
                ->setAttribute(
                    'onchange',
                    'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}'
                )
                ->setAttribute(
                    'style',
                    'width:18rem;max-width:100%;padding:0;border:0;box-shadow:none;background:none;background-color:transparent;'
                );

            $numericFields[] = $field;
        }

        if ($numericFields !== []) {
            $fields->addFieldsToTab('Root.OpenSearch.Weights', $numericFields);
        }

        $storedFineTuneSettings = $this->getStoredFineTuneSettings();
        $fineTuneFields = [
            LiteralField::create(
                'OpenSearchFineTuneHelp',
                '<p style="margin:0 0 1rem;">Fine-tune how the generated search query behaves. Leaving a setting on its default keeps today&apos;s search behaviour unchanged.</p>'
            ),
            OpenSearchFineTuneDropdownField::create(
                'OpenSearchFineTune[type]',
                'Search mode',
                OpenSearchFineTuneSettings::getSearchModeFieldOptions()
            )
                ->setFineTuneSettingName('type')
                ->setValue(OpenSearchFineTuneSettings::getSearchModeStoredValue($storedFineTuneSettings['type'] ?? null))
                ->setDescription('Controls how matches across multiple fields are combined. The default usually works best for general site search.'),
            OpenSearchFineTuneDropdownField::create(
                'OpenSearchFineTune[operator]',
                'Match strictness',
                OpenSearchFineTuneSettings::getOperatorFieldOptions()
            )
                ->setFineTuneSettingName('operator')
                ->setValue(OpenSearchFineTuneSettings::getOperatorStoredValue($storedFineTuneSettings['operator'] ?? null))
                ->setDescription('Choose whether a result can match any search word or must match them all. Stricter matching usually returns fewer results.'),
            OpenSearchFineTuneDropdownField::create(
                'OpenSearchFineTune[minimum_should_match]',
                'Minimum words to match',
                OpenSearchFineTuneSettings::getMinimumShouldMatchFieldOptions()
            )
                ->setFineTuneSettingName('minimum_should_match')
                ->setValue(OpenSearchFineTuneSettings::getMinimumShouldMatchStoredValue($storedFineTuneSettings['minimum_should_match'] ?? null))
                ->setDescription('Choose how many of the search words should match before a result is included. Higher values make search stricter.'),
            OpenSearchFineTuneDropdownField::create(
                'OpenSearchFineTune[fuzziness]',
                'Typo tolerance',
                OpenSearchFineTuneSettings::getFuzzinessFieldOptions()
            )
                ->setFineTuneSettingName('fuzziness')
                ->setValue(OpenSearchFineTuneSettings::getFuzzinessStoredValue($storedFineTuneSettings['fuzziness'] ?? null))
                ->setDescription('Allows near matches when someone misspells a word. Higher values are more forgiving, but can also make results broader. This setting is ignored for Exact phrase, Phrase prefix, and combined-field search modes.'),
            OpenSearchFineTuneRangeField::create(
                'OpenSearchFineTune[min_score]',
                'Minimum score cutoff'
            )
                ->setFineTuneSettingName('min_score')
                ->setHTML5(true)
                ->setScale(1)
                ->setValue(OpenSearchFineTuneSettings::formatMinScore((float) ($storedFineTuneSettings['min_score'] ?? 0)))
                ->setAttribute('type', 'range')
                ->setAttribute('min', '0')
                ->setAttribute('max', '10')
                ->setAttribute('step', '0.1')
                ->setAttribute(
                    'oninput',
                    'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}'
                )
                ->setAttribute(
                    'onchange',
                    'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}'
                )
                ->setAttribute(
                    'style',
                    'width:18rem;max-width:100%;padding:0;border:0;box-shadow:none;background:none;background-color:transparent;'
                )
                ->setDescription('Hide weaker matches. Set this to 0 to keep the default behaviour. Scores are relative, so small increases can make a big difference.'),
        ];

        if ($fineTuneFields !== []) {
            $fields->addFieldsToTab('Root.OpenSearch.FineTune', $fineTuneFields);
        }

        if ($this->owner->ID) {
            $config = GridFieldConfig_RecordEditor::create();
            $config->addComponent(new GridFieldOrderableRows('SortOrder'));
            $config->addComponent(
                OpenSearchGridFieldExportButton::create('buttons-before-left', [
                    'SearchTerms' => 'SearchTerms',
                    'SynonymTerms' => 'SynonymTerms',
                ])
            );

            $controller = Controller::curr();

            if ($controller && $controller->hasMethod('OpenSearchSynonymImportForm')) {
                $config->addComponent(
                    GridFieldImportButton::create('buttons-before-left')
                        ->setImportForm($controller->OpenSearchSynonymImportForm())
                        ->setModalTitle('Import synonym rules from CSV')
                );
            }

            $fields->addFieldToTab(
                'Root.OpenSearch.Synonyms',
                GridField::create(
                    'OpenSearchSynonyms',
                    'Synonym rules',
                    $this->owner->OpenSearchSynonyms(),
                    $config
                )
            );
        } else {
            $fields->addFieldToTab(
                'Root.OpenSearch.Synonyms',
                LiteralField::create(
                    'OpenSearchSynonymsSaveFirst',
                    '<p style="margin:0;color:#5b6574;">Save the site configuration first to start adding synonym rules.</p>'
                )
            );
        }
    }

    public function onBeforeWrite()
    {
        $index = $this->getIndexDefinition();

        if (!$index) {
            return;
        }

        $weights = [];
        $defaultWeights = $index->getConfiguredSearchFieldWeights();

        $submittedWeights = $this->owner->getField('OpenSearchFields');

        if (!is_array($submittedWeights)) {
            $submittedWeights = [];
        }

        foreach ($index->getConfigurableSearchFields() as $fieldName) {
            $submittedWeight = $this->normaliseWeight($submittedWeights[$fieldName] ?? null);

            if ($submittedWeight === null) {
                continue;
            }

            $defaultWeight = $this->normaliseWeight($defaultWeights[$fieldName] ?? self::WEIGHT_MIN) ?? self::WEIGHT_MIN;

            if ($this->weightsAreEqual($submittedWeight, $defaultWeight)) {
                continue;
            }

            $weights[$fieldName] = $submittedWeight;
        }

        $this->owner->OpenSearchRelevanceSettings = $weights === [] ? null : json_encode($weights);

        $submittedFineTuneSettings = $this->owner->getField('OpenSearchFineTune');

        if (!is_array($submittedFineTuneSettings)) {
            $submittedFineTuneSettings = [];
        }

        $fineTuneSettings = OpenSearchFineTuneSettings::normalise($submittedFineTuneSettings);

        $this->owner->OpenSearchFineTuneSettings = $fineTuneSettings === [] ? null : json_encode($fineTuneSettings);
    }

    private function getIndexDefinition(): ?OpenSearchIndex
    {
        try {
            return Injector::inst()->get(OpenSearch::class)->getIndexDefinition();
        } catch (\Throwable) {
            return null;
        }
    }

    private function getStoredSearchWeights(): array
    {
        $storedSettings = json_decode((string) ($this->owner->OpenSearchRelevanceSettings ?? ''), true);

        if (!is_array($storedSettings)) {
            return [];
        }

        $weights = [];

        foreach ($storedSettings as $fieldName => $weight) {
            if (!is_string($fieldName)) {
                continue;
            }

            $normalisedWeight = $this->normaliseWeight($weight);

            if ($normalisedWeight === null) {
                continue;
            }

            $weights[$fieldName] = $normalisedWeight;
        }

        return $weights;
    }

    private function getStoredFineTuneSettings(): array
    {
        $storedSettings = json_decode((string) ($this->owner->OpenSearchFineTuneSettings ?? ''), true);

        if (!is_array($storedSettings)) {
            return [];
        }

        return OpenSearchFineTuneSettings::normalise($storedSettings);
    }

    private function normaliseWeight($weight): ?float
    {
        if (is_string($weight)) {
            $weight = trim($weight);
        }

        if ($weight === null || $weight === '' || !is_numeric($weight)) {
            return null;
        }

        $weight = round((float) $weight, self::WEIGHT_SCALE);

        if ($weight < self::WEIGHT_MIN || $weight > self::WEIGHT_MAX) {
            return null;
        }

        return $weight;
    }

    private function weightsAreEqual(float $left, float $right): bool
    {
        return abs($left - $right) < 0.00001;
    }

    private function formatWeight(float $weight): string
    {
        return number_format($weight, self::WEIGHT_SCALE, '.', '');
    }

    private function formatFieldLabel(string $fieldName): string
    {
        $segments = explode('.', $fieldName);

        $segments = array_map(static function (string $segment): string {
            return preg_replace('/(?<!^)([A-Z])/', ' $1', $segment) ?? $segment;
        }, $segments);

        return implode(' > ', $segments);
    }
}
