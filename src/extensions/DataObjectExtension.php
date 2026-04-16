<?php

namespace Toast\OpenSearch\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Toast\OpenSearch\Helpers\OpenSearch;
use Toast\OpenSearch\Search\OpenSearchIndex;

class DataObjectExtension extends Extension
{
    protected array $dependentRecordsPendingReindex = [];

    public function onAfterWrite()
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
                $this->syncRecordUpdate($search, $index);
            });
        }
    }

    public function onBeforeDelete()
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
                $this->dependentRecordsPendingReindex = $index->supportsRelatedRecordChanges($this->getOwnerRecord())
                    ? $index->getDependentRecords($this->getOwnerRecord())
                    : [];
            });
        }
    }

    public function onAfterDelete()
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
                $this->syncRecordDeletion($search, $index);
            });
        }
    }

    public function onAfterPublish()
    {
        $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
            $this->syncRecordUpdate($search, $index);
        });
    }

    public function onBeforeUnpublish()
    {
        $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
            $this->dependentRecordsPendingReindex = $index->supportsRelatedRecordChanges($this->getOwnerRecord())
                ? $index->getDependentRecords($this->getOwnerRecord())
                : [];
        });
    }

    public function onAfterUnpublish()
    {
        $this->handleRecordHook(function (OpenSearch $search, OpenSearchIndex $index): void {
            $this->syncRecordDeletion($search, $index);
        });
    }

    protected function handleRecordHook(callable $callback): void
    {
        $search = OpenSearch::singleton();

        try {
            $index = $search->getIndexDefinition();

            if (!$index->shouldHandleRecordHooks($this->getOwnerRecord())) {
                $this->dependentRecordsPendingReindex = [];
                return;
            }

            $callback($search, $index);
        } catch (\Throwable $exception) {
            $this->dependentRecordsPendingReindex = [];

            if (!$search->shouldRecordOperationsFailSilently()) {
                throw $exception;
            }
        }
    }

    protected function syncRecordUpdate(OpenSearch $search, OpenSearchIndex $index): void
    {
        $owner = $this->getOwnerRecord();

        if ($index->supportsRecord($owner)) {
            $search->updateIndex($owner, null, $index);
        }

        if ($index->supportsRelatedRecordChanges($owner)) {
            $search->updateRelatedRecords($owner, $index);
        }
    }

    protected function syncRecordDeletion(OpenSearch $search, OpenSearchIndex $index): void
    {
        $owner = $this->getOwnerRecord();

        if ($index->supportsRecord($owner)) {
            $search->deleteRecord($owner, $index);
        }

        if ($this->dependentRecordsPendingReindex !== []) {
            $search->updateRecords($this->dependentRecordsPendingReindex, $index);
        }

        $this->dependentRecordsPendingReindex = [];
    }

    protected function getOwnerRecord(): DataObject
    {
        return $this->owner;
    }
}
