<?php

namespace Toast\OpenSearch\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Model\ArrayData;
use Toast\OpenSearch\Helpers\OpenSearch;
use Toast\OpenSearch\Forms\OpenSearchWeightField;
use Toast\OpenSearch\Search\OpenSearchFineTuneSettings;
use Toast\OpenSearch\Search\OpenSearchIndex;

class SiteConfigExtension extends Extension
{
    private const WEIGHT_MIN = 1;
    private const WEIGHT_MAX = 10;
    private const WEIGHT_SCALE = 1;

    private static $db = [
        'OpenSearchRelevanceSettings' => 'Text',
        'OpenSearchFineTuneType' => 'Varchar(255)',
        'OpenSearchFineTuneOperator' => 'Varchar(255)',
        'OpenSearchFineTuneMinimumShouldMatch' => 'Varchar(255)',
        'OpenSearchFineTuneFuzziness' => 'Varchar(255)',
        'OpenSearchFineTuneMinScore' => 'Decimal(5,1)',
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
            LiteralField::create('OpenSearchWeightsHelp', '<p style="margin:0 0 1rem;">Use these sliders to tune relevance. Higher values make a field more important in search ranking, while lower values reduce its influence.</p>'),
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
                ->setAttribute('oninput', 'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}')
                ->setAttribute('onchange', 'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}')
                ->setAttribute('style', 'width:18rem;max-width:100%;padding:0;border:0;box-shadow:none;background:none;background-color:transparent;');

            $numericFields[] = $field;
        }

        if ($numericFields !== []) {
            $fields->addFieldsToTab('Root.OpenSearch.Weights', $numericFields);
        }

        $storedFineTuneSettings = $this->getStoredFineTuneSettings();
        
        $fineTuneFields = [
            LiteralField::create('OpenSearchFineTuneHelp', '<p style="margin:0 0 1rem;">Fine-tune how the generated search query behaves. Leaving a setting on its default keeps today&apos;s search behaviour unchanged.</p>'),
            DropdownField::create('OpenSearchFineTuneType', 'Search mode', OpenSearchFineTuneSettings::getSearchModeFieldOptions())
                ->setValue(OpenSearchFineTuneSettings::getSearchModeStoredValue($storedFineTuneSettings['type'] ?? null))
                ->setDescription('Controls how matches across multiple fields are combined. The default usually works best for general site search.'),

            DropdownField::create('OpenSearchFineTuneOperator', 'Match strictness', OpenSearchFineTuneSettings::getOperatorFieldOptions())
                ->setValue(OpenSearchFineTuneSettings::getOperatorStoredValue($storedFineTuneSettings['operator'] ?? null))
                ->setDescription('Choose whether a result can match any search word or must match them all. Stricter matching usually returns fewer results.'),

            DropdownField::create('OpenSearchFineTuneMinimumShouldMatch', 'Minimum words to match', OpenSearchFineTuneSettings::getMinimumShouldMatchFieldOptions())
                ->setValue(OpenSearchFineTuneSettings::getMinimumShouldMatchStoredValue($storedFineTuneSettings['minimum_should_match'] ?? null))
                ->setDescription('Choose how many of the search words should match before a result is included. Higher values make search stricter.'),

            DropdownField::create('OpenSearchFineTuneFuzziness', 'Typo tolerance', OpenSearchFineTuneSettings::getFuzzinessFieldOptions())
                ->setValue(OpenSearchFineTuneSettings::getFuzzinessStoredValue($storedFineTuneSettings['fuzziness'] ?? null))
                ->setDescription('Allows near matches when someone misspells a word. Higher values are more forgiving, but can also make results broader. This setting is ignored for Exact phrase, Phrase prefix, and combined-field search modes.'),
            
            NumericField::create('OpenSearchFineTuneMinScore', 'Minimum score cutoff')
                ->setHTML5(true)
                ->setScale(1)
                ->setValue(OpenSearchFineTuneSettings::formatMinScore((float) ($storedFineTuneSettings['min_score'] ?? 0)))
                ->setAttribute('type', 'range')
                ->setAttribute('min', '0')
                ->setAttribute('max', '30')
                ->setAttribute('step', '0.1')
                ->setAttribute('oninput', 'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}')
                ->setAttribute('onchange', 'if(this.nextElementSibling){var v=Number(this.value).toFixed(1);this.nextElementSibling.value=v;this.nextElementSibling.textContent=v;}')
                ->setAttribute('style', 'width:18rem;max-width:100%;padding:0;border:0;box-shadow:none;background:none;background-color:transparent;')
                ->setDescription('Hide weaker matches. Set this to 0 to keep the default behaviour. Scores are relative, so small increases can make a big difference.'),
        ];

        if ($fineTuneFields !== []) {
            $fields->addFieldsToTab('Root.OpenSearch.FineTune', $fineTuneFields);
        }

        $fields->addFieldToTab('Root.OpenSearch.More', $this->getOpenSearchExplainField());
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

        $fineTuneSettings = OpenSearchFineTuneSettings::normalise($this->getSubmittedFineTuneSettings());

        $this->owner->OpenSearchFineTuneType = $fineTuneSettings['type'] ?? null;
        $this->owner->OpenSearchFineTuneOperator = $fineTuneSettings['operator'] ?? null;
        $this->owner->OpenSearchFineTuneMinimumShouldMatch = $fineTuneSettings['minimum_should_match'] ?? null;
        $this->owner->OpenSearchFineTuneFuzziness = isset($fineTuneSettings['fuzziness'])
            ? (string) $fineTuneSettings['fuzziness']
            : null;
        $this->owner->OpenSearchFineTuneMinScore = $fineTuneSettings['min_score'] ?? 0;
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
        return OpenSearchFineTuneSettings::normalise([
            'type' => $this->owner->OpenSearchFineTuneType ?? null,
            'operator' => $this->owner->OpenSearchFineTuneOperator ?? null,
            'minimum_should_match' => $this->owner->OpenSearchFineTuneMinimumShouldMatch ?? null,
            'fuzziness' => $this->owner->OpenSearchFineTuneFuzziness ?? null,
            'min_score' => $this->owner->OpenSearchFineTuneMinScore ?? null,
        ]);
    }

    private function getSubmittedFineTuneSettings(): array
    {
        return [
            'type' => $this->owner->getField('OpenSearchFineTuneType'),
            'operator' => $this->owner->getField('OpenSearchFineTuneOperator'),
            'minimum_should_match' => $this->owner->getField('OpenSearchFineTuneMinimumShouldMatch'),
            'fuzziness' => $this->owner->getField('OpenSearchFineTuneFuzziness'),
            'min_score' => $this->owner->getField('OpenSearchFineTuneMinScore'),
        ];
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

    private function getOpenSearchExplainField(): LiteralField
    {
        $controller = Controller::curr();
        $endpoint = $controller ? Controller::join_links($controller->Link(), 'OpenSearchExplain') : '';

        $html = ArrayData::create([
            'Endpoint' => $endpoint,
        ])->renderWith('Toast\\OpenSearch\\Includes\\OpenSearchExplainField');

        return LiteralField::create('OpenSearchExplainSearchField', $html);
    }
}
