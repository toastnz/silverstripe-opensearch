<?php

namespace Toast\OpenSearch\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\SiteConfig\SiteConfig;
use Toast\OpenSearch\Import\OpenSearchSynonymCsvBulkLoader;

class SiteConfigLeftAndMainExtension extends Extension
{
    private static $allowed_actions = [
        'OpenSearchSynonymImportForm',
        'doOpenSearchSynonymImport',
    ];

    public function OpenSearchSynonymImportForm()
    {
        $siteConfig = $this->getCurrentSiteConfig();

        if (!$siteConfig || !$siteConfig->exists()) {
            return false;
        }

        $fields = FieldList::create(
            HiddenField::create('SiteConfigID', false, (string) $siteConfig->ID),
            FileField::create('_CsvFile', 'CSV file'),
            LiteralField::create(
                'OpenSearchSynonymImportHelp',
                '<p>Use a CSV with <code>SearchTerms</code> and <code>SynonymTerms</code> columns. Keep multiple terms in a single cell separated by commas or line breaks.</p>'
            ),
            CheckboxField::create(
                'EmptyBeforeImport',
                'Replace existing synonym rules',
                false
            )
        );

        $actions = FieldList::create(
            FormAction::create('doOpenSearchSynonymImport', 'Import from CSV')
                ->addExtraClass('btn btn-outline-secondary font-icon-upload')
        );

        $form = Form::create(
            $this->owner,
            'OpenSearchSynonymImportForm',
            $fields,
            $actions
        );
        $form->setFormAction(
            Controller::join_links($this->owner->Link(), 'OpenSearchSynonymImportForm')
        );

        return $form;
    }

    public function doOpenSearchSynonymImport(array $data, Form $form)
    {
        $siteConfig = $this->getCurrentSiteConfig();

        if (!$siteConfig || !$siteConfig->exists()) {
            $form->sessionMessage(
                'Save the site configuration before importing synonym rules.',
                ValidationResult::TYPE_ERROR
            );
            return $this->owner->redirectBack();
        }

        if (!$siteConfig->canEdit()) {
            return $this->owner->httpError(403);
        }

        if (empty($_FILES['_CsvFile']['tmp_name']) || file_get_contents($_FILES['_CsvFile']['tmp_name'] ?? '') === '') {
            $form->sessionMessage(
                'Please browse for a CSV file to import.',
                ValidationResult::TYPE_ERROR
            );
            return $this->owner->redirectBack();
        }

        try {
            $replaceExisting = !empty($data['EmptyBeforeImport']);
            $synonyms = $siteConfig->OpenSearchSynonyms();

            if ($replaceExisting) {
                $synonyms->removeAll();
            }

            $importer = OpenSearchSynonymCsvBulkLoader::create()
                ->forSiteConfig($siteConfig)
                ->setCheckPermissions(true);

            if ($replaceExisting) {
                $importer->resetSortOrder();
            }

            $results = $importer->load($_FILES['_CsvFile']['tmp_name']);
            $count = $results ? $results->Count() : 0;

            $form->sessionMessage(
                sprintf('Imported %d synonym rule%s.', $count, $count === 1 ? '' : 's'),
                ValidationResult::TYPE_GOOD
            );
        } catch (\Throwable $exception) {
            $form->sessionMessage($exception->getMessage(), ValidationResult::TYPE_ERROR);
        }

        return $this->owner->redirectBack();
    }

    private function getCurrentSiteConfig(): ?SiteConfig
    {
        $record = $this->owner->currentRecord();

        if ($record instanceof SiteConfig && $record->exists()) {
            return $record;
        }

        $current = SiteConfig::current_site_config();

        return $current->exists() ? $current : null;
    }
}
