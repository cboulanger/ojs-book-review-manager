<?php

/**
 * @file plugins/generic/bookReviewManager/pages/MyBookReviewsHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MyBookReviewsHandler
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Backend page "My book reviews" (dashboard menu): the current user's
 *  reservations, i.e. the reviews they still have to submit. Invitations and
 *  contract offers are answered on the public page.
 */

namespace APP\plugins\generic\bookReviewManager\pages;

use APP\handler\Handler;
use APP\plugins\generic\bookReviewManager\classes\MyReviews;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class MyBookReviewsHandler extends Handler {

	/** Page name used in URLs */
	const PAGE = 'myBookReviews';

	/** @copydoc PKPHandler::_isBackendPage */
	public $_isBackendPage = true;

	/** @var \APP\plugins\generic\bookReviewManager\BookReviewManagerPlugin */
	public $plugin;

	public function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		// Anyone with a role in the journal: the page only lists the user's own reservations
		$this->addRoleAssignment(
			[Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
				Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_READER],
			['index']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	public function index($args, $request) {
		$this->setupTemplate($request);
		$context = $request->getContext();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'pageTitle' => __('plugins.generic.bookReviewManager.myReviews.title'),
			'rows' => MyReviews::rows($context->getId(), $request->getUser()->getId(), MyReviews::DASHBOARD_STATUSES),
			'publicPage' => ObjectsForReviewHandler::PAGE,
			'receiptTemplate' => $this->plugin->getTemplateResource('myBookReviewsReceipt.tpl'),
		]);
		return $templateMgr->display($this->plugin->getTemplateResource('myBookReviews.tpl'));
	}
}
