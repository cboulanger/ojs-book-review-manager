<?php

/**
 * @file ObjectsForReviewSchemaMigration.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewSchemaMigration
 * @brief Describe database table structures.
 *
 * Idempotent: also used to upgrade existing installations (see
 * BookReviewManagerPlugin::upgradeSchema()).
 */

namespace APP\plugins\generic\bookReviewManager;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use APP\facades\Repo;
use PKP\facades\Locale;


class ObjectsForReviewSchemaMigration extends Migration
{
    /** Increase when tables or data migrations are added */
    const SCHEMA_VERSION = 6;

    /** Schema version 6: email templates whose default text changed (the work's link and open access status) */
    const CHANGED_TEMPLATES_6 = ['BRM_INVITATION_RESERVE', 'BRM_INVITATION_BID'];

    /** Reservation fields that were stored with the object before schema version 2 */
    const LEGACY_RESERVATION_FIELDS = ['dateReserved', 'dueDate', 'copyPreference', 'shippingAddress', 'phone', 'copyNote', 'reminderSent'];

    /** Tables of the objectsForReview fork => tables of this plugin */
    const FORK_TABLES = [
        'objects_for_review' => 'brm_objects',
        'objects_for_review_settings' => 'brm_object_settings',
        'objects_for_review_assignments' => 'brm_assignments',
        'objects_for_review_assignment_settings' => 'brm_assignment_settings',
        'objects_for_review_links' => 'brm_links',
    ];

    /** Index names of the fork's tables => index names of this plugin (index names are global in PostgreSQL) */
    const FORK_INDEXES = [
        'brm_object_settings' => ['objects_for_review_settings_id' => 'brm_object_settings_id', 'objects_for_review_settings_pkey' => 'brm_object_settings_pkey'],
        'brm_assignments' => ['ofr_assignments_object_id' => 'brm_assignments_object_id', 'ofr_assignments_user_id' => 'brm_assignments_user_id', 'ofr_assignments_context_status' => 'brm_assignments_context_status'],
        'brm_assignment_settings' => ['ofr_assignment_settings_id' => 'brm_assignment_settings_id', 'ofr_assignment_settings_pkey' => 'brm_assignment_settings_pkey'],
        'brm_links' => ['ofr_links_key_hash' => 'brm_links_key_hash', 'ofr_links_assignment_id' => 'brm_links_assignment_id'],
    ];

    /** Plugin name (plugin_settings) and product (versions) of the fork */
    const FORK_PLUGIN_NAME = 'objectsforreviewplugin';
    const FORK_PRODUCT = 'objectsForReview';

    /** Plugin name of this plugin in plugin_settings */
    const PLUGIN_NAME = 'bookreviewmanagerplugin';

    /** Prefixes of the fork's email template keys and locale keys */
    const FORK_EMAIL_PREFIX = 'OFR_';
    const EMAIL_PREFIX = 'BRM_';
    const FORK_LOCALE_PREFIX = 'plugins.generic.objectsForReview.';
    const LOCALE_PREFIX = 'plugins.generic.bookReviewManager.';

    /** Event log assoc type of objects (see classes/ReservationLog.php) */
    const EVENT_ASSOC_TYPE = 0x1F0F001;

    /** Navigation menu item types (see BookReviewManagerPlugin::NMI_TYPE) */
    const FORK_NMI_TYPE = 'OBJECTSFORREVIEW_NMI';
    const NMI_TYPE = 'BOOKREVIEWMANAGER_NMI';

    /**
     * Run the migrations.
     */
    /**
     * @param ?int $fromVersion Schema version of the installation (0: new installation;
     *  null: the version stored in the plugin settings)
     */
    public function __construct(public ?int $fromVersion = null)
    {
    }

