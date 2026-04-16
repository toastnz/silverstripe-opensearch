<?php

namespace Toast\OpenSearch\Forms;

use SilverStripe\Forms\NumericField;

class OpenSearchFineTuneNumericField extends NumericField
{
    use OpenSearchFineTuneFieldTrait;

    protected function getPersistedValue(): ?string
    {
        $value = $this->getValue();

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }
}
