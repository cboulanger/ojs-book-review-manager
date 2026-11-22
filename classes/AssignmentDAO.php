<?php

/**
 * @file plugins/generic/bookReviewManager/classes/AssignmentDAO.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignmentDAO
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Operations for retrieving and modifying Assignment objects.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAO;

class AssignmentDAO extends DAO {

	const TABLE = 'brm_assignments';
	const SETTINGS_TABLE = 'brm_assignment_settings';

	public function newDataObject(): Assignment {
		return new Assignment();
	}

	public function getById(int $assignmentId): ?Assignment {
		$row = DB::table(self::TABLE)->where('assignment_id', $assignmentId)->first();
		return $row ? $this->_fromRow((array) $row) : null;
	}

	/**
	 * All assignments of an object, oldest first.
	 * @param ?string[] $statuses Only assignments with one of these statuses
	 * @return Assignment[]
	 */
	public function getByObjectId(int $objectId, ?array $statuses = null): array {
		$query = DB::table(self::TABLE)->where('object_id', $objectId);
		if ($statuses !== null) {
			$query->whereIn('status', $statuses);
		}
		return $this->toObjects($query->orderBy('date_created')->orderBy('assignment_id')->get());
	}

	/**
	 * Assignments of a journal, grouped by object ID.
	 * @param ?string[] $statuses Only assignments with one of these statuses
	 * @return array objectId => Assignment[]
	 */
	public function getByContextId(int $contextId, ?array $statuses = null): array {
		$query = DB::table(self::TABLE)->where('context_id', $contextId);
		if ($statuses !== null) {
			$query->whereIn('status', $statuses);
		}
		$grouped = [];
		foreach ($this->toObjects($query->orderBy('date_created')->orderBy('assignment_id')->get()) as $assignment) {
			$grouped[$assignment->getObjectId()][] = $assignment;
		}
		return $grouped;
	}

	/**
	 * The current assignment of a user for an object (the newest one).
	 */
	public function getByObjectAndUser(int $objectId, int $userId, ?array $statuses = null): ?Assignment {
		$query = DB::table(self::TABLE)->where('object_id', $objectId)->where('user_id', $userId);
		if ($statuses !== null) {
			$query->whereIn('status', $statuses);
		}
		$row = $query->orderByDesc('assignment_id')->first();
		return $row ? $this->_fromRow((array) $row) : null;
	}

	/**
	 * A user's assignments in a journal, oldest first, without the mirrors of
	 * review essay assignments (the essay's own assignment stands for them).
	 * @param string[] $statuses Only assignments with one of these statuses
	 * @return Assignment[]
	 */
	public function getByUser(int $userId, int $contextId, array $statuses): array {
		return $this->toObjects(DB::table(self::TABLE)
			->where('user_id', $userId)
			->where('context_id', $contextId)
			->whereIn('status', $statuses)
			->whereNotIn('assignment_id', DB::table(self::SETTINGS_TABLE)->where('setting_name', 'bundleAssignmentId')->select('assignment_id'))
			->orderBy('date_created')->orderBy('assignment_id')
			->get());
	}

	/**
	 * The reviewer of an object (accepted, submitted or completed), if any.
	 */
	public function getReviewer(int $objectId): ?Assignment {
		$assignments = $this->getByObjectId($objectId, Assignment::REVIEWER_STATUSES);
		return $assignments ? end($assignments) : null;
	}

	/**
	 * Number of reviews a user has submitted for this journal (shown when choosing among bidders).
	 */
	public function countReviewsByUser(int $userId, int $contextId): int {
		return DB::table(self::TABLE)
			->where('user_id', $userId)
			->where('context_id', $contextId)
			->whereIn('status', [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED])
			->whereNotIn('assignment_id', DB::table(self::SETTINGS_TABLE)->where('setting_name', 'bundleAssignmentId')->select('assignment_id'))
			->count();
	}

	/**
	 * Mirror assignments of a review essay assignment.
	 * @return Assignment[]
	 */
	public function getMirrors(int $bundleAssignmentId): array {
		$ids = DB::table(self::SETTINGS_TABLE)->where('setting_name', 'bundleAssignmentId')
			->where('setting_value', (string) $bundleAssignmentId)->pluck('assignment_id')->all();
		return $ids ? $this->toObjects(DB::table(self::TABLE)->whereIn('assignment_id', $ids)->orderBy('assignment_id')->get()) : [];
	}

	public function insertObject(Assignment $assignment): int {
		$now = Core::getCurrentDate();
		$assignment->setDateCreated($assignment->getDateCreated() ?: $now);
		$assignment->setDateStatus($assignment->getDateStatus() ?: $now);
		$id = DB::table(self::TABLE)->insertGetId([
			'object_id' => $assignment->getObjectId(),
			'context_id' => $assignment->getContextId(),
			'user_id' => $assignment->getUserId(),
			'status' => $assignment->getStatus(),
			'date_created' => $assignment->getDateCreated(),
			'date_status' => $assignment->getDateStatus(),
		], 'assignment_id');
		$assignment->setId($id);
		$this->updateDataObjectSettings(self::SETTINGS_TABLE, $assignment, ['assignment_id' => $id]);
		return $id;
	}

	public function updateObject(Assignment $assignment): void {
		DB::table(self::TABLE)->where('assignment_id', $assignment->getId())->update([
			'object_id' => $assignment->getObjectId(),
			'context_id' => $assignment->getContextId(),
			'user_id' => $assignment->getUserId(),
			'status' => $assignment->getStatus(),
			'date_created' => $assignment->getDateCreated(),
			'date_status' => $assignment->getDateStatus(),
		]);
		$this->updateDataObjectSettings(self::SETTINGS_TABLE, $assignment, ['assignment_id' => $assignment->getId()]);
	}

	/**
	 * Delete all assignments (and their links) of an object, when the object is deleted.
	 */
	public function deleteById(int $assignmentId): void {
		DB::table(self::SETTINGS_TABLE)->where('assignment_id', $assignmentId)->delete();
		DB::table(ActionLink::TABLE)->where('assignment_id', $assignmentId)->delete();
		DB::table(self::TABLE)->where('assignment_id', $assignmentId)->delete();
	}

	public function deleteByObjectId(int $objectId): void {
		$ids = DB::table(self::TABLE)->where('object_id', $objectId)->pluck('assignment_id')->all();
		if ($ids) {
			DB::table(self::SETTINGS_TABLE)->whereIn('assignment_id', $ids)->delete();
			DB::table(ActionLink::TABLE)->whereIn('assignment_id', $ids)->delete();
			DB::table(self::TABLE)->whereIn('assignment_id', $ids)->delete();
		}
	}

	public function getAdditionalFieldNames(): array {
		return array_merge(Reservation::FIELDS, Assignment::FIELDS);
	}

	public function _fromRow(array $row): Assignment {
		$assignment = $this->newDataObject();
		$assignment->setId((int) $row['assignment_id']);
		$assignment->setObjectId($row['object_id']);
		$assignment->setContextId($row['context_id']);
		$assignment->setUserId($row['user_id']);
		$assignment->setStatus($row['status']);
		$assignment->setDateCreated($row['date_created']);
		$assignment->setDateStatus($row['date_status']);
		$this->getDataObjectSettings(self::SETTINGS_TABLE, 'assignment_id', $row['assignment_id'], $assignment);
		return $assignment;
	}

	/**
	 * @return Assignment[]
	 */
	protected function toObjects($rows): array {
		$objects = [];
		foreach ($rows as $row) {
			$objects[] = $this->_fromRow((array) $row);
		}
		return $objects;
	}
}
