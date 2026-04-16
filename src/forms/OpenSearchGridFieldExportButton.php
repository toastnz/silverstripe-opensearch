<?php

namespace Toast\OpenSearch\Forms;

use League\Csv\Bom;
use League\Csv\Writer;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;

class OpenSearchGridFieldExportButton extends GridFieldExportButton
{
    public function generateExportFileData($gridField)
    {
        $csvColumns = $this->getExportColumnsForGridField($gridField);

        $csvWriter = Writer::from(new \SplTempFileObject());
        $csvWriter->setDelimiter($this->getCsvSeparator());
        $csvWriter->setEnclosure($this->getCsvEnclosure());
        $csvWriter->setEndOfLine("\r\n");
        $csvWriter->setOutputBOM(Bom::Utf8);

        if (!Config::inst()->get(get_class($this), 'xls_export_disabled')) {
            $csvWriter->addFormatter(function (array $row) {
                foreach ($row as &$item) {
                    if (preg_match('/^[-@=+].*/', $item ?? '')) {
                        $item = "\t" . $item;
                    }
                }

                return $row;
            });
        }

        if ($this->getCsvHasHeader()) {
            $headers = [];

            foreach ($csvColumns as $columnSource => $columnHeader) {
                if (is_array($columnHeader) && array_key_exists('title', $columnHeader ?? [])) {
                    $headers[] = $columnHeader['title'];
                } else {
                    $headers[] = (!is_string($columnHeader) && is_callable($columnHeader)) ? $columnSource : $columnHeader;
                }
            }

            $csvWriter->insertOne($headers);
        }

        $gridField->getConfig()->removeComponentsByType(GridFieldPaginator::class);

        $items = $gridField->getManipulatedList();

        foreach ($gridField->getConfig()->getComponents() as $component) {
            if ($component instanceof GridFieldFilterHeader || $component instanceof GridFieldSortableHeader) {
                $items = $component->getManipulatedData($gridField, $items);
            }
        }

        $gridFieldColumnsComponent = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $columnsHandled = $gridFieldColumnsComponent
            ? $gridFieldColumnsComponent->getColumnsHandled($gridField)
            : [];

        $items = $items->limit(null);

        foreach ($items as $item) {
            if (!$item->hasMethod('canView') || $item->canView()) {
                $columnData = [];

                foreach ($csvColumns as $columnSource => $columnHeader) {
                    if (!is_string($columnHeader) && is_callable($columnHeader)) {
                        if ($item->hasMethod($columnSource)) {
                            $relObj = $item->{$columnSource}();
                        } else {
                            $relObj = $item->relObject($columnSource);
                        }

                        $value = $columnHeader($relObj);
                    } elseif ($gridFieldColumnsComponent && in_array($columnSource, $columnsHandled ?? [])) {
                        $value = strip_tags(
                            $gridFieldColumnsComponent->getColumnContent($gridField, $item, $columnSource) ?? ''
                        );
                    } else {
                        $value = $gridField->getDataFieldValue($item, $columnSource);

                        if ($value === null) {
                            $value = $gridField->getDataFieldValue($item, $columnHeader);
                        }
                    }

                    $columnData[] = $value;
                }

                $csvWriter->insertOne($columnData);
            }

            if ($item->hasMethod('destroy')) {
                $item->destroy();
            }
        }

        return $csvWriter->toString();
    }
}
