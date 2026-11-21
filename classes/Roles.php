<?php

/**
 * @file plugins/generic/bookReviewManager/classes/Roles.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Roles
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief The plugin's two user groups and who may use the backend pages.
 *
 *  - Book review editor: base role Section editor, so the group can also be
 *    assigned to a (by convention "Book reviews") section and handle review
 *    submissions. Members may use the plugin's backend pages, except settings.
 *  - Publisher contact: base role Reader, no self-registration, not on the
 *    masthead. Publisher contacts never log in; they act through email links.
 *
 *  The groups are created per journal when needed; their IDs are kept in plugin
 *  settings. Managers can rename them under Users & Roles.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\security\Role;
use PKP\userGroup\relationships\UserGroupStage;
use PKP\userGroup\UserGroup;

class Roles {

	const EDITOR = 'bookReviewEditor';
	const PUBLISHER = 'publisherContact';

	/** Plugin setting with the group ID, base role, workflow stages */
	const GROUPS = [
		self::EDITOR => ['ofrEditorGroupId', Role::ROLE_ID_SUB_EDITOR, [WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION]],
		self::PUBLISHER => ['ofrPublisherGroupId', Role::ROLE_ID_READER, []],
	];

	/**
	 * Create the plugin's user groups in a journal if they don't exist (any more).
	 */
	public static function ensureGroups($plugin, int $contextId): void {
		foreach (array_keys(self::GROUPS) as $group) {
			self::getGroupId($plugin, $contextId, $group);
		}
	}

	/**
	 * The ID of one of the plugin's user groups, created if needed.
	 */
	public static function getGroupId($plugin, int $contextId, string $group): int {
		[$settingName, $roleId, $stages] = self::GROUPS[$group];
		$groupId = (int) $plugin->getSetting($contextId, $settingName);
		if ($groupId && Repo::userGroup()->contextHasGroup($contextId, $groupId)) {
			return $groupId;
		}

		$locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
		$names = $abbrevs = [];
		foreach ($locales as $locale) {
			$names[$locale] = __('plugins.generic.bookReviewManager.role.' . $group, [], $locale);
			$abbrevs[$locale] = __('plugins.generic.bookReviewManager.role.' . $group . '.abbrev', [], $locale);
		}
		$userGroup = new UserGroup([
			'roleId' => $roleId,
			'contextId' => $contextId,
			'isDefault' => false,
			'permitSelfRegistration' => false,
			'permitMetadataEdit' => $roleId === Role::ROLE_ID_SUB_EDITOR,
			'permitSettings' => false,
			'masthead' => $roleId === Role::ROLE_ID_SUB_EDITOR,
		]);
		$userGroup->save();
		// Settings are only saved when assigned as properties (as in Repository::installLocale())
		$userGroup->name = $names;
		$userGroup->abbrev = $abbrevs;
		$userGroup->save();
		foreach ($stages as $stageId) {
			UserGroupStage::create(['contextId' => $contextId, 'userGroupId' => $userGroup->id, 'stageId' => $stageId]);
		}
		$plugin->updateSetting($contextId, $settingName, $userGroup->id, 'int');
		return (int) $userGroup->id;
	}

	public static function isBookReviewEditor($plugin, int $contextId, ?int $userId): bool {
		$groupId = (int) $plugin->getSetting($contextId, self::GROUPS[self::EDITOR][0]);
		return $userId && $groupId && Repo::userGroup()->userInGroup($userId, $groupId);
	}

	/**
	 * May the user use the plugin's backend pages (managers and book review editors)?
	 * @param int[] $userRoles Roles of the user in the journal
	 */
	public static function canManage($plugin, int $contextId, ?int $userId, array $userRoles): bool {
		if (array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $userRoles)) {
			return true;
		}
		return in_array(Role::ROLE_ID_SUB_EDITOR, $userRoles) && self::isBookReviewEditor($plugin, $contextId, $userId);
	}

	public static function isManager(array $userRoles): bool {
		return (bool) array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $userRoles);
	}

	/**
	 * Members of one of the plugin's groups.
	 * @return array userId => "Name (email)"
	 */
	public static function getMembers($plugin, int $contextId, string $group): array {
		$groupId = self::getGroupId($plugin, $contextId, $group);
		$users = Repo::user()->getCollector()
			->filterByContextIds([$contextId])
			->filterByUserGroupIds([$groupId])
			->getMany();
		$members = [];
		foreach ($users as $user) {
			$members[$user->getId()] = $user->getFullName() . ' (' . $user->getEmail() . ')';
		}
		asort($members);
		return $members;
	}
}
