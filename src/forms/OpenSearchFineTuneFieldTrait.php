<?php

namespace Toast\OpenSearch\Forms;

use SilverStripe\ORM\DataObjectInterface;

trait OpenSearchFineTuneFieldTrait
{
    private string $fineTuneSettingName = '';

    public function setFineTuneSettingName(string $fineTuneSettingName): static
    {
        $this->fineTuneSettingName = $fineTuneSettingName;

        return $this;
    }

    public function getFineTuneSettingName(): string
    {
        return $this->fineTuneSettingName;
    }

    public function saveInto(DataObjectInterface $record)
    {
        $settings = $record->getField('OpenSearchFineTune');

        if (!is_array($settings)) {
            $settings = [];
        }

        $settingName = $this->getFineTuneSettingName();

        if ($settingName === '') {
            return;
        }

        $value = $this->getPersistedValue();

        if ($value === null) {
            unset($settings[$settingName]);
        } else {
            $settings[$settingName] = $value;
        }

        $record->setField('OpenSearchFineTune', $settings);
    }

    abstract protected function getPersistedValue();
}
