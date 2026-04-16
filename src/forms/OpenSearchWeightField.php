<?php

namespace Toast\OpenSearch\Forms;

use SilverStripe\Core\Convert;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObjectInterface;

class OpenSearchWeightField extends NumericField
{
    private string $searchFieldName = '';

    public function setSearchFieldName(string $searchFieldName): static
    {
        $this->searchFieldName = $searchFieldName;

        return $this;
    }

    public function getSearchFieldName(): string
    {
        return $this->searchFieldName;
    }

    public function saveInto(DataObjectInterface $record)
    {
        $weights = $record->getField('OpenSearchFields');

        if (!is_array($weights)) {
            $weights = [];
        }

        $searchFieldName = $this->getSearchFieldName();

        if ($searchFieldName === '') {
            return;
        }

        $weights[$searchFieldName] = $this->getPersistedValue();
        $record->setField('OpenSearchFields', $weights);
    }

    public function Field($properties = [])
    {
        return sprintf(
            '<div class="opensearch-weight-slider" style="display:flex;align-items:center;gap:0.75rem;max-width:24rem;">'
            . '<input %s />'
            . '<output id="%s" for="%s" style="min-width:2.5rem;font-weight:600;text-align:right;">%s</output>'
            . '</div>',
            $this->getAttributesHTML(),
            Convert::raw2att($this->getValueDisplayID()),
            Convert::raw2att($this->ID()),
            Convert::raw2xml($this->getDisplayValue())
        );
    }

    public function getValueDisplayID(): string
    {
        return $this->ID() . '_value';
    }

    public function getDisplayValue(): string
    {
        $value = $this->getValue();

        if (!is_numeric($value)) {
            return '';
        }

        $scale = $this->getScale() ?? 0;

        return number_format((float) $value, $scale, '.', '');
    }

    protected function getPersistedValue(): ?string
    {
        $value = $this->getValue();

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $this->getScale() ?? 0, '.', '');
    }
}
