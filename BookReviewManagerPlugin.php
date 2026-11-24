<?php

/**
 * @file plugins/generic/bookReviewManager/BookReviewManagerPlugin.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BookReviewManagerPlugin
 * @ingroup plugins_generic_bookReviewManager
 * @brief Add bookReviewManager data to the submission metadata and display them on the submission view page.
 *
 */

namespace APP\plugins\generic\bookReviewManager;

use APP\core\Application;
use APP\pages\submission\SubmissionHandler;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\AssignmentDAO;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use APP\plugins\generic\bookReviewManager\classes\MyReviews;

use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewGridHandler;
use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewManagementGridHandler;
use APP\plugins\generic\bookReviewManager\ObjectsForReviewSchemaMigration;
use APP\plugins\generic\bookReviewManager\api\ObjectsForReviewController;
use APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewHandler;
use APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewManagementHandler;
use APP\plugins\generic\bookReviewManager\pages\MyBookReviewsHandler;

use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewNew;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewCancel;

use APP\facades\Repo;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;
use PKP\core\JSONMessage;
use APP\notification\NotificationManager;
use PKP\notification\Notification;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\config\Config;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use APP\plugins\generic\bookReviewManager\classes\ReservationReminder;
use PKP\security\Role;
use Illuminate\Support\Facades\Mail;



class BookReviewManagerPlugin extends GenericPlugin implements HasTaskScheduler {

	/** Navigation menu item type of the public page */
	const NMI_TYPE = 'BOOKREVIEWMANAGER_NMI';

	/** Name of the upstream Objects for Review plugin, which can be installed alongside */
	const UPSTREAM_PLUGIN = 'ObjectsForReviewPlugin';

	/**
	 * @copydoc Plugin::getName()
	 */
	function getName() {
		return 'BookReviewManagerPlugin';
    }

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
    function getDisplayName() {
		return __('plugins.generic.bookReviewManager.displayName');
    }

	/**
	 * @copydoc Plugin::getDescription()
	 */
    function getDescription() {
		return __('plugins.generic.bookReviewManager.description');
    }

