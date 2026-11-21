<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/form/AvailableObjectsForReviewForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AvailableObjectsForReviewForm
 * @ingroup controllers_grid_bookReviewManagerManager
 *
 * Form for adding/editing an available objectForReview
 *
 */
namespace APP\plugins\generic\bookReviewManager\controllers\grid\form;

use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use APP\plugins\generic\bookReviewManager\BookReviewManagerPlugin;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;

class AvailableobjectsForReviewForm extends \PKP\form\Form {
	/** @var int Context ID */
	var $contextId;

    /** @var string objectId */
    public $objectId;

	/** @var BookReviewManagerPlugin */
	var $plugin;

	/**
	 * Constructor
	 * @param $bookReviewManagerPlugin BookReviewManagerPlugin
	 * @param $contextId int Context ID
	 * @param $objectId int (optional) Review ID
	 */
	function __construct($bookReviewManagerPlugin, $contextId, $objectId = null) {
		parent::__construct($bookReviewManagerPlugin->getTemplateResource('editAvailableObjectForReviewForm.tpl'));

		$this->contextId = $contextId;
		$this->objectId = $objectId;
		$this->plugin = $bookReviewManagerPlugin;

		// Add form checks
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'identifierType', 'required', 'plugins.generic.bookReviewManager.identifierTypeRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'resourceType', 'required', 'plugins.generic.bookReviewManager.resourceTypeRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'title', 'required', 'plugins.generic.bookReviewManager.titleRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidator($this, 'identifier', 'required', 'plugins.generic.bookReviewManager.identifierRequired'));
		$this->addCheck(new \PKP\form\validation\FormValidatorInSet($this, 'assignmentMode', 'required', 'plugins.generic.bookReviewManager.offer.modeRequired', ObjectForReview::MODES));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'bidDeadline', 'optional', 'plugins.generic.bookReviewManager.offer.bidDeadlineInvalid',
			fn ($date) => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date)) && $d->format('Y-m-d') === $date));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'responsibleEditorId', 'optional', 'plugins.generic.bookReviewManager.offer.editorInvalid',
			fn ($id) => isset(Roles::getMembers($this->plugin, (int) $this->contextId, Roles::EDITOR)[(int) $id])));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'publisherContactId', 'optional', 'plugins.generic.bookReviewManager.offer.publisherContactInvalid',
			fn ($id) => isset(Roles::getMembers($this->plugin, (int) $this->contextId, Roles::PUBLISHER)[(int) $id])));
		// Physical copies are returned to the responsible editor (journal stock) or the publisher contact: their addresses are needed
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'journalHoldsCopy', 'optional', 'plugins.generic.bookReviewManager.offer.editorAddressRequired',
			fn ($holds) => !$this->getData('returnRequired') || $this->hasMailingAddress((int) $this->getData('responsibleEditorId'))));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'publisherContactId', 'optional', 'plugins.generic.bookReviewManager.offer.publisherAddressRequired',
			fn ($id) => !$this->getData('returnRequired') || $this->hasMailingAddress((int) $id)));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'notifyPublisher', 'optional', 'plugins.generic.bookReviewManager.offer.notifyNeedsContact',
			fn ($notify) => (bool) $this->getData('publisherContactId')));
		$this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
		$this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));

	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		if ($this->objectId) {
			$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
			$objectForReview = $objectForReviewDao->getById($this->objectId);
			$this->setData('identifierType', $objectForReview->getIdentifierType());
			$this->setData('resourceType', $objectForReview->getResourceType());
			$this->setData('identifier', $objectForReview->getIdentifier());
			$this->setData('authors', $objectForReview->getAuthors());
			$this->setData('title', $objectForReview->getTitle());
			$this->setData('description', $objectForReview->getDescription());
			$this->setData('year', $objectForReview->getYear());
			$this->setData('publisher', $objectForReview->getPublisher());
			$this->setData('assignmentMode', $objectForReview->getAssignmentMode());
			$this->setData('bidDeadline', $objectForReview->getData('bidDeadline'));
			$this->setData('responsibleEditorId', $objectForReview->getResponsibleEditorId());
			$this->setData('publisherContactId', $objectForReview->getPublisherContactId());
			$this->setData('journalHoldsCopy', (bool) $objectForReview->getData('journalHoldsCopy'));
			$this->setData('returnRequired', $objectForReview->isReturnRequired());
			$this->setData('notifyPublisher', (bool) $objectForReview->getData('notifyPublisher'));
		} else {
			$this->setData('returnRequired', true);
			// Defaults for new offers: settings, or the current user if they are a book review editor
			$userId = Application::get()->getRequest()->getUser()?->getId();
			$this->setData('assignmentMode', $this->plugin->getSetting($this->contextId, 'ofrDefaultMode') ?: ObjectForReview::MODE_DIRECT);
			$editorId = (int) $this->plugin->getSetting($this->contextId, 'ofrDefaultEditorId');
			if (!$editorId && Roles::isBookReviewEditor($this->plugin, (int) $this->contextId, $userId)) {
				$editorId = $userId;
			}
			$this->setData('responsibleEditorId', $editorId ?: null);
		}
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array('identifierType', 'resourceType', 'identifier', 'authors', 'title', 'description', 'year', 'publisher', 'assignmentMode', 'bidDeadline', 'responsibleEditorId',
			'publisherContactId', 'journalHoldsCopy', 'returnRequired', 'notifyPublisher'));
	}

	/**
	 * @copydoc Form::fetch
	 */
	function fetch($request, $template = NULL, $display = false) {
		$templateMgr = TemplateManager::getManager();
		$identifierTypes = $this->_getIdentifierTypes();
		$resourceTypes = $this->_getResourceTypes(null);
		$templateMgr->assign('objectId', $this->objectId);
		$templateMgr->assign('identifierTypes', $identifierTypes);
		$templateMgr->assign('resourceTypes', $resourceTypes);
		$templateMgr->assign('identifierLookupTemplate', $this->plugin->getTemplateResource('identifierLookup.tpl'));
		self::assignOfferSettings($templateMgr, $this->plugin, (int) $this->contextId);
		$publisherContacts = Roles::getMembers($this->plugin, (int) $this->contextId, Roles::PUBLISHER);
		$templateMgr->assign('hasPublisherContacts', (bool) $publisherContacts);
		$templateMgr->assign('publisherContacts', ['' => __('plugins.generic.bookReviewManager.offer.noPublisherContact')] + $publisherContacts);
		return parent::fetch($request);
	}

	/**
	 * Template variables of the offer settings (offerSettingsFields.tpl), also used by ReviewEssayForm.
	 */
	public static function assignOfferSettings(TemplateManager $templateMgr, $plugin, int $contextId): void {
		$templateMgr->assign('offerSettingsTemplate', $plugin->getTemplateResource('offerSettingsFields.tpl'));
		$templateMgr->assign('assignmentModes', [
			ObjectForReview::MODE_DIRECT => __('plugins.generic.bookReviewManager.offer.mode.direct'),
			ObjectForReview::MODE_BIDDING => __('plugins.generic.bookReviewManager.offer.mode.bidding'),
		]);
		$templateMgr->assign('bookReviewEditors', ['' => __('plugins.generic.bookReviewManager.offer.noEditor')]
			+ Roles::getMembers($plugin, $contextId, Roles::EDITOR));
	}

	/**
	 * Save form values into the database
	 */
	function execute(...$functionArgs) {
		$objectId = $this->objectId;
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');

		if ($objectId) {
			// Load and update an existing objectForReview
			$objectForReview = $objectForReviewDao->getById($this->objectId);
		} else {
			// Create a new objectForReview
			$objectForReview = $objectForReviewDao->newDataObject();
			$objectForReview->setContextId($this->contextId);
		}

		$objectForReview->setIdentifier($this->getData('identifier'));
		$objectForReview->setIdentifierType($this->getData('identifierType'));
		$objectForReview->setResourceType($this->getData('resourceType'));
		$objectForReview->setAuthors($this->getData('authors'));
		$objectForReview->setTitle(trim((string) $this->getData('title')));
		$description = trim((string) $this->getData('description'));
		$objectForReview->setDescription(trim(strip_tags($description)) === '' ? null : $description);
		$objectForReview->setYear($this->getData('year'));
		$objectForReview->setPublisher($this->getData('publisher'));
		$objectForReview->setCreator("manager");
		$objectForReview->setOfferSetting('assignmentMode', $this->getData('assignmentMode') === ObjectForReview::MODE_BIDDING ? ObjectForReview::MODE_BIDDING : null);
		$objectForReview->setOfferSetting('bidDeadline', $this->getData('assignmentMode') === ObjectForReview::MODE_BIDDING ? $this->getData('bidDeadline') : null);
		$objectForReview->setOfferSetting('responsibleEditorId', (int) $this->getData('responsibleEditorId') ?: null);
		$objectForReview->setOfferSetting('publisherContactId', (int) $this->getData('publisherContactId') ?: null);
		$objectForReview->setOfferSetting('journalHoldsCopy', (bool) $this->getData('journalHoldsCopy'));
		$objectForReview->setData('returnRequired', (bool) $this->getData('returnRequired'));
		$objectForReview->setOfferSetting('notifyPublisher', (bool) $this->getData('notifyPublisher'));

		if ($objectId) {
			$objectForReviewDao->updateObject($objectForReview);
			// A changed assignment mode or bid deadline may end an invitation round
			\APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow::onOfferChanged($objectForReview,
				\APP\core\Application::get()->getRequest()->getUser());
		} else {
			$objectForReview = $objectForReviewDao->insertObject($objectForReview);
		}

	}

	/**
	 * Has the user entered a mailing address in their profile?
	 */
	protected function hasMailingAddress(int $userId): bool {
		$user = $userId ? \APP\facades\Repo::user()->get($userId, true) : null;
		return $user && trim((string) $user->getMailingAddress()) !== '';
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
