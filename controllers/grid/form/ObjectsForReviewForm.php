<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/form/ObjectsForReviewForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewForm
 * @ingroup controllers_grid_bookReviewManager
 *
 * Form for adding/editing an objectForReview
 *
 */
namespace APP\plugins\generic\bookReviewManager\controllers\grid\form;

use APP\plugins\generic\bookReviewManager\classes\ObjectsForReviewDAO;
use APP\plugins\generic\bookReviewManager\BookReviewManagerPlugin;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;

class ObjectsForReviewForm extends \PKP\form\Form {
	/** @var int Context ID */
	var $contextId;

	/** @var int Submission ID */
	var $submissionId;

    /** @var string objectId */
    public $objectId;

	/** @var BookReviewManagerPlugin */
	var $plugin;

	/**
	 * Constructor
	 * @param $bookReviewManagerPlugin BookReviewManagerPlugin
	 * @param $contextId int Context ID
	 * @param $submissionId int Submission ID
	 * @param $objectId int (optional) Review ID
	 */
	function __construct($bookReviewManagerPlugin, $contextId, $submissionId, $objectId = null) {
		parent::__construct($bookReviewManagerPlugin->getTemplateResource('editObjectForReviewForm.tpl'));

		$this->contextId = $contextId;
		$this->submissionId = $submissionId;
		$this->objectId = $objectId;
		$this->plugin = $bookReviewManagerPlugin;

		// Add form checks
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'identifierType', 'required', 'plugins.generic.bookReviewManager.identifierTypeRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'resourceType', 'required', 'plugins.generic.bookReviewManager.resourceTypeRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'title', 'required', 'plugins.generic.bookReviewManager.titleRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'identifier', 'required', 'plugins.generic.bookReviewManager.identifierRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
		$this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));

	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		$this->setData('submissionId', $this->submissionId);
		if ($this->objectId) {
			$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
			$objectForReview = $objectForReviewDao->getById($this->objectId, $this->submissionId);
			if (!$objectForReview) {
				throw new \Exception('Object for review not found in this submission.');
			}
			$this->setData('identifierType', $objectForReview->getIdentifierType());
			$this->setData('resourceType', $objectForReview->getResourceType());
			$this->setData('identifier', $objectForReview->getIdentifier());
			$this->setData('authors', $objectForReview->getAuthors());
			$this->setData('title', $objectForReview->getTitle());
			$this->setData('year', $objectForReview->getYear());
			$this->setData('publisher', $objectForReview->getPublisher());
		}
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array('identifierType', 'resourceType', 'identifier', 'authors', 'title', 'year', 'publisher'));
	}

	/**
	 * @copydoc Form::fetch
	 */
	function fetch($request, $template = NULL, $display = false) {
		$templateMgr = TemplateManager::getManager();
		$identifierTypes = $this->_getIdentifierTypes();
		$resourceTypes = $this->_getResourceTypes(null);
		$currentUser = $request->getUser();
		$context = $request->getContext();

		// Get reserved objects for review
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$bookReviewManager = $objectForReviewDao->getByUserId($currentUser->getId(), NULL, true);

		if ($bookReviewManager){
			$reservedObjects = array();
			while ($objectForReview = $bookReviewManager->next()) {
				// The works of a review essay are attached with the essay
				if ($objectForReview->isBundleMember()) {
					continue;
				}
				$objectId = $objectForReview->getId();
				$reservedObjects[$objectId] = array(
					'objectId' => $objectId,
					'description' => $objectForReview->isReviewEssay() ? $objectForReview->getCitation()
						: $objectForReview->getAuthors() . ": " . $objectForReview->getTitle(),
					'userId' => $objectForReview->getUserId()
				);
			}
			$templateMgr->assign('reservedObjects', $reservedObjects);
		}

		if ($this->plugin->getSetting($context->getId(), 'onlyReserved')){
			$templateMgr->assign('onlyReserved', true);
		}

		$templateMgr->assign('objectId', $this->objectId);
		$templateMgr->assign('submissionId', $this->submissionId);
		$templateMgr->assign('identifierTypes', $identifierTypes);
		$templateMgr->assign('resourceTypes', $resourceTypes);
		$templateMgr->assign('identifierLookupTemplate', $this->plugin->getTemplateResource('identifierLookup.tpl'));

		return parent::fetch($request);
	}

	/**
	 * Save form values into the database
	 */
	function execute(...$functionArgs) {
		$objectId = $this->objectId;
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');

		if ($objectId) {
			// Load and update an existing objectForReview
			$objectForReview = $objectForReviewDao->getById($this->objectId, $this->submissionId);
		} else {
			// Create a new objectForReview
			$objectForReview = $objectForReviewDao->newDataObject();
			$objectForReview->setContextId($this->contextId);
			$objectForReview->setSubmissionId($this->submissionId);
		}

		$objectForReview->setIdentifier($this->getData('identifier'));
		$objectForReview->setIdentifierType($this->getData('identifierType'));
		$objectForReview->setResourceType($this->getData('resourceType'));	
		$objectForReview->setAuthors($this->getData('authors'));
		$objectForReview->setTitle(trim((string) $this->getData('title')));
		$objectForReview->setYear($this->getData('year'));
		$objectForReview->setPublisher($this->getData('publisher'));
		$objectForReview->setCreator("workflow");

		if ($objectId) {
			$objectForReviewDao->updateObject($objectForReview);
			return $objectId;
		} else {
			return $objectForReviewDao->insertObject($objectForReview);
		}
	}

	/**
	 * List identifierTypes 
	 * See Crossref Schema isReviewOf relation types and Datacite relationType
	 * @return array
	 */
	function _getIdentifierTypes() {
		return array(
				'DOI'=>'doi',
				'ISSN'=>'issn',
				'ISBN'=>'isbn',
				'Link'=>'uri',
				'urn'=>'urn',				
				'pmid'=>'pmid',
				'pmcid'=>'pmcid',
				'purl'=>'purl',
				'arxiv'=>'arxiv',
				'ark'=>'ark',
				'handle'=>'handle',
				'uuid'=>'uuid',
				'ecli'=>'ecli',
				'accession'=>'accession',
				'other'=>'other'
		);
	}

	/**
	 * Get a COAR Resource Type by URI. If $uri is null return all.
	 * @param $uri string
	 * @return mixed
	 */
	function _getResourceTypes($uri = null) {
		$resourceTypes = array(
				'http://purl.org/coar/resource_type/c_2f33' => 'plugins.generic.bookReviewManager.COAR.book',
				'http://purl.org/coar/resource_type/c_3248' => 'plugins.generic.bookReviewManager.COAR.bookPart',
				'http://purl.org/coar/resource_type/c_6501' => 'plugins.generic.bookReviewManager.COAR.journalArticle',
				'http://purl.org/coar/resource_type/c_5794' => 'plugins.generic.bookReviewManager.COAR.conferencePaper',
				'http://purl.org/coar/resource_type/c_46ec' => 'plugins.generic.bookReviewManager.COAR.thesis',
				'http://purl.org/coar/resource_type/c_816b' => 'plugins.generic.bookReviewManager.COAR.preprint',
				'http://purl.org/coar/resource_type/c_7ad9' => 'plugins.generic.bookReviewManager.COAR.website',
				'http://purl.org/coar/resource_type/c_ddb1' => 'plugins.generic.bookReviewManager.COAR.dataset',
				'http://purl.org/coar/resource_type/c_ddb1' => 'plugins.generic.bookReviewManager.COAR.software',
				'http://purl.org/coar/resource_type/c_12cc' => 'plugins.generic.bookReviewManager.COAR.cartographicMaterial',
				'http://purl.org/coar/resource_type/c_18cc' => 'plugins.generic.bookReviewManager.COAR.sound'
		);
		if ($uri){
			return $resourceTypes[$uri];
		} else {
			return $resourceTypes;
		}
	}
}

?>
