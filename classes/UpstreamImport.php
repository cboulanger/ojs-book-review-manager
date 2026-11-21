<?php

/**
 * @file plugins/generic/bookReviewManager/classes/UpstreamImport.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UpstreamImport
 * @brief Copy the data of the upstream Objects for Review plugin (ajnyga/objectsForReview)
 *  into this plugin.
 *
 * The upstream tables and settings are only read, so both plugins can be used side by side
 * while a journal switches over. Objects that were imported before are skipped (they keep
 * the upstream object ID in the "importedObjectId" setting), so the import can be repeated.
 * Run it with tools/importObjectsForReview.php.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\plugins\generic\bookReviewManager\ObjectsForReviewSchemaMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpstreamImport {

	/** Tables and plugin name of the upstream plugin */
	const TABLE = 'objects_for_review';
	const SETTINGS_TABLE = 'objects_for_review_settings';
	const PLUGIN_NAME = 'objectsforreviewplugin';

	/** Upstream plugin settings that mean the same here */
	const PLUGIN_SETTINGS = ['displayAsSubtitle', 'displayAsList', 'onlyReserved', 'ofrNotifyEmail', 'ofrInstructions'];

	/** Upstream email templates => templates of this plugin (which know the same variables) */
	const EMAIL_KEYS = ['OFR_NEW_RESERVATION' => 'BRM_NEW_RESERVATION', 'OFR_CANCEL_RESERVATION' => 'BRM_CANCEL_RESERVATION'];

	/** Object setting with the upstream object ID */
	const IMPORTED_SETTING = 'importedObjectId';

	/**
	 * Is there upstream data to import? (Tables of the objectsForReview fork have the same
	 * names; they are taken over when the plugin is installed, see ObjectsForReviewSchemaMigration::adoptFork().)
	 */
	public static function available(): bool {
		return Schema::hasTable(self::TABLE) && Schema::hasTable(self::SETTINGS_TABLE)
			&& !Schema::hasTable('objects_for_review_assignments');
	}

	/**
	 * Import the objects of the given journals (all journals: null).
	 * @param ?int[] $contextIds
	 * @param bool $dryRun Only count what would be imported
	 * @return array{objects: int, skipped: int, settings: int, templates: int}
	 *  templates: journals' customised email templates
	 */
	public static function run(?array $contextIds, bool $dryRun = false): array {
		$result = ['objects' => 0, 'skipped' => 0, 'settings' => 0, 'templates' => 0];

		$imported = DB::table('brm_object_settings')
			->where('setting_name', self::IMPORTED_SETTING)
			->pluck('setting_value')
			->map(fn ($id) => (int) $id)
			->all();
		$objects = DB::table(self::TABLE)
			->when($contextIds !== null, fn ($q) => $q->whereIn('context_id', $contextIds))
			->orderBy('object_id')
			->get();

		$newIds = [];
		foreach ($objects as $object) {
			if (in_array((int) $object->object_id, $imported, true)) {
				$result['skipped']++;
				continue;
			}
			$result['objects']++;
			if ($dryRun) {
				continue;
			}
			$newIds[] = DB::transaction(function () use ($object) {
				$id = DB::table('brm_objects')->insertGetId([
					'submission_id' => $object->submission_id,
					'context_id' => $object->context_id,
					'user_id' => $object->user_id,
					'identifier' => $object->identifier,
					'identifier_type' => $object->identifier_type,
					'resource_type' => $object->resource_type,
					'creator' => $object->creator,
					'date_created' => $object->date_created,
				], 'object_id');
				$settings = DB::table(self::SETTINGS_TABLE)->where('object_id', $object->object_id)->get();
				foreach ($settings as $setting) {
					DB::table('brm_object_settings')->insert([
						'object_id' => $id,
						'locale' => $setting->locale,
						'setting_name' => $setting->setting_name,
						'setting_value' => $setting->setting_value,
						'setting_type' => $setting->setting_type,
					]);
				}
				DB::table('brm_object_settings')->insert([
					'object_id' => $id,
					'locale' => '',
					'setting_name' => self::IMPORTED_SETTING,
					'setting_value' => (string) $object->object_id,
					'setting_type' => 'int',
				]);
				return $id;
			});
		}
		// Reservations become assignments, titles plain text (as for old installations of this plugin)
		(new ObjectsForReviewSchemaMigration())->convertImported($newIds);

		// Journal settings that are not set here yet
		$settings = DB::table('plugin_settings')
			->where('plugin_name', self::PLUGIN_NAME)
			->whereIn('setting_name', self::PLUGIN_SETTINGS)
			->when($contextIds !== null, fn ($q) => $q->whereIn('context_id', $contextIds))
			->get();
		foreach ($settings as $setting) {
			$exists = DB::table('plugin_settings')
				->where('plugin_name', ObjectsForReviewSchemaMigration::PLUGIN_NAME)
				->where('context_id', $setting->context_id)
				->where('setting_name', $setting->setting_name)
				->exists();
			if ($exists) {
				continue;
			}
			$result['settings']++;
			if (!$dryRun) {
				DB::table('plugin_settings')->insert([
					'plugin_name' => ObjectsForReviewSchemaMigration::PLUGIN_NAME,
					'context_id' => $setting->context_id,
					'setting_name' => $setting->setting_name,
					'setting_value' => $setting->setting_value,
					'setting_type' => $setting->setting_type,
				]);
			}
		}

		// Journals' customised email templates, unless the journal customised the template here already
		$templates = DB::table('email_templates')
			->whereIn('email_key', array_keys(self::EMAIL_KEYS))
			->when($contextIds !== null, fn ($q) => $q->whereIn('context_id', $contextIds))
			->get();
		foreach ($templates as $template) {
			$key = self::EMAIL_KEYS[$template->email_key];
			if (DB::table('email_templates')->where('context_id', $template->context_id)->where('email_key', $key)->exists()) {
				continue;
			}
			$result['templates']++;
			if ($dryRun) {
				continue;
			}
			DB::transaction(function () use ($template, $key) {
				$id = DB::table('email_templates')->insertGetId([
					'email_key' => $key,
					'context_id' => $template->context_id,
					'alternate_to' => isset(self::EMAIL_KEYS[$template->alternate_to ?? '']) ? self::EMAIL_KEYS[$template->alternate_to] : $template->alternate_to,
				], 'email_id');
				foreach (DB::table('email_templates_settings')->where('email_id', $template->email_id)->get() as $setting) {
					DB::table('email_templates_settings')->insert([
						'email_id' => $id,
						'locale' => $setting->locale,
						'setting_name' => $setting->setting_name,
						'setting_value' => $setting->setting_value,
					]);
				}
			});
		}

		return $result;
	}
}
