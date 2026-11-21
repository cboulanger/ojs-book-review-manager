<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/form/ReviewEssayForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewEssayForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Create a review essay from works of the "Available works" list, or edit
 *  its title, description and offer settings (its works are managed with the
 *  row actions, see BundleMemberForm).
 */

namespace APP\plugins\generic\bookReviewManager\controllers\grid\form;

use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use APP\template\TemplateManager;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorInSet;
use PKP\form\validation\FormValidatorPost;

class ReviewEssayForm extends \PKP\form\Form {

	protected $plugin;
	protected $context;
	/** @var ?ObjectForReview The container (null: create a new review essay) */
	protected ?ObjectForReview $container;
	/** @var bool Whether execute() emailed a reviewer who was taken over */
	public bool $reviewerEmailed = false;

	public function __construct($plugin, $context, ?ObjectForReview $container = null) {
		parent::__construct($plugin->getTemplateResource('reviewEssayForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->container = $container;

		$this->addCheck(new FormValidatorCustom($this, 'title', 'required', 'plugins.generic.bookReviewManager.reviewEssay.titleRequired',
			fn ($title) => trim((string) $title) !== ''));
		if (!$container) {
			$this->addCheck(new FormValidatorCustom($this, 'objectIds', 'required', 'plugins.generic.bookReviewManager.reviewEssay.worksRequired',
				fn ($ids) => is_array($ids) && count($ids) > 0));
		}
		$this->addCheck(new FormValidatorInSet($this, 'assignmentMode', 'required', 'plugins.generic.bookReviewManager.offer.modeRequired', ObjectForReview::MODES));
		$this->addCheck(new FormValidatorCustom($this, 'bidDeadline', 'optional', 'plugins.generic.bookReviewManager.offer.bidDeadlineInvalid',
			fn ($date) => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date)) && $d->format('Y-m-d') === $date));
		$this->addCheck(new FormValidatorCustom($this, 'responsibleEditorId', 'optional', 'plugins.generic.bookReviewManager.offer.editorInvalid',
			fn ($id) => isset(Roles::getMembers($this->plugin, (int) $this->context->getId(), Roles::EDITOR)[(int) $id])));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Works that may be combined into a review essay.
	 * @return ObjectForReview[] id => work
	 */
	public static function getCombinableWorks(int $contextId): array {
		$works = [];
		$result = (new ObjectForReviewDAO())->getAll($contextId, true, true);
		while ($work = $result->next()) {
			if (!$work->isReviewEssay() && BundleWorkflow::canCombine([$work]) === null) {
				$works[$work->getId()] = $work;
			}
		}
		return $works;
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		if ($this->container) {
			$this->setData('title', $this->container->getTitle());
			$this->setData('description', $this->container->getDescription());
			$this->setData('assignmentMode', $this->container->getAssignmentMode());
			$this->setData('bidDeadline', $this->container->getData('bidDeadline'));
			$this->setData('responsibleEditorId', $this->container->getResponsibleEditorId());
		} else {
			// Defaults as for a new work (AvailableObjectsForReviewForm)
			$userId = Application::get()->getRequest()->getUser()?->getId();
			$this->setData('assignmentMode', $this->plugin->getSetting($this->context->getId(), 'ofrDefaultMode') ?: ObjectForReview::MODE_DIRECT);
			$editorId = (int) $this->plugin->getSetting($this->context->getId(), 'ofrDefaultEditorId');
			if (!$editorId && Roles::isBookReviewEditor($this->plugin, (int) $this->context->getId(), $userId)) {
				$editorId = $userId;
			}
			$this->setData('responsibleEditorId', $editorId ?: null);
			$this->setData('objectIds', []);
			$this->setData('copyPreferences', []);
			$this->setData('hidden', false);
		}
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	public function readInputData() {
		$this->readUserVars(['title', 'description', 'assignmentMode', 'bidDeadline', 'responsibleEditorId', 'objectIds', 'copyPreferences', 'hidden']);
	}

	/**
	 * The selected works (create mode), in the order of the list; unknown ids are null.
	 * @return array<?ObjectForReview>
	 */
	protected function getSelectedWorks(): array {
		$dao = new ObjectForReviewDAO();
		$works = [];
		foreach (array_unique(array_map('intval', (array) $this->getData('objectIds'))) as $id) {
			$work = $dao->getById($id);
			$works[] = $work && $work->getContextId() == $this->context->getId() ? $work : null;
		}
		return $works;
	}

	/**
	 * @copydoc Form::validate()
	 */
	public function validate($callHooks = true) {
		$valid = parent::validate($callHooks);
		if ($valid && !$this->container) {
			$error = BundleWorkflow::canCombine($this->getSelectedWorks());
			if ($error !== null) {
				$this->addError('objectIds', __($error));
				$valid = false;
			}
		}
		return $valid;
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		AvailableObjectsForReviewForm::assignOfferSettings($templateMgr, $this->plugin, (int) $this->context->getId());
		$templateMgr->assign('containerId', $this->container?->getId());
		if (!$this->container) {
			$dao = AssignmentWorkflow::dao();
			$copyPreferences = (array) $this->getData('copyPreferences');
			$works = [];
			foreach (self::getCombinableWorks((int) $this->context->getId()) as $id => $work) {
				$works[$id] = [
					'citation' => $work->getCitation(),
					'hasReviewer' => (bool) $dao->getReviewer($id),
					'copyPreference' => in_array($copyPreferences[$id] ?? null, Reservation::COPY_PREFERENCES, true)
						? $copyPreferences[$id] : Reservation::COPY_HAVE,
				];
			}
			$templateMgr->assign('works', $works);
			$templateMgr->assign('selectedIds', array_map('intval', (array) $this->getData('objectIds')));
			$templateMgr->assign('copyPreferenceOptions', self::copyPreferenceOptions());
		}
		return parent::fetch($request, $template, $display);
	}

	/**
	 * Options of a copy preference select.
	 */
	public static function copyPreferenceOptions(): array {
		$options = [];
		foreach (Reservation::COPY_PREFERENCES as $preference) {
			$options[$preference] = __('plugins.generic.bookReviewManager.reserve.copy.' . $preference);
		}
		return $options;
	}

	/**
	 * Create the review essay, or save the changes.
	 * @throws \InvalidArgumentException With a locale key, if BundleWorkflow refuses
	 * @return ObjectForReview The container
	 */
	public function execute(...$functionArgs) {
		$request = Application::get()->getRequest();
		$editor = $request->getUser();
		$dao = new ObjectForReviewDAO();
		$title = trim((string) $this->getData('title'));
		$description = trim((string) $this->getData('description'));
		$description = trim(strip_tags($description)) === '' ? null : $description;

		if ($this->container) {
			$container = $dao->getById($this->container->getId());
			$container->setTitle($title);
			$container->setDescription($description);
		} else {
			$copyPreferences = [];
			foreach ((array) $this->getData('copyPreferences') as $id => $preference) {
				if (in_array($preference, Reservation::COPY_PREFERENCES, true)) {
					$copyPreferences[(int) $id] = $preference;
				}
			}
			$container = BundleWorkflow::create($this->context, $title, $description, $this->getSelectedWorks(), $editor, $copyPreferences);
			$container->setOfferSetting('hidden', (bool) $this->getData('hidden'));
		}
		$bidding = $this->getData('assignmentMode') === ObjectForReview::MODE_BIDDING;
		$container->setOfferSetting('assignmentMode', $bidding ? ObjectForReview::MODE_BIDDING : null);
		$container->setOfferSetting('bidDeadline', $bidding ? ($this->getData('bidDeadline') ?: null) : null);
		$container->setOfferSetting('responsibleEditorId', (int) $this->getData('responsibleEditorId') ?: null);
		$dao->updateObject($container);

		if ($this->container) {
			// A changed assignment mode or bid deadline may end an invitation round
			InvitationWorkflow::onOfferChanged($container, $editor);
		} else {
			// A reviewer of one of the works was taken over: their agreement now covers all works
			$this->reviewerEmailed = ReservationMailer::sendEssayChanged($this->plugin, $this->context, $container, $editor);
		}
		return $container;
	}
}
