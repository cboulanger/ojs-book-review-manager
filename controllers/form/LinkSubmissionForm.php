<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/form/LinkSubmissionForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LinkSubmissionForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief The book review editor links an accepted reservation to the review submission,
 *  for reviews submitted without choosing the reserved work in the submission wizard
 *  (another account, submitted on the reviewer's behalf, work typed in by hand).
 *  The reviewer's submissions are suggested; any submission of the journal can be
 *  given by its ID. No email is sent.
 */

namespace APP\plugins\generic\bookReviewManager\controllers\form;

use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\security\Role;

class LinkSubmissionForm extends \PKP\form\Form {

	protected $plugin;
	protected $context;
	protected ObjectForReview $object;
	protected Assignment $assignment;

	public function __construct($plugin, $context, ObjectForReview $object, Assignment $assignment) {
		parent::__construct($plugin->getTemplateResource('linkSubmissionForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->object = $object;
		$this->assignment = $assignment;

		$this->addCheck(new FormValidatorCustom($this, 'submissionId', 'required', 'plugins.generic.bookReviewManager.linkSubmission.error.required',
			fn () => $this->getSelectedId() > 0));
		$this->addCheck(new FormValidatorCustom($this, 'submissionId', 'required', 'plugins.generic.bookReviewManager.linkSubmission.error.notFound',
			fn () => $this->getSelectedId() <= 0 || $this->getSubmission() !== null));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * The chosen submission ID: typed in, or else chosen from the suggestions.
	 */
	protected function getSelectedId(): int {
		return (int) trim((string) $this->getData('otherSubmissionId')) ?: (int) $this->getData('submissionId');
	}

	/**
	 * The chosen submission, if it belongs to this journal.
	 */
	public function getSubmission(): ?Submission {
		$id = $this->getSelectedId();
		$submission = $id > 0 ? Repo::submission()->get($id) : null;
		return $submission && (int) $submission->getData('contextId') === (int) $this->context->getId() ? $submission : null;
	}

	/**
	 * Submissions of the journal by the reviewer: those they submitted (or are a participant of
	 * as an author) and those that list them as an author by email. Newest first.
	 * @return int[]
	 */
	public static function getReviewerSubmissionIds(int $contextId, int $userId): array {
		$asParticipant = DB::table('submissions as s')
			->join('stage_assignments as sa', 'sa.submission_id', '=', 's.submission_id')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->where('s.context_id', $contextId)
			->where('sa.user_id', $userId)
			->where('ug.role_id', Role::ROLE_ID_AUTHOR)
			->pluck('s.submission_id');
		$email = Repo::user()->get($userId, true)?->getEmail();
		$asAuthor = $email ? DB::table('submissions as s')
			->join('publications as p', 'p.submission_id', '=', 's.submission_id')
			->join('authors as a', 'a.publication_id', '=', 'p.publication_id')
			->where('s.context_id', $contextId)
			->whereRaw('LOWER(a.email) = ?', [strtolower($email)])
			->pluck('s.submission_id') : collect();
		$ids = array_unique(array_map('intval', $asParticipant->merge($asAuthor)->all()));
		rsort($ids);
		return $ids;
	}

	/**
	 * Suggestions: submission ID => "#ID Title (status)".
	 */
	protected function getOptions(): array {
		$options = [];
		foreach (self::getReviewerSubmissionIds((int) $this->context->getId(), $this->assignment->getUserId()) as $id) {
			if ($submission = Repo::submission()->get($id)) {
				$options[$id] = self::describe($submission);
			}
		}
		return $options;
	}

	public static function describe(Submission $submission): string {
		$title = $submission->getCurrentPublication()?->getLocalizedFullTitle() ?: __('common.untitled');
		$status = $submission->getData('submissionProgress')
			? __('submissions.incomplete')
			: __($submission->getStatusKey());
		return '#' . $submission->getId() . ' ' . $title . ' (' . $status . ')';
	}

	public function initData() {
		$options = $this->getOptions();
		// One suggestion: preselect it
		$this->setData('submissionId', count($options) === 1 ? array_key_first($options) : '');
	}

	public function readInputData() {
		$this->readUserVars(['submissionId', 'otherSubmissionId']);
	}

	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'objectId' => $this->object->getId(),
			'assignmentId' => $this->assignment->getId(),
			'objectDescription' => $this->object->getCitation(),
			'reviewerName' => Repo::user()->get($this->assignment->getUserId(), true)?->getFullName(),
			'options' => $this->getOptions(),
		]);
		return parent::fetch($request, $template, $display);
	}

	public function execute(...$functionArgs) {
		$editor = \APP\core\Application::get()->getRequest()->getUser();
		AssignmentWorkflow::attachByEditor($this->object, $this->assignment, $editor, $this->getSubmission());
		parent::execute(...$functionArgs);
	}
}
