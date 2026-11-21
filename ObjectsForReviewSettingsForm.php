<?php

/**
 * @file plugins/generic/bookReviewManager/ObjectsForReviewSettingsForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewSettingsForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Form for journal managers to modify Book Review Manager plugin settings
 */

namespace APP\plugins\generic\bookReviewManager;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use APP\plugins\generic\bookReviewManager\classes\Roles;


class ObjectsForReviewSettingsForm extends \PKP\form\Form {

	/** @var int */
	var $_journalId;

	/** @var object */
	var $_plugin;

	/**
	 * Constructor
	 * @param $plugin BookReviewManagerPlugin
	 * @param $journalId int
	 */
	function __construct($plugin, $journalId) {
		$this->_journalId = $journalId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrDueMonthsMax', 'required',
			'plugins.generic.bookReviewManager.settings.dueMonths.invalid',
			fn ($max) => ctype_digit((string) $max) && $max >= 1 && $max <= Reservation::MAX_DUE_MONTHS_LIMIT));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrDueMonthsDefault', 'required',
			'plugins.generic.bookReviewManager.settings.dueMonths.invalid',
			fn ($default) => ctype_digit((string) $default) && $default >= 1 && $default <= (int) $this->getData('ofrDueMonthsMax')));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrReminderDays', 'required',
			'plugins.generic.bookReviewManager.settings.reminderDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days <= Reservation::MAX_REMINDER_DAYS));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrContractDays', 'required',
			'plugins.generic.bookReviewManager.settings.contractDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days >= 1 && $days <= AssignmentWorkflow::MAX_CONTRACT_DAYS));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrContractReminderDays', 'required',
			'plugins.generic.bookReviewManager.settings.contractDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days < (int) $this->getData('ofrContractDays')));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrPublisherReminderDays', 'required',
			'plugins.generic.bookReviewManager.settings.publisherReminderDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days <= \APP\plugins\generic\bookReviewManager\classes\CopyWorkflow::MAX_PUBLISHER_REMINDER_DAYS));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrInvitationDays', 'required',
			'plugins.generic.bookReviewManager.settings.invitationDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days >= 1 && $days <= \APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow::MAX_INVITATION_DAYS));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrInvitationReminderDays', 'required',
			'plugins.generic.bookReviewManager.settings.invitationDays.reminderInvalid',
			fn ($days) => ctype_digit((string) $days) && ((int) $days === 0 || $days < (int) $this->getData('ofrInvitationDays'))));
		$this->addCheck(new \PKP\form\validation\FormValidatorInSet($this, 'ofrDefaultMode', 'required',
			'plugins.generic.bookReviewManager.offer.modeRequired', ObjectForReview::MODES));
		$this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
		$this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$this->_data = array(
			'displayAsSubtitle' => $this->_plugin->getSetting($this->_journalId, 'displayAsSubtitle'),
			'displayAsList' => $this->_plugin->getSetting($this->_journalId, 'displayAsList'),
			'onlyReserved' => $this->_plugin->getSetting($this->_journalId, 'onlyReserved'),
			'ofrNotifyEmail' => $this->_plugin->getSetting($this->_journalId, 'ofrNotifyEmail'),
			'ofrInstructions' => $this->_plugin->getSetting($this->_journalId, 'ofrInstructions'),
			'ofrContract' => $this->_plugin->getSetting($this->_journalId, 'ofrContract'),
		);
		[$this->_data['ofrDueMonthsDefault'], $this->_data['ofrDueMonthsMax']] = Reservation::getDueMonths($this->_plugin, $this->_journalId);
		$this->_data['ofrReminderDays'] = Reservation::getReminderDays($this->_plugin, $this->_journalId);
		[$this->_data['ofrContractDays'], $this->_data['ofrContractReminderDays']] = AssignmentWorkflow::getContractDays($this->_plugin, $this->_journalId);
		$this->_data['ofrPublisherReminderDays'] = \APP\plugins\generic\bookReviewManager\classes\CopyWorkflow::getPublisherReminderDays($this->_plugin, $this->_journalId);
		$this->_data['ofrInvitationDays'] = \APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow::getInvitationDays($this->_plugin, $this->_journalId);
		$this->_data['ofrInvitationReminderDays'] = \APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow::getReminderDays($this->_plugin, $this->_journalId);
		$this->_data['ofrDefaultMode'] = $this->_plugin->getSetting($this->_journalId, 'ofrDefaultMode') ?: ObjectForReview::MODE_DIRECT;
		$this->_data['ofrDefaultEditorId'] = $this->_plugin->getSetting($this->_journalId, 'ofrDefaultEditorId');
		// Prefill the contract with the default text
		if (empty(array_filter((array) $this->_data['ofrContract']))) {
			$contract = [];
			foreach ($context->getSupportedFormLocales() as $locale) {
				$contract[$locale] = __('plugins.generic.bookReviewManager.contract.default', [], $locale);
			}
			$this->_data['ofrContract'] = $contract;
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('displayAsSubtitle', 'displayAsList', 'onlyReserved', 'ofrNotifyEmail', 'ofrInstructions', 'ofrContract', 'ofrDueMonthsDefault', 'ofrDueMonthsMax', 'ofrReminderDays',
			'ofrContractDays', 'ofrContractReminderDays', 'ofrDefaultMode', 'ofrDefaultEditorId', 'ofrPublisherReminderDays', 'ofrInvitationDays', 'ofrInvitationReminderDays'));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		$templateMgr->assign('assignmentModes', [
			ObjectForReview::MODE_DIRECT => __('plugins.generic.bookReviewManager.offer.mode.direct'),
			ObjectForReview::MODE_BIDDING => __('plugins.generic.bookReviewManager.offer.mode.bidding'),
		]);
		$templateMgr->assign('bookReviewEditors', ['' => __('plugins.generic.bookReviewManager.settings.defaultEditor.none')]
			+ Roles::getMembers($this->_plugin, (int) $this->_journalId, Roles::EDITOR));
		return parent::fetch($request, $template, $display);
	}

	/**
	 * Save settings.
	 */
	function execute(...$functionArgs) {
		$request = Application::get()->getRequest();
		$router = $request->getRouter();
		$context = $router->getContext($request);

		$this->_plugin->updateSetting($this->_journalId, 'displayAsSubtitle', $this->getData('displayAsSubtitle'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'displayAsList', $this->getData('displayAsList'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'onlyReserved', $this->getData('onlyReserved'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'ofrNotifyEmail', $this->getData('ofrNotifyEmail'), 'string');
		$this->_plugin->updateSetting($this->_journalId, 'ofrInstructions', $this->getData('ofrInstructions'));
		$this->_plugin->updateSetting($this->_journalId, 'ofrContract', $this->getData('ofrContract'));
		$this->_plugin->updateSetting($this->_journalId, 'ofrDueMonthsDefault', (int) $this->getData('ofrDueMonthsDefault'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrDueMonthsMax', (int) $this->getData('ofrDueMonthsMax'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrReminderDays', (int) $this->getData('ofrReminderDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrContractDays', (int) $this->getData('ofrContractDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrContractReminderDays', (int) $this->getData('ofrContractReminderDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrDefaultMode', $this->getData('ofrDefaultMode'), 'string');
		$this->_plugin->updateSetting($this->_journalId, 'ofrPublisherReminderDays', (int) $this->getData('ofrPublisherReminderDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrInvitationDays', (int) $this->getData('ofrInvitationDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrInvitationReminderDays', (int) $this->getData('ofrInvitationReminderDays'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrDefaultEditorId', (int) $this->getData('ofrDefaultEditorId') ?: null, 'int');

	}
}

?>
