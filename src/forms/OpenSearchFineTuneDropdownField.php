<?php

namespace Toast\OpenSearch\Forms;

use SilverStripe\Forms\DropdownField;

class OpenSearchFineTuneDropdownField extends DropdownField
{
    use OpenSearchFineTuneFieldTrait;

    protected function getPersistedValue(): ?string
    {
        $value = $this->getValue();

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