	/**
	 * @see PKPPlugin::getInstallEmailTemplatesFile()
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}


	/**
	 * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
	 */
	public function registerSchedules(PKPScheduler $scheduler): void {
		$scheduler
			->addSchedule(new ReservationReminder())
			->daily()
			->name(ReservationReminder::class)
			->withoutOverlapping();
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() 
                ? [
                    new LinkAction(
                        'settings',
                        new AjaxModal(
                            $router->url(
                                $request,
                                null,
                                null,
                                'manage',
                                null,
                                [
                                    'verb'      => 'settings',
                                    'plugin'    => $this->getName(),
                                    'category'  => 'generic'
                                ]
                            ),
                            $this->getDisplayName()
                        ),
                        __('manager.plugins.settings'),
                        null
                    ),
                ] : [],
            parent::getActions($request, $verb)
        );
    }

	/**
	 * @copydoc Plugin::manage()
	 */
    public function manage($args, $request) 
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);
                // Standalone: embedded in the backend settings page instead of a modal
                $templateMgr->assign('standalone', (bool) $request->getUserVar('standalone'));
                $form = new ObjectsForReviewSettingsForm($this, $context->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        (new NotificationManager())->createTrivialNotification(
                            $request->getUser()->getId(),
                            Notification::NOTIFICATION_TYPE_SUCCESS,
                            ['contents' => __('common.changesSaved')]
                        );
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }

                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

	/**
	 * @copydoc Plugin::register()
	 */
    function register($category, $path, $mainContextId = null) {

		$success = parent::register($category, $path, $mainContextId);
		// Also before the plugin is enabled: taking over an installation of the objectsForReview
		// fork moves its settings, including where it is enabled (see ObjectsForReviewSchemaMigration::adoptFork())
		if ($success && Config::getVar('general', 'installed')) {
			$this->upgradeSchema();
		}
		if ($success && $this->getEnabled($mainContextId)) {

			$request = Application::get()->getRequest();
			$context = $request->getContext();

			$objectForReviewDao = new ObjectForReviewDAO();
			DAORegistry::registerDAO('BookReviewManagerObjectDAO', $objectForReviewDao);
			DAORegistry::registerDAO('BookReviewManagerAssignmentDAO', new AssignmentDAO());

            Hook::add('TemplateManager::display', $this->addToSubmissionWizardSteps(...));
            Hook::add('Template::SubmissionWizard::Section', $this->addToSubmissionWizardTemplate(...));
            Hook::add('Template::SubmissionWizard::Section::Review', $this->addToSubmissionWizardReviewTemplate(...));

			// OJS 3.5: the workflow page is Vue-based; the publication tab is added in js/Workflow.js
			// (the Template::Workflow::Publication hook was removed, see pkp/pkp-lib#10766)

			Hook::Add('LoadComponentHandler', array($this, 'setupGridHandler'));
			Hook::Add('TemplateManager::display',array($this, 'addJs'));

			Hook::add('TemplateManager::setupBackendPage', [$this, 'addBackendMenu']);

			// if list display is enabled
			if ($this->getSetting($context->getId(), 'displayAsList')) {
				Hook::Add('Templates::Article::Main', array($this, 'addSubmissionDisplay'));
				Hook::Add('Templates::Catalog::Book::Main', array($this, 'addSubmissionDisplay'));
			}

			// Handler for public objects for review page
			Hook::Add('LoadHandler', array($this, 'loadPageHandler'));
			Hook::Add('NavigationMenus::itemTypes', array($this, 'addMenuItemTypes'));
			Hook::Add('NavigationMenus::displaySettings', array($this, 'setMenuItemDisplayDetails'));
			Hook::Add('SitemapHandler::createJournalSitemap', array($this, 'addSitemapURLs'));

			Hook::add('Mailer::Mailables', [$this, 'addMailable']);
			Hook::add('Schema::get::eventLog', [$this, 'addEventLogProperties']);
			Hook::add('Publication::publish', [$this, 'completeOnPublish']);

			// REST API for imports: /index.php/{journal}/api/v1/bookReviewManager/... (see skills/book-review-manager-api/SKILL.md)
			Hook::add('APIHandler::endpoints::plugin', [$this, 'registerApi']);



		}
		return $success;
	}

	/**
	 * Create or upgrade the plugin's tables (installation only runs the migration once).
	 */
	protected function upgradeSchema(): void {
		$version = (int) $this->getSetting(Application::SITE_CONTEXT_ID, 'schemaVersion');
		// Not installed yet (CLI tools register every plugin on disk): the installation runs the migration
		if ($version >= ObjectsForReviewSchemaMigration::SCHEMA_VERSION || !$this->getCurrentVersion()) {
			return;
		}
		// Stores the new schema version itself (it also runs as the install migration)
		(new ObjectsForReviewSchemaMigration($version))->up();
	}

	/**
	 * Event log data of the plugin's own events that OJS has no property for
	 * (unknown properties are not stored).
	 */
	public function addEventLogProperties(string $hookName, array $params): bool {
		$schema = $params[0];
		// The previous due date of a review (ReservationLog::EVENT_DUE_DATE_CHANGED)
		$schema->properties->previousDueDate = (object) ['type' => 'string', 'validation' => ['nullable']];
		return false;
	}

	/**
	 * @copydoc Plugin::setEnabled()
	 * Create the plugin's user groups (book review editor, publisher contact) when enabled in a journal.
	 */
	public function setEnabled($enabled) {
		parent::setEnabled($enabled);
		$context = Application::get()->getRequest()->getContext();
		if ($enabled && $context) {
			Roles::ensureGroups($this, $context->getId());
		}
	}

	/**
	 * Register the plugin's REST API controller.
	 * Hooked to APIHandler::endpoints::plugin.
	 */
	public function registerApi(string $hookName, \PKP\core\APIRouter $router) {
		$router->registerPluginApiControllers([new ObjectsForReviewController($this)]);
		return false;
	}

	/**
	 * A review was published: complete its assignment and discard the list of bidders.
	 * Hooked to Publication::publish.
	 */
	public function completeOnPublish($hookName, $args) {
		$submission = $args[2];
		$objects = (new ObjectForReviewDAO())->getBySubmissionId($submission->getId());
		while ($object = $objects->next()) {
			// The works of a review essay are completed with the essay (BundleWorkflow::syncMembers)
			if (!$object->isBundleMember()) {
				AssignmentWorkflow::complete($object);
			}
		}
		return false;
	}

	/**
	 * Add the "Book Review Manager" section to the backend's left menu.
	 * Hooked to TemplateManager::setupBackendPage.
	 */
	public function addBackendMenu($hookName, $args) {
		$request = Application::get()->getRequest();
		$router = $request->getRouter();
		if (!$request->getContext() || !$request->getUser() || !$router || !$router->getHandler()) {
			return false;
		}
		$userRoles = (array) $router->getHandler()->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		$contextId = $request->getContext()->getId();
		$this->addMyReviewsMenu($request, $contextId);
		if (!Roles::canManage($this, $contextId, $request->getUser()->getId(), $userRoles)) {
			return false;
		}
		$ops = ['index' => 'available', 'desk' => 'desk', 'assignments' => 'assignments', 'reviewedWorks' => 'reviewedWorks'];
		if (Roles::isManager($userRoles)) {
			$ops['settings'] = 'settings';
		}

		$page = ObjectsForReviewManagementHandler::PAGE;
		$onPage = $request->getRequestedPage() === $page;
		$requestedOp = $request->getRequestedOp() ?: 'index';
		// The invitation page belongs to "Available objects"
		if ($requestedOp === 'invite') {
			$requestedOp = 'index';
		}
		$submenu = [];
		foreach ($ops as $op => $key) {
			$submenu[$key] = [
				'name' => __('plugins.generic.bookReviewManager.menu.' . $key),
				'url' => $router->url($request, null, $page, $op),
				'isCurrent' => $onPage && $requestedOp === $op,
			];
		}
		$submenu['publicPage'] = [
			'name' => __('plugins.generic.bookReviewManager.menu.publicPage'),
			'url' => $router->url($request, null, ObjectsForReviewHandler::PAGE),
		];
		$entry = [
			'name' => __('plugins.generic.bookReviewManager.managementLink'),
			'icon' => 'Book',
			'submenu' => $submenu,
		];

		// Place the section before "Settings" (or at the end)
		$templateMgr = TemplateManager::getManager($request);
		$menu = (array) $templateMgr->getState('menu');
		$pos = array_search('settings', array_keys($menu), true);
		$menu = $pos === false
			? $menu + ['bookReviewManager' => $entry]
			: array_slice($menu, 0, $pos, true) + ['bookReviewManager' => $entry] + array_slice($menu, $pos, null, true);
		$templateMgr->setState(['menu' => $menu]);
		return false;
	}

	/**
	 * Add "My book reviews" to the backend's left menu, after "My Submissions",
	 * for users with open reservations (reviews still to be submitted).
	 */
	protected function addMyReviewsMenu($request, int $contextId): void {
		$count = MyReviews::countReservations($contextId, $request->getUser()->getId());
		$onPage = $request->getRequestedPage() === MyBookReviewsHandler::PAGE;
		if (!$count && !$onPage) {
			return;
		}
		$entry = [
			'name' => __('plugins.generic.bookReviewManager.myReviews.title') . ($count ? ' (' . $count . ')' : ''),
			'url' => $request->getRouter()->url($request, null, MyBookReviewsHandler::PAGE),
			'isCurrent' => $onPage,
			'icon' => 'Book',
		];
		$templateMgr = TemplateManager::getManager($request);
		$menu = (array) $templateMgr->getState('menu');
		$keys = array_keys($menu);
		$pos = array_search('mySubmissions', $keys, true);
		$pos = $pos === false ? array_search('submit', $keys, true) : $pos + 1;
		$menu = $pos === false
			? $menu + ['bookReviewManagerMyReviews' => $entry]
			: array_slice($menu, 0, $pos, true) + ['bookReviewManagerMyReviews' => $entry] + array_slice($menu, $pos, null, true);
		$templateMgr->setState(['menu' => $menu]);
	}

	/**
	 * Permit requests to the ObjectsForReview grid handler
	 * @param $hookName string The name of the hook being invoked
	 * @param $args array The parameters to the invoked hook
	 */
	function setupGridHandler($hookName, $params) {
		$component =& $params[0];
		$componentInstance =& $params[2];

		if ($component === 'plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler') {
			$componentInstance = new controllers\grid\ObjectsForReviewGridHandler($this);
			return true;
		}

		if ($component === 'plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler') {
			$componentInstance = new controllers\grid\ObjectsForReviewManagementGridHandler($this);
			return true;
		}

		if ($component === controllers\ReservationActionsHandler::COMPONENT) {
			$componentInstance = new controllers\ReservationActionsHandler($this);
			return true;
		}
		
		return false;
	}

    /**
     * Inject a object for review section into the submission wizard steps UI.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardSteps($hookName, $params) {
        $request = Application::get()->getRequest();

        if ($request->getRequestedPage() !== 'submission') {
            return;
        }

        if ($request->getRequestedOp() === 'saved') {
            return;
        }

        $submission = $request
            ->getRouter()
            ->getHandler()
            ->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

        if (!$submission || !$submission->getData('submissionProgress')) {
            return;
        }

        /** @var ObjectForReviewDAO $objectForReviewDao */
        $objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
        $objectForReviewResult = $objectForReviewDao->getBySubmissionId($submission->getId());

        $bookReviewManager = [];
        while ($objectForReview = $objectForReviewResult->next()) {
            // A review essay is one entry (its citation lists the works)
            if (!$objectForReview->isBundleMember()) {
                $bookReviewManager[] = $this->getObjectForReviewData($objectForReview);
            }
        }

        /** @var TemplateManager $templateMgr */
        $templateMgr = $params[0];

        $steps = $templateMgr->getState('steps');
        $steps = array_map(function($step) {
            if ($step['id'] === 'editors') {
                $step['sections'][] = [
                    'id' => 'bookReviewManager',
                    'name' => __('plugins.generic.bookReviewManager.submissionWizard.name'),
                    'type' => SubmissionHandler::SECTION_TYPE_TEMPLATE,
                ];
            }
            return $step;
        }, $steps);

        $templateMgr->setState([
            'bookReviewManager' => $bookReviewManager,
            'steps' => $steps,
        ]);

        $templateMgr->addJavaScript(
            'plugin-objectForReview-submission-wizard',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/SubmissionWizard.js',
            [
                'contexts' => 'backend',
                'priority' => TemplateManager::STYLE_SEQUENCE_LATE,
            ]
        );

        return false;
    }

    /**
     * Render the object for review section content inside the submission wizard.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardTemplate($hookName, $params) {
        $smarty = $params[1];
        $output =& $params[2];

        $output .= sprintf(
            '<template v-else-if="section.id === \'bookReviewManager\'">%s</template>',
            $smarty->fetch($this->getTemplateResource('objectsForReviewGrid.tpl'))
        );

        return false;
    }

    /**
     * Format object for review entity as an associative array for frontend or API use.
     *
     * @param ObjectForReview $objectForReview
     *
     * @return array
     */
	public function getObjectForReviewData(ObjectForReview $objectForReview): array
	{
		return [
			'id' => $objectForReview->getId(),
			'identifier' => $objectForReview->getIdentifier(),
			'identifierType' => $objectForReview->getIdentifierType(),
			'resourceType' => $objectForReview->getResourceType(),
			'authors' => $objectForReview->getAuthors(),
			// A review essay has no authors, publisher or year: its citation is the title with the works
			'title' => $objectForReview->isReviewEssay() ? $objectForReview->getCitation() : $objectForReview->getTitle(),
			'publisher' => $objectForReview->getPublisher(),
			'year' => $objectForReview->getYear(),
		];
	}

    /**
     * Add a review panel for the object for review data in the final step of the wizard.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardReviewTemplate($hookName, $params) {
        $submission = $params[0]['submission']; /** @var Submission $submission */
        $step = $params[0]['step']; /** @var string $step */
        $templateMgr = $params[1]; /** @var TemplateManager $templateMgr */
        $output =& $params[2];

        if ($step === 'editors') {
            $output .= $templateMgr->fetch($this->getTemplateResource('reviewObjectsForReview.tpl'));
        }

        return false;
    }

	/**
	* Hook to Templates::Article::Details and Templates::Catalog::Book::Details and list object for review information
	* @param $hookName string
	* @param $params array
	*/
	function addSubmissionDisplay($hookName, $params) {
		$templateMgr = $params[1];
		$output =& $params[2];

		$submission = $templateMgr->getTemplateVars('monograph') ? $templateMgr->getTemplateVars('monograph') : $templateMgr->getTemplateVars('article');

		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$bookReviewManager = $objectForReviewDao->getBySubmissionId($submission->getId());

		if ($bookReviewManager){
			$templateData = array();

			while ($objectForReview = $bookReviewManager->next()) {
				// Only the reviewed works, not the review essay that holds them
				if ($objectForReview->isReviewEssay()) {
					continue;
				}
				$objectId = $objectForReview->getId();
				$templateData[$objectId] = array(
					'identifierType' => $objectForReview->getIdentifierType(),
					'identifier' => $objectForReview->getIdentifier(),
					'identifierUrl' => $objectForReview->getIdentifierUrl(),
					'identifierHtml' => $objectForReview->getIdentifierHtml(),
					'description' => $objectForReview->getCitation()
				);
			}

			if ($templateData){
				$templateMgr->assign('bookReviewManager', $templateData);
				$output .= $templateMgr->fetch($this->getTemplateResource('listReviews.tpl'));
			}

		}

		return false;
	}

	/**
	* Hook to ArticleDAO::_fromRow and MonographDAO::_fromRow and display objectForReview as subtitle
	* @param $hookName string
	* @param $params array
	*/
	function addSubtitleDisplay($hookName, $params) {
		// NOTE Not working in 3.2, need to rewrite this
		$submission =& $params[0];

		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$bookReviewManager = $objectForReviewDao->getBySubmissionId($submission->getId());

		if ($bookReviewManager){
			$objects = array();
			while ($objectForReview = $bookReviewManager->next()) {
				$objects[] = $objectForReview->getCitation();
			}

			if ($objects){
				$publication->setSubtitle(implode(" ▪ ", $objects), $publication->getLocale());
			}
		}

		return false;
	}

	/**
	 * @copydoc Plugin::getInstallMigration()
	 */
	function getInstallMigration() {
		return new ObjectsForReviewSchemaMigration();
	}

	/**
	 * Add custom js for backend and frontend
	 */
	function addJs($hookName, $params) {
		$templateMgr = $params[0];
		$template =& $params[1];
		$request = Application::get()->getRequest();

		$gridHandlerJs = $this->getJavaScriptURL($request, false) . '/ObjectsForReviewGridHandler.js';
		$templateMgr->addJavaScript(
			'ObjectsForReviewGridHandlerJs',
			$gridHandlerJs,
			array('contexts' => 'backend')
		);
		// Configuration and registry extensions for the OJS 3.5 workflow page
		$gridUrl = $request->getDispatcher()->url(
			$request,
			Application::ROUTE_COMPONENT,
			null,
			'plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler',
			'fetchGrid',
			null,
			['submissionId' => '__submissionId__']
		);
		$templateMgr->addJavaScript(
			'ObjectsForReviewWorkflowConfig',
			'window.pkpBookReviewManager = ' . json_encode([
				'gridUrl' => $gridUrl,
				'label' => __('plugins.generic.bookReviewManager.workflow.tabLabel'),
			]) . ';',
			[
				'inline' => true,
				'contexts' => 'backend',
			]
		);
		// The backend side menu ignores link targets: open the "Public page" entry in a new tab
		if ($request->getContext()) {
			$publicUrl = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, ObjectsForReviewHandler::PAGE);
			$templateMgr->addJavaScript(
				'ObjectsForReviewPublicPageLink',
				'document.addEventListener("click", function (e) {'
					. ' var a = e.target.closest && e.target.closest("a[href]");'
					. ' if (!a || a.getAttribute("href") !== ' . json_encode($publicUrl) . ' || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;'
					. ' e.preventDefault(); e.stopPropagation();'
					. ' window.open(a.href, "_blank", "noopener");'
					. '}, true);',
				[
					'inline' => true,
					'contexts' => 'backend',
				]
			);
		}
		$templateMgr->addJavaScript(
			'ObjectsForReviewWorkflowJs',
			$this->getJavaScriptURL($request, false) . '/Workflow.js',
			[
				'contexts' => 'backend',
				'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
			]
		);
		// Sorting of card lists (templates/cardListHeader.tpl)
		$templateMgr->addJavaScript(
			'ObjectsForReviewCardListJs',
			$this->getJavaScriptURL($request, false) . '/CardList.js',
			[
				'contexts' => 'backend',
				'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
			]
		);
		$templateMgr->addStylesheet(
			'ObjectsForReviewGridHandlerStyles',
			'.bookReviewManagerWorkflowGrid { margin: 1rem; } .orIdLookup .orIdLookupButton, .orIdLookup .orIdLookupStatus { margin-left: 0.5em; }'
				. ' .bookReviewManagerManagement .ofrTable { width: 100%; border-collapse: collapse; }'
				. ' .bookReviewManagerManagement .ofrTable th, .bookReviewManagerManagement .ofrTable td { text-align: left; vertical-align: top; padding: 0.75rem 1rem; border-bottom: 1px solid #ddd; }'
				. ' .bookReviewManagerManagement .ofrIdentifier { margin-top: 0.25rem; font-size: 0.875rem; }'
				. ' .bookReviewManagerManagement .ofrFilter { margin-bottom: 1rem; }'
				. ' .bookReviewManagerManagement .ofrNoWrap { white-space: nowrap; }'
				// Long unbroken text (email addresses) may break, and the panel scrolls rather than the table spilling over it (#7)
				. ' .bookReviewManagerManagement { overflow-x: auto; }'
				. ' .bookReviewManagerManagement .ofrTable td { overflow-wrap: anywhere; }'
				. ' .bookReviewManagerManagement .ofrTable td.ofrNoWrap, .bookReviewManagerManagement .ofrTable td.ofrActions, .bookReviewManagerManagement .ofrIdentifier { overflow-wrap: normal; }'
				. ' .bookReviewManagerManagement .ofrActions a { display: block; white-space: nowrap; margin-bottom: 0.25rem; }'
				. ' .bookReviewManagerManagement .ofrDetail { margin-top: 0.25rem; font-size: 0.875rem; }'
				. ' .bookReviewManagerManagement .ofrFilter label { margin: 0 0.5rem 0 1rem; font-weight: bold; }'
				. ' .bookReviewManagerManagement h2 { margin: 2rem 0 0.75rem; font-size: 1.125rem; }'
				. ' .bookReviewManagerManagement h2:first-child { margin-top: 0; }'
				. ' .bookReviewManagerManagement .ofrDeskItem { border: 1px solid #ddd; border-radius: 4px; padding: 1rem; margin-bottom: 1rem; }'
				. ' .bookReviewManagerManagement .ofrDeskState { margin: 0.5rem 0 1rem; }'
				. ' .bookReviewManagerManagement .ofrOfferActions { margin-top: 0.75rem; display: flex; gap: 1.5rem; flex-wrap: wrap; }'
				. ' .bookReviewManagerManagement .ofrClosed { margin-top: 2rem; }'
				. ' .bookReviewManagerManagement .ofrClosed summary { cursor: pointer; font-weight: bold; }'
				. ' .bookReviewManagerManagement .ofrWarning { color: #b00; }'
				. ' .bookReviewManagerManagement .ofrInlineActions { display: flex; gap: 0.75rem; align-items: center; margin-top: 0.25rem; }'
				. ' .bookReviewManagerManagement .ofrInlineForm { display: inline; margin: 0; }'
				. ' .bookReviewManagerManagement .ofrLinkButton { background: none; border: none; padding: 0; color: #006798; cursor: pointer; font: inherit; text-decoration: underline; }'
				. ' .bookReviewManagerManagement .ofrDragHandle { cursor: move; color: #888; letter-spacing: -0.2em; margin-right: 0.25rem; }'
				. ' .bookReviewManagerManagement tr.ofrDragging { opacity: 0.4; }'
				. ' .bookReviewManagerManagement .ofrInviteHistory { margin-left: 1rem; }'
				. ' .bookReviewManagerManagement .ofrRound { border-left: 4px solid #006798; background: #f3f8fb; padding: 0.75rem 1rem; margin: 1rem 0; }'
				. ' .bookReviewManagerManagement .ofrRound p { margin: 0.25rem 0; }'
				. ' .bookReviewManagerManagement .ofrNewInvitee .ofrField { margin-bottom: 0.75rem; }'
				. ' .bookReviewManagerManagement .ofrNewInvitee label { display: block; font-weight: bold; }'
				. ' .bookReviewManagerManagement .ofrNewInvitee input { width: 100%; max-width: 30rem; }'
				. ' .bookReviewManagerManagement input[type=text], .bookReviewManagerManagement input[type=email], .bookReviewManagerManagement input[type=search] { border: 1px solid #bbb; border-radius: 3px; padding: 0.4rem 0.5rem; font: inherit; }'
				// Card lists (templates/cardListHeader.tpl, card.tpl): rows whose parts can be long (#8)
				. ' .bookReviewManagerManagement .ofrCardListHeader { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid #ddd; }'
				. ' .bookReviewManagerManagement .ofrCardListHeaderLabel { font-weight: bold; margin-right: 0.25rem; }'
				. ' .bookReviewManagerManagement .ofrCardSort { background: none; border: 1px solid transparent; border-radius: 3px; padding: 0.25rem 0.5rem; font: inherit; color: #006798; cursor: pointer; }'
				. ' .bookReviewManagerManagement .ofrCardSort:hover, .bookReviewManagerManagement .ofrCardSort:focus-visible { border-color: #bbb; }'
				. ' .bookReviewManagerManagement .ofrCardSort[aria-sort] { border-color: #006798; font-weight: bold; }'
				. ' .bookReviewManagerManagement .ofrCardSort[aria-sort=ascending]::after { content: " \\25B2"; font-size: 0.7em; }'
				. ' .bookReviewManagerManagement .ofrCardSort[aria-sort=descending]::after { content: " \\25BC"; font-size: 0.7em; }'
				. ' .bookReviewManagerManagement .ofrCard { display: flex; flex-wrap: wrap; gap: 0.75rem 2rem; padding: 1rem 0; border-bottom: 1px solid #ddd; }'
				. ' .bookReviewManagerManagement .ofrCardMain { flex: 1 1 28rem; min-width: 0; overflow-wrap: anywhere; }'
				. ' .bookReviewManagerManagement .ofrCardActions { flex: 0 0 auto; }'
				. ' .bookReviewManagerManagement .ofrCardTitle { font-weight: 600; } .bookReviewManagerManagement .ofrCardTitle .ofrIdentifier, .bookReviewManagerManagement .ofrCardTitle .ofrDetail, .bookReviewManagerManagement .ofrCardTitle .ofrBadge { font-weight: normal; }'
				. ' .bookReviewManagerManagement .ofrCardFacts { display: flex; flex-wrap: wrap; gap: 0.5rem 2rem; margin-top: 0.75rem; }'
				. ' .bookReviewManagerManagement .ofrFact { max-width: 100%; }'
				. ' .bookReviewManagerManagement .ofrFactLabel { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #666; }'
				. ' .bookReviewManagerManagement .ofrCardDetails { margin-top: 0.75rem; }'
				. ' .bookReviewManagerManagement .ofrCardWorks { margin: 0.25rem 0 0; padding-left: 1.25rem; }'
				. ' .bookReviewManagerManagement .ofrCardWorks li { margin-bottom: 0.25rem; }'
				. ' .bookReviewManagerManagement .ofrCardWorks .ofrInlineActions { display: inline-flex; margin: 0 0 0 0.5rem; }'
				. ' .ofrBadge { display: inline-block; margin-left: 0.25rem; padding: 0 0.4rem; border: 1px solid #888; border-radius: 3px; font-size: 0.75rem; line-height: 1.4; color: #555; vertical-align: middle; }'
				. ' .ofrEssayMember { display: inline-block; padding-left: 1.5rem; }'
				. ' .ofrEssayWorkChoice { list-style: none; padding: 0; } .ofrEssayWorkChoice li { margin-bottom: 0.5rem; } .ofrEssayCopyPreference { margin: 0.25rem 0 0 1.5rem; }'
				. ' .ofrReturnTarget { display: block; margin: 0.5rem 0; } .ofrReturnTarget .ofrDetail { display: block; margin-left: 1.5rem; font-size: 0.875rem; }',
			[
				'inline' => true,
				'contexts' => 'backend',
			]
		);

		if (strpos($template, 'frontend/pages/forReview.tpl')) {
			$tablesortJs = $this->getJavaScriptURL($request, false) . '/tablesort/src/tablesort.js';
			$templateMgr->addJavaScript(
				'TableSortJs',
				$tablesortJs,
				array('contexts' => 'frontend')
			);
			$tablesortCss = $request->getBaseUrl() . '/plugins/generic/bookReviewManager/style.css';
			$templateMgr->addStyleSheet('tablesortCss', $tablesortCss);
		}

		return false;
	}

	/**
	 * Get the JavaScript URL for this plugin.
	 */
	function getJavaScriptURL() {
		$request = Application::get()->getRequest();
		return $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js';
	}

	/**
	 * Load the handler to deal with browse by section page requests
	 *
	 * @param $hookName string `LoadHandler`
	 * @param $args array [
	 * 		@option string page
	 * 		@option string op
	 * 		@option string sourceFile
	 * ]
	 * @return bool
	 */
	public function loadPageHandler($hookName, $args) {
        $page = $args[0];
        if ($this->getEnabled() && $this->isLegacyPage($page)) {
            // Old URLs (e.g. one-click links in emails sent before the rename): continue at the new page
            $request = Application::get()->getRequest();
            $newPage = $page === ObjectsForReviewManagementHandler::LEGACY_PAGE ? ObjectsForReviewManagementHandler::PAGE : ObjectsForReviewHandler::PAGE;
            $request->redirect(null, $newPage, $request->getRequestedOp() ?: null, $request->getRequestedArgs(), $request->getQueryArray());
        }
        if ($this->getEnabled() && $page === ObjectsForReviewHandler::PAGE) {
			$handler =& $args[3];
            $handler = new ObjectsForReviewHandler($this);
            return true;
        }
        if ($this->getEnabled() && $page === MyBookReviewsHandler::PAGE) {
			$handler =& $args[3];
            $handler = new MyBookReviewsHandler($this);
            return true;
        }
        if ($this->getEnabled() && $page === ObjectsForReviewManagementHandler::PAGE) {
			$handler =& $args[3];
            $handler = new ObjectsForReviewManagementHandler($this);
            return true;
        }
        return false;
	}

	/**
	 * Is this a page path used before the plugin was renamed? The old public page path
	 * belongs to the upstream Objects for Review plugin when that is enabled.
	 */
	protected function isLegacyPage(?string $page): bool {
		if ($page === ObjectsForReviewManagementHandler::LEGACY_PAGE) {
			return true;
		}
		if ($page !== ObjectsForReviewHandler::LEGACY_PAGE) {
			return false;
		}
		$upstream = PluginRegistry::getPlugin('generic', self::UPSTREAM_PLUGIN);
		return !($upstream && $upstream->getEnabled());
	}

	/**
	 * Add Navigation Menu Item types for linking to objects for review page
	 *
	 * @param $hookName string
	 * @param $args array [
	 *		@option array Existing menu item types
	 * ]
	 */
	public function addMenuItemTypes($hookName, $args) {
		$types =& $args[0];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextId = $context ? $context->getId() : Application::CONTEXT_ID_NONE;
		$types[self::NMI_TYPE] = array(
			'title' => __('plugins.generic.bookReviewManager.navMenuItem'),
			'description' => __('plugins.generic.bookReviewManager.navMenuItem.description'),
		);
	}

	/**
	 * Set the display details for the custom menu item types
	 *
	 * @param $hookName string
	 * @param $args array [
	 *		@option NavigationMenuItem
	 * ]
	 */
	public function setMenuItemDisplayDetails($hookName, $args) {
		$navigationMenuItem =& $args[0];
		if ($navigationMenuItem->getType() == self::NMI_TYPE) {
			$request = Application::get()->getRequest();
			$context = $request->getContext();
			if ($context){
				$dispatcher = $request->getDispatcher();
				$navigationMenuItem->setUrl($dispatcher->url(
					$request,
					Application::ROUTE_PAGE,
					null,
					ObjectsForReviewHandler::PAGE
				));
			}
		}
	}

	/**
	 * Add the objects for review page URL to the sitemap
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return boolean
	 */
	function addSitemapURLs($hookName, $args) {
		$doc = $args[0];
		$rootNode = $doc->documentElement;
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if ($context) {
			// Create and append sitemap XML "url" element
			$url = $doc->createElement('url');
			$url->appendChild($doc->createElement('loc', htmlspecialchars($request->url($context->getPath(), ObjectsForReviewHandler::PAGE), ENT_COMPAT, 'UTF-8')));
			$rootNode->appendChild($url);
		}
		return false;
	}

    /**
     * Add mailable to the list of mailables in the application
     */
    public function addMailable(string $hookName, array $args): void
    {
        $args[0]->push(ObjectsForReviewNew::class);
		$args[0]->push(ObjectsForReviewCancel::class);
		foreach (ReservationMailer::MAILABLES as $mailableClass) {
			if (!$args[0]->contains($mailableClass)) {
				$args[0]->push($mailableClass);
			}
		}
    }
	
	/**
	 * Send mail to editor when object is reserved or cancelled
	 *
	 * Recipient: the responsible book review editor of the offer, otherwise the
	 * notification address in the settings, otherwise the journal contact.
	 *
	 * @param User $user The reviewer
	 * @param ObjectForReview $object
	 * @param $template Send either the reserve or cancel mail
	 */
	public function notifyEditor($user, $object, $mailTemplate, $reservationDetails = '') {
		$objectDescription = $object->getCitation();

		// The offer's journal (also outside a web request, e.g. in scheduled tasks)
		$context = Application::get()->getRequest()->getContext()
			?? Application::getContextDAO()->getById($object->getContextId());
		$contextId = $context->getId();

        $emailParams = [
            'objectDescription' => htmlspecialchars($objectDescription),
            'senderUsername' => htmlspecialchars($user->getFullName()),
            'senderEmail' => htmlspecialchars($user->getEmail()),
            // variable names used by the original en/de templates
            'userName' => htmlspecialchars($user->getFullName()),
            'userEmail' => htmlspecialchars($user->getEmail()),
            // already escaped HTML (see Reservation::getEmailDetails())
            'reservationDetails' => $reservationDetails,
        ];

		if ($mailTemplate == 'BRM_NEW_RESERVATION'){
			$mailable = new ObjectsForReviewNew($context, $emailParams);
		} elseif ($mailTemplate == 'BRM_CANCEL_RESERVATION'){
			$mailable = new ObjectsForReviewCancel($context, $emailParams);
		} 

		$editor = $object->getResponsibleEditorId() ? Repo::user()->get($object->getResponsibleEditorId()) : null;
		if ($editor) {
			$mailTo = $editor->getEmail();
		} elseif ($this->getSetting($contextId, 'ofrNotifyEmail')){
			$mailTo = $this->getSetting($contextId, 'ofrNotifyEmail');
		} else{
			$mailTo = $context->getData('contactEmail');
		}

		$template = Repo::emailTemplate()->getByKey($context->getId(), $mailable::getEmailTemplateKey());
		$locale = $context->getPrimaryLocale();
		$body = (string) $template->getLocalizedData('body', $locale);
		// Templates installed before the reservation details existed have no placeholder for them
		if ($reservationDetails !== '' && strpos($body, '{$reservationDetails}') === false) {
			$body .= '<br />{$reservationDetails}';
		}
		$mailable
			->sender($user)
			->to($mailTo)
			->subject($template->getLocalizedData('subject', $locale))
			->body($body);

		ReservationMailer::dispatch($mailable, $contextId);
	}
}
?>
