<?php

namespace Toast\OpenSearch\Forms;

use SilverStripe\Control\RequestHandler;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validation\Validator;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\DataObject;
use Toast\OpenSearch\Helpers\OpenSearch;

class SearchForm extends Form
{
    private static $casting = [
        'SearchQuery' => 'Text'
    ];

    public function __construct(?RequestHandler $controller = null, $name = Form::DEFAULT_NAME, ?FieldList $fields = null, ?FieldList $actions = null, ?Validator $validator = null)
    {
        if (!$fields) {
            $fields = FieldList::create(
                TextField::create('Search', 'Search')
            );
        }

        if (!$actions) {
            $actions = FieldList::create(
                FormAction::create('searchResults', 'Go')
            );
        }

        parent::__construct($controller, $name, $fields, $actions, $validator);

        $this->setFormMethod('get');
        $this->disableSecurityToken();

        $this->extend('updateForm', $this);
    }


    public function getResults()
    {
        $request = $this->getRequestHandler()->getRequest();
        $searchTerm = $request->requestVar('Search');
        $this->extend('updateSearchTerm', $searchTerm);

        $result = OpenSearch::singleton()
            ->search($searchTerm);
        $result->Matches = $this->hydrateMatches($result->Matches);

        $this->extend('updateResults', $result);

        if ($this->config()->get('enable_pagination') === false) {
            return $result->Matches;
        }

        $results = PaginatedList::create($result->Matches, $this->getRequest())
            ->setPageLength($this->config()->get('results_per_page'))
            ->setPaginationGetVar('start');

        $this->extend('updatePaginatedResults', $results);

        return $results;
    }

    protected function hydrateMatches($matches): ArrayList
    {
        $records = ArrayList::create();

        foreach ($matches ?: [] as $match) {
            if ($match instanceof DataObject) {
                $records->push($match);
                continue;
            }

            $className = $match->ClassName ?? null;
            $recordId = $this->extractRecordIdFromMatch($match);

            if (!is_string($className) || $className === '' || $recordId === null || $recordId === '') {
                continue;
            }

            if (!class_exists($className)) {
                continue;
            }

            $records->push($className::get()->byID($recordId));
        }

        return $records;
    }

    protected function extractRecordIdFromMatch($match): int|string|null
    {
        $recordId = $match->ID ?? null;

        if ($recordId !== null && $recordId !== '') {
            return $recordId;
        }

        $documentId = $match->DocumentID ?? null;

        if (!is_string($documentId) || $documentId === '') {
            return null;
        }

        $separatorPosition = strrpos($documentId, ':');

        if ($separatorPosition === false) {
            return $documentId;
        }

        return substr($documentId, $separatorPosition + 1);
    }


    public function getSearchQuery()
    {
        return $this->getRequestHandler()->getRequest()->requestVar('Search');
    }

}
