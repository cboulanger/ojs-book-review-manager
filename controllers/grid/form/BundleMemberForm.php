<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/form/BundleMemberForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BundleMemberForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Add a work to a review essay that is not submitted yet:
 *  - add mode (row action of a review essay): choose the work;
 *  - choose mode (row action of a work): choose the review essay.
 *  If the review essay has a reviewer, the editor chooses the reviewer's copy
 *  preference for the work, and the reviewer is emailed.
 */

namespace APP\plugins\generic\bookReviewManager\controllers\grid\form;

use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\template\TemplateManager;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class BundleMemberForm extends \PKP\form\Form {

	const MODE_ADD = 'add';
	const MODE_CHOOSE = 'choose';

	protected $plugin;
	protected $context;
	/** @var ?ObjectForReview The review essay (add mode) */
	protected ?ObjectForReview $container;
	/** @var ?ObjectForReview The work (choose mode) */
	protected ?ObjectForReview $work;
	/** @var bool Whether execute() emailed the reviewer of the review essay */
	public bool $reviewerEmailed = false;

	/**
	 * @param ?ObjectForReview $container Add mode: the review essay to add a work to
	 * @param ?ObjectForReview $work Choose mode: the work to add to a review essay
	 */
	public function __construct($plugin, $context, ?ObjectForReview $container, ?ObjectForReview $work = null) {
		parent::__construct($plugin->getTemplateResource('bundleMemberForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->container = $container;
		$this->work = $container ? null : $work;

		$this->addCheck(new FormValidatorCustom($this, $this->getMode() === self::MODE_ADD ? 'workId' : 'containerId', 'required',
			'plugins.generic.bookReviewManager.reviewEssay.selectRequired',
			fn ($id) => isset(($this->getMode() === self::MODE_ADD ? $this->getWorks() : $this->getContainers())[(int) $id])));
		$this->addCheck(new FormValidatorCustom($this, 'copyPreference', 'optional', 'plugins.generic.bookReviewManager.reviewEssay.selectRequired',
			fn ($preference) => in_array($preference, Reservation::COPY_PREFERENCES, true)));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	public function getMode(): string {
		return $this->container ? self::MODE_ADD : self::MODE_CHOOSE;
	}

	/**
	 * Works that may be added (add mode).
	 * @return ObjectForReview[] id => work
	 */
	protected function getWorks(): array {
		// A work with its own reviewer cannot join (BundleWorkflow::addMember: the essay has one reviewer)
		$dao = AssignmentWorkflow::dao();
		return array_filter(ReviewEssayForm::getCombinableWorks((int) $this->context->getId()),
			fn ($work) => !$dao->getReviewer((int) $work->getId()));
	}

	/**
	 * Review essays that are not submitted (choose mode).
	 * @return ObjectForReview[] id => container
	 */
	protected function getContainers(): array {
		$containers = [];
		$result = (new ObjectForReviewDAO())->getAll((int) $this->context->getId(), true, true);
		while ($object = $result->next()) {
			if ($object->isReviewEssay()) {
				$containers[$object->getId()] = $object;
			}
		}
		return $containers;
	}

	/**
	 * Does the review essay have a reviewer with an accepted agreement?
	 */
	protected static function hasReviewer(ObjectForReview $container): bool {
		$assignment = BundleWorkflow::getReviewerAssignment($container);
		return $assignment && $assignment->getStatus() === Assignment::STATUS_ACCEPTED;
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		$this->setData('copyPreference', Reservation::COPY_HAVE);
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	public function readInputData() {
		$this->readUserVars(['workId', 'containerId', 'copyPreference']);
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('mode', $this->getMode());
		if ($this->getMode() === self::MODE_ADD) {
			$templateMgr->assign('containerId', $this->container->getId());
			$templateMgr->assign('essayCitation', $this->container->getCitation());
			$templateMgr->assign('options', array_map(fn (ObjectForReview $work) => $work->getCitation(), $this->getWorks()));
			$templateMgr->assign('reviewerEssayIds', self::hasReviewer($this->container) ? [(int) $this->container->getId()] : []);
		} else {
			$containers = $this->getContainers();
			$templateMgr->assign('workId', $this->work->getId());
			$templateMgr->assign('workCitation', $this->work->getCitation());
			$templateMgr->assign('options', array_map(fn (ObjectForReview $container) => $container->getCitation(), $containers));
			$templateMgr->assign('reviewerEssayIds', array_values(array_map('intval',
				array_keys(array_filter($containers, fn (ObjectForReview $container) => self::hasReviewer($container))))));
		}
		$templateMgr->assign('reviewerEssayIdsJson', json_encode($templateMgr->getTemplateVars('reviewerEssayIds')));
		$templateMgr->assign('copyPreferenceOptions', ReviewEssayForm::copyPreferenceOptions());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * Add the work to the review essay and email the reviewer, if there is one.
	 * @throws \InvalidArgumentException With a locale key, if BundleWorkflow refuses
	 * @return ObjectForReview The container
	 */
	public function execute(...$functionArgs) {
		$dao = new ObjectForReviewDAO();
		if ($this->getMode() === self::MODE_ADD) {
			$container = $this->container;
			$work = $dao->getById((int) $this->getData('workId'));
		} else {
			$container = $dao->getById((int) $this->getData('containerId'));
			$work = $this->work;
		}
		$editor = Application::get()->getRequest()->getUser();
		$hasReviewer = self::hasReviewer($container);
		BundleWorkflow::addMember($container, $work, $editor, $hasReviewer ? $this->getData('copyPreference') : null);
		if ($hasReviewer) {
			$this->reviewerEmailed = ReservationMailer::sendEssayChanged($this->plugin, $this->context, $container, $editor);
		}
		return $container;
	}
}
