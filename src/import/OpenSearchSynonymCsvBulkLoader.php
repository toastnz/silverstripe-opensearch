<?php

namespace Toast\OpenSearch\Import;

use League\Csv\MapIterator;
use League\Csv\Reader;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Dev\BulkLoader_Result;
use SilverStripe\ORM\HasManyList;
use SilverStripe\SiteConfig\SiteConfig;
use Toast\OpenSearch\Models\OpenSearchSynonym;

class OpenSearchSynonymCsvBulkLoader extends CsvBulkLoader
{
    private ?SiteConfig $siteConfig = null;

    private int $siteConfigID = 0;

    private int $nextSortOrder = 1;

    public function __construct($objectClass = OpenSearchSynonym::class)
    {
        parent::__construct($objectClass);

        $this->columnMap = [
            'SearchTerms' => 'SearchTerms',
            'SynonymTerms' => 'SynonymTerms',
        ];
        $this->duplicateChecks = [];
    }

    public function forSiteConfig(SiteConfig $siteConfig): self
    {
        $this->siteConfig = $siteConfig;
        $this->siteConfigID = (int) $siteConfig->ID;
        $this->nextSortOrder = ((int) $siteConfig->OpenSearchSynonyms()->max('SortOrder')) + 1;

        return $this;
    }

    public function resetSortOrder(int $startAt = 1): self
    {
        $this->nextSortOrder = $startAt;

        return $this;
    }

    public function getImportSpec()
    {
        return [
            'fields' => [
                'SearchTerms' => 'When searching for these',
                'SynonymTerms' => 'Also search for these',
            ],
            'relations' => [],
        ];
    }

    protected function processAll($filepath, $preview = false)
    {
        $this->extend('onBeforeProcessAll', $filepath, $preview);

        $result = BulkLoader_Result::create();

        try {
            $filepath = Director::getAbsFile($filepath);
            $csvReader = Reader::from($filepath, 'r');
            $csvReader->setDelimiter($this->delimiter);
            $csvReader->skipInputBOM();

            $tabExtractor = function ($row, $rowOffset) {
                foreach ($row as &$item) {
                    if (preg_match("/^\t[\-@=\+]+.*/", $item ?? '')) {
                        $item = ltrim($item ?? '', "\t");
                    }
                }

                return $row;
            };

            if ($this->columnMap) {
                $headerMap = $this->getNormalisedColumnMap();

                $remapper = function ($row, $rowOffset) use ($headerMap, $tabExtractor) {
                    $row = $tabExtractor($row, $rowOffset);
                    foreach ($headerMap as $column => $renamedColumn) {
                        if ($column == $renamedColumn) {
                            continue;
                        }
                        if (array_key_exists($column, $row ?? [])) {
                            if (strpos($renamedColumn ?? '', '_ignore_') !== 0) {
                                $row[$renamedColumn] = $row[$column];
                            }
                            unset($row[$column]);
                        }
                    }

                    return $row;
                };
            } else {
                $remapper = $tabExtractor;
            }

            if ($this->hasHeaderRow) {
                if (method_exists($csvReader, 'fetchAssoc')) {
                    $rows = $csvReader->fetchAssoc(0, $remapper);
                } else {
                    $csvReader->setHeaderOffset(0);
                    $rows = new MapIterator($csvReader->getRecords(), $remapper);
                }
            } elseif ($this->columnMap) {
                if (method_exists($csvReader, 'fetchAssoc')) {
                    $rows = $csvReader->fetchAssoc($headerMap, $remapper);
                } else {
                    $rows = new MapIterator($csvReader->getRecords($headerMap), $remapper);
                }
            }

            foreach ($rows as $row) {
                $this->processRecord($row, $this->columnMap, $result, $preview);
            }
        } catch (\Exception $exception) {
            if ($exception instanceof HTTPResponse_Exception) {
                throw $exception;
            }

            $failedMessage = sprintf('Failed to parse %s', $filepath);
            if (Director::isDev()) {
                $failedMessage = sprintf($failedMessage . ' because %s', $exception->getMessage());
            }
            print $failedMessage . PHP_EOL;
        }

        $this->extend('onAfterProcessAll', $result, $preview);

        return $result;
    }

    protected function processRecord($record, $columnMap, &$results, $preview = false)
    {
        if ($this->isEmptyRecord($record)) {
            return 0;
        }

        $synonyms = $this->getSynonymList();

        if (!$synonyms || $preview) {
            return 0;
        }

        $synonym = $synonyms->newObject();
        $synonym->update([
            'SearchTerms' => $record['SearchTerms'] ?? null,
            'SynonymTerms' => $record['SynonymTerms'] ?? null,
            'SortOrder' => $this->nextSortOrder++,
        ]);

        $synonyms->add($synonym);
        $results->addCreated($synonym);

        return (int) $synonym->ID;
    }

    private function isEmptyRecord(array $record): bool
    {
        foreach (['SearchTerms', 'SynonymTerms'] as $fieldName) {
            if (trim((string) ($record[$fieldName] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function getSynonymList(): ?HasManyList
    {
        if (!$this->siteConfig || !$this->siteConfig->exists() || !$this->siteConfigID) {
            return null;
        }

        return $this->siteConfig->OpenSearchSynonyms();
    }
}