    public function up()
    {
        // Also runs as install migration, possibly after the plugin upgraded itself in the same request
        $this->fromVersion ??= $this->storedVersion();
        if ($this->fromVersion >= self::SCHEMA_VERSION) {
            return;
        }

        if (!Schema::hasTable('brm_objects') && Schema::hasTable('objects_for_review_assignments')) {
            // The fork's data is at the fork's schema version (versions 1-4 are the same as here)
            $this->fromVersion = max($this->fromVersion, $this->adoptFork());
        }

        if (!Schema::hasTable('brm_objects')) {
            Schema::create('brm_objects', function (Blueprint $table) {
                $table->bigInteger('object_id')->autoIncrement();
                $table->bigInteger('submission_id')->nullable();
                $table->bigInteger('context_id');
                $table->bigInteger('user_id')->nullable();
                $table->string('identifier', 255);
                $table->string('identifier_type', 255);
                $table->string('resource_type', 255);
                $table->string('creator', 255);
                $table->datetime('date_created');
            });
        }

        if (!Schema::hasTable('brm_object_settings')) {
            Schema::create('brm_object_settings', function (Blueprint $table) {
                $table->bigInteger('object_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->comment('(bool|int|float|string|object)');
                $table->index(['object_id'], 'brm_object_settings_id');
                $table->unique(['object_id', 'locale', 'setting_name'], 'brm_object_settings_pkey');
            });
        }

        // One row per bidder or reviewer of an object (see classes/Assignment.php)
        if (!Schema::hasTable('brm_assignments')) {
            Schema::create('brm_assignments', function (Blueprint $table) {
                $table->bigInteger('assignment_id')->autoIncrement();
                $table->bigInteger('object_id');
                $table->bigInteger('context_id');
                $table->bigInteger('user_id');
                $table->string('status', 32);
                $table->datetime('date_created');
                $table->datetime('date_status');
                $table->index(['object_id'], 'brm_assignments_object_id');
                $table->index(['user_id'], 'brm_assignments_user_id');
                $table->index(['context_id', 'status'], 'brm_assignments_context_status');
            });
        }

        if (!Schema::hasTable('brm_assignment_settings')) {
            Schema::create('brm_assignment_settings', function (Blueprint $table) {
                $table->bigInteger('assignment_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->comment('(bool|int|float|string|object)');
                $table->index(['assignment_id'], 'brm_assignment_settings_id');
                $table->unique(['assignment_id', 'locale', 'setting_name'], 'brm_assignment_settings_pkey');
            });
        }

        // One-click links sent by email (see classes/ActionLink.php)
        if (!Schema::hasTable('brm_links')) {
            Schema::create('brm_links', function (Blueprint $table) {
                $table->bigInteger('link_id')->autoIncrement();
                $table->string('key_hash', 64);
                $table->bigInteger('assignment_id');
                $table->string('action', 32);
                $table->datetime('date_created');
                $table->datetime('date_expires');
                $table->datetime('date_used')->nullable();
                $table->unique(['key_hash'], 'brm_links_key_hash');
                $table->index(['assignment_id'], 'brm_links_assignment_id');
            });
        }

        // Data migrations run once: they would change data the workflow wrote later
        // (e.g. accepted assignments without a review copy status)
        if ($this->fromVersion < 2) {
            $this->migrateReservations();
        }
        if ($this->fromVersion < 3) {
            $this->initCopyStatus();
        }
        if ($this->fromVersion < 4) {
            $this->plainTextTitles();
        }
        // New installations get the current texts when the email templates are installed
        if ($this->fromVersion > 0 && $this->fromVersion < 6) {
            $this->refreshDefaultEmailTexts(self::CHANGED_TEMPLATES_6);
        }

        DB::table('plugin_settings')->updateOrInsert(
            ['plugin_name' => self::PLUGIN_NAME, 'context_id' => null, 'setting_name' => 'schemaVersion'],
            ['setting_value' => (string) self::SCHEMA_VERSION, 'setting_type' => 'int']
        );
        Cache::forget(self::settingsCacheId(null, self::PLUGIN_NAME));
        $this->fromVersion = self::SCHEMA_VERSION;
    }

    /**
     * The schema version stored in the site-wide plugin settings (0: not installed yet).
     */
    protected function storedVersion(): int
    {
        if (!Schema::hasTable('plugin_settings')) {
            return 0;
        }
        return (int) DB::table('plugin_settings')
            ->where('plugin_name', self::PLUGIN_NAME)
            ->whereNull('context_id')
            ->where('setting_name', 'schemaVersion')
            ->value('setting_value');
    }

    /**
     * Cache key of a plugin's settings (see PluginSettingsDAO::_getCacheId()).
     */
    protected static function settingsCacheId(?int $contextId, string $pluginName): string
    {
        return 'pluginSettings-' . (int) $contextId . '-' . strtolower($pluginName);
    }

    /**
     * Bring objects copied from the upstream Objects for Review plugin to the current
     * data model (see classes/UpstreamImport.php). Only touches the given objects.
     * @param int[] $objectIds
     */
    public function convertImported(array $objectIds): void
    {
        if (!$objectIds) {
            return;
        }
        $this->migrateReservations($objectIds);
        $this->initCopyStatus($objectIds);
        $this->plainTextTitles($objectIds);
    }

    /**
     * Schema version 5: take over an installation of the objectsForReview fork
     * (cboulanger/objectsForReview), which this plugin was renamed from.
     *
     * Only the fork has the objects_for_review_assignments table, so an installation of the
     * upstream Objects for Review plugin is never touched (its data can be copied with
     * tools/importObjectsForReview.php). Afterwards the objects_for_review* names are free
     * again, so the upstream plugin can be installed alongside.
     */
    protected function adoptFork(): int
    {
        $forkVersion = (int) DB::table('plugin_settings')
            ->where('plugin_name', self::FORK_PLUGIN_NAME)
            ->whereNull('context_id')
            ->where('setting_name', 'schemaVersion')
            ->value('setting_value');

        // Tables and their named indexes
        foreach (self::FORK_TABLES as $from => $to) {
            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
        foreach (self::FORK_INDEXES as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $existing = array_map('strtolower', array_column(Schema::getIndexes($table), 'name'));
            foreach ($indexes as $from => $to) {
                if (in_array($from, $existing, true) && !in_array($to, $existing, true)) {
                    Schema::table($table, fn (Blueprint $t) => $t->renameIndex($from, $to));
                }
            }
        }

        // Plugin settings (enabled state, user groups, configuration) move to this plugin;
        // the fork's schema version is not taken over.
        $pluginName = self::PLUGIN_NAME;
        $settings = DB::table('plugin_settings')->where('plugin_name', self::FORK_PLUGIN_NAME)->get();
        foreach ($settings as $setting) {
            if ($setting->setting_name === 'schemaVersion') {
                continue;
            }
            $exists = DB::table('plugin_settings')
                ->where('plugin_name', $pluginName)
                ->where('context_id', $setting->context_id)
                ->where('setting_name', $setting->setting_name)
                ->exists();
            if (!$exists) {
                DB::table('plugin_settings')->insert([
                    'plugin_name' => $pluginName,
                    'context_id' => $setting->context_id,
                    'setting_name' => $setting->setting_name,
                    'setting_value' => $setting->setting_value,
                    'setting_type' => $setting->setting_type,
                ]);
            }
        }
        DB::table('plugin_settings')->where('plugin_name', self::FORK_PLUGIN_NAME)->delete();
        foreach ($settings->pluck('context_id')->unique() as $contextId) {
            Cache::forget(self::settingsCacheId($contextId, self::FORK_PLUGIN_NAME));
            Cache::forget(self::settingsCacheId($contextId, $pluginName));
        }

        // The fork counts as uninstalled (the upstream plugin installs its own tables then)
        DB::table('versions')
            ->where('product_type', 'plugins.generic')
            ->where('product', self::FORK_PRODUCT)
            ->delete();

        // Email templates: journals' customised templates and the default texts
        $forkKeys = DB::table('email_templates_default_data')
            ->where('email_key', 'like', self::FORK_EMAIL_PREFIX . '%')
            ->distinct()
            ->pluck('email_key')
            ->merge(DB::table('email_templates')->where('email_key', 'like', self::FORK_EMAIL_PREFIX . '%')->pluck('email_key'))
            ->unique();
        foreach ($forkKeys as $forkKey) {
            $key = self::EMAIL_PREFIX . substr($forkKey, strlen(self::FORK_EMAIL_PREFIX));
            DB::table('email_templates')->where('email_key', $forkKey)->update(['email_key' => $key]);
            DB::table('email_templates')->where('alternate_to', $forkKey)->update(['alternate_to' => $key]);
            foreach (DB::table('email_templates_default_data')->where('email_key', $forkKey)->pluck('locale') as $locale) {
                $exists = DB::table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists();
                $rows = DB::table('email_templates_default_data')->where('email_key', $forkKey)->where('locale', $locale);
                $exists ? $rows->delete() : $rows->update(['email_key' => $key]);
            }
        }

        // Navigation menu items linking to the public page
        DB::table('navigation_menu_items')->where('type', self::FORK_NMI_TYPE)->update(['type' => self::NMI_TYPE]);

        // History entries store the locale key of their message
        DB::table('event_log')
            ->where('assoc_type', self::EVENT_ASSOC_TYPE)
            ->where('message', 'like', self::FORK_LOCALE_PREFIX . '%')
            ->update(['message' => DB::raw('REPLACE(message, ' . DB::getPdo()->quote(self::FORK_LOCALE_PREFIX) . ', ' . DB::getPdo()->quote(self::LOCALE_PREFIX) . ')')]);

        return $forkVersion;
    }

    /**
     * Schema version 6: replace the default texts of email templates with the current ones.
     * Plugin upgrades only install templates that are missing, so changed default texts
     * would not arrive otherwise. Templates a journal has customized are not changed.
     */
    protected function refreshDefaultEmailTexts(array $keys): void
    {
        $file = __DIR__ . '/emailTemplates.xml';
        $locales = array_filter(\PKP\db\DAORegistry::getDAO('SiteDAO')->getSite()?->getInstalledLocales() ?? [],
            fn (string $locale) => file_exists(__DIR__ . "/locale/{$locale}/emails.po"));
        if (!$locales || !Schema::hasTable('email_templates_default_data')) {
            return;
        }
        // The texts come from the plugin's locale files
        Locale::registerPath(__DIR__ . '/locale');
        foreach ($keys as $key) {
            // Only templates that are installed: missing ones are added with the current text anyway
            if (DB::table('email_templates_default_data')->where('email_key', $key)->exists()) {
                Repo::emailTemplate()->dao->installEmailTemplateLocaleData($file, $locales, $key);
            }
        }
    }

    /**
     * Schema version 4: titles were entered in a rich text editor; they are plain text now.
     */
    protected function plainTextTitles(?array $objectIds = null): void
    {
        $titles = DB::table('brm_object_settings')->where('setting_name', 'title')
            ->when($objectIds !== null, fn ($q) => $q->whereIn('object_id', $objectIds))
            ->get();
        foreach ($titles as $row) {
            $plain = trim(html_entity_decode(strip_tags((string) $row->setting_value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $plain = preg_replace('/\s+/u', ' ', $plain);
            if ($plain !== $row->setting_value) {
                DB::table('brm_object_settings')
                    ->where('object_id', $row->object_id)
                    ->where('locale', $row->locale)
                    ->where('setting_name', 'title')
                    ->update(['setting_value' => $plain]);
            }
        }
    }

    /**
     * Schema version 3: current reviewers get a review copy status (see CopyWorkflow).
     */
    protected function initCopyStatus(?array $objectIds = null): void
    {
        $assignments = DB::table('brm_assignments')->where('status', 'accepted')
            ->when($objectIds !== null, fn ($q) => $q->whereIn('object_id', $objectIds))
            ->pluck('assignment_id');
        foreach ($assignments as $assignmentId) {
            $settings = DB::table('brm_assignment_settings')->where('assignment_id', $assignmentId);
            if ((clone $settings)->where('setting_name', 'copyStatus')->exists()) {
                continue;
            }
            $preference = (clone $settings)->where('setting_name', 'copyPreference')->value('setting_value');
            DB::table('brm_assignment_settings')->insert([
                'assignment_id' => $assignmentId,
                'locale' => '',
                'setting_name' => 'copyStatus',
                'setting_value' => in_array($preference, ['physical', 'electronic'], true) ? 'pending' : 'notNeeded',
                'setting_type' => 'string',
            ]);
        }
    }

    /**
     * Schema version 2: reservations stored with the object become assignments.
     * Objects with a reviewer and no submission become "accepted" assignments,
     * objects attached to a submission "submitted" ones.
     */
    protected function migrateReservations(?array $objectIds = null): void
    {
        $objects = DB::table('brm_objects')->whereNotNull('user_id')
            ->when($objectIds !== null, fn ($q) => $q->whereIn('object_id', $objectIds))
            ->get();
        foreach ($objects as $object) {
            $exists = DB::table('brm_assignments')
                ->where('object_id', $object->object_id)
                ->where('user_id', $object->user_id)
                ->exists();
            if ($exists) {
                continue;
            }
            $settings = DB::table('brm_object_settings')
                ->where('object_id', $object->object_id)
                ->whereIn('setting_name', self::LEGACY_RESERVATION_FIELDS)
                ->get();
            $dateReserved = $settings->firstWhere('setting_name', 'dateReserved')?->setting_value ?? $object->date_created;
            $now = date('Y-m-d H:i:s');
            $assignmentId = DB::table('brm_assignments')->insertGetId([
                'object_id' => $object->object_id,
                'context_id' => $object->context_id,
                'user_id' => $object->user_id,
                'status' => $object->submission_id ? 'submitted' : 'accepted',
                'date_created' => $dateReserved,
                'date_status' => $now,
            ], 'assignment_id');
            foreach ($settings as $setting) {
                DB::table('brm_assignment_settings')->insert([
                    'assignment_id' => $assignmentId,
                    'locale' => '',
                    'setting_name' => $setting->setting_name,
                    'setting_value' => $setting->setting_value,
                    'setting_type' => $setting->setting_type,
                ]);
            }
        }
        DB::table('brm_object_settings')
            ->whereIn('setting_name', self::LEGACY_RESERVATION_FIELDS)
            ->when($objectIds !== null, fn ($q) => $q->whereIn('object_id', $objectIds))
            ->delete();
    }
}
