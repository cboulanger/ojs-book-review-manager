<?php

/**
 * @file tests/unit/php/ReservationMailerTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Notices to the editor, the switch that turns the plugin's emails off, and
 *  the language of an email (writes to the database, mail is faked).
 */

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\ReservationEmailEventType;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationDeclined;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\db\DAORegistry;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class ReservationMailerTest extends TestCase {

	protected ?int $objectId = null;

	/** The journal's email switch before the test (false: there was none) */
	protected mixed $savedSwitch = false;

	protected function setUp(): void {
		$this->savedSwitch = DB::table('plugin_settings')->where('plugin_name', 'bookreviewmanagerplugin')
			->where('context_id', self::CONTEXT_ID)->where('setting_name', ReservationMailer::SETTING_SUPPRESS)->first() ?? false;
	}

	protected function tearDown(): void {
		$this->setSwitch(null);
		if ($this->savedSwitch) {
			DB::table('plugin_settings')->insert((array) $this->savedSwitch);
		}
		if ($this->objectId) {
			DB::table('email_log')->where('assoc_type', ReservationLog::ASSOC_TYPE)->where('assoc_id', $this->objectId)->delete();
			(new ObjectForReviewDAO())->deleteById($this->objectId);
		}
	}

	/** null removes the setting (the default: emails off) */
	protected function setSwitch(?bool $suppress): void {
		$dao = DAORegistry::getDAO('PluginSettingsDAO');
		$dao->deleteSetting(self::CONTEXT_ID, 'bookreviewmanagerplugin', ReservationMailer::SETTING_SUPPRESS);
		if ($suppress !== null) {
			$dao->updateSetting(self::CONTEXT_ID, 'bookreviewmanagerplugin', ReservationMailer::SETTING_SUPPRESS, $suppress, 'bool');
		}
	}

	protected function work() {
		$dao = new ObjectForReviewDAO();
		$work = $dao->newDataObject();
		$work->setContextId(self::CONTEXT_ID);
		$work->setIdentifier('978' . random_int(1000000000, 9999999999));
		$work->setIdentifierType('isbn');
		$work->setResourceType('http://purl.org/coar/resource_type/c_2f33');
		$work->setCreator('manager');
		$work->setTitle('Notice test');
		$work->setAuthors('Author');
		$work->setOfferSetting('hidden', true);
		$dao->insertObject($work);
		$this->objectId = $work->getId();
		return $work;
	}

	protected function noticePlugin() {
		return new class(['ofrNotifyEmail' => 'notices@example.org']) extends FakePlugin {
			public function getInstallEmailTemplatesFile() {
				return dirname(__DIR__, 3) . '/emailTemplates.xml';
			}
		};
	}

	/** Emails are off until a journal manager switches them on */
	public function testEmailsAreOffByDefault(): void {
		$this->setSwitch(null);
		$this->assertTrue(ReservationMailer::emailsSuppressed(self::CONTEXT_ID));
		$this->assertTrue(ReservationMailer::emailsSuppressed(null));
		$this->setSwitch(false);
		$this->assertFalse(ReservationMailer::emailsSuppressed(self::CONTEXT_ID));
		$this->setSwitch(true);
		$this->assertTrue(ReservationMailer::emailsSuppressed(self::CONTEXT_ID));
	}

	/** Switched off: nothing is sent, but the history records the email as not sent */
	public function testSuppressedEmailIsLoggedButNotSent(): void {
		$this->setSwitch(null);
		$work = $this->work();
		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		Mail::fake();
		ReservationMailer::notifyEditor($this->noticePlugin(), $context, ReservationEmailEventType::INVITATION_DECLINED, $work, null);
		Mail::assertNothingSent();
		$subject = DB::table('email_log')->where('assoc_type', ReservationLog::ASSOC_TYPE)->where('assoc_id', $work->getId())->value('subject');
		$this->assertStringStartsWith(__('plugins.generic.bookReviewManager.email.notSent'), (string) $subject);
	}

	/** Without a responsible editor, the notice goes to the notification address (used to fail: to() isn't allowed) */
	public function testNoticeWithoutResponsibleEditorGoesToNotificationAddress(): void {
		$this->setSwitch(false);
		$work = $this->work();
		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		Mail::fake();
		ReservationMailer::notifyEditor($this->noticePlugin(), $context, ReservationEmailEventType::INVITATION_DECLINED, $work, null);
		Mail::assertSent(ObjectsForReviewInvitationDeclined::class,
			fn ($mailable) => $mailable->hasTo('notices@example.org'));
	}

	/** The language of an email: a supported one as requested, anything else falls back to the primary language */
	public function testEmailLocaleFallsBackToPrimaryLanguage(): void {
		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		$primary = $context->getPrimaryLocale();
		$this->assertSame($primary, ReservationMailer::emailLocale($context));
		$this->assertSame($primary, ReservationMailer::emailLocale($context, 'xx'));
		foreach ($context->getSupportedLocales() as $locale) {
			$this->assertSame($locale, ReservationMailer::emailLocale($context, $locale));
		}
		$this->assertSame($context->getSupportedLocaleNames(), ReservationMailer::emailLocales($context));
	}

	/** The template is filled in the requested language (used to be always the primary language) */
	public function testComposeUsesRequestedLanguage(): void {
		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		$locales = $context->getSupportedLocales();
		if (!in_array('de', $locales, true) || !in_array('en', $locales, true)) {
			$this->markTestSkipped('The dev journal needs English and German');
		}
		ReservationMailer::ensureTemplates($this->noticePlugin(), self::CONTEXT_ID);
		$work = $this->work();
		$template = \APP\facades\Repo::emailTemplate()->getByKey(self::CONTEXT_ID,
			ReservationMailer::MAILABLES[ReservationEmailEventType::INVITATION_RESERVE->value]::getEmailTemplateKey());
		foreach (['de', 'en'] as $locale) {
			[$subject] = ReservationMailer::compose($context, ReservationEmailEventType::INVITATION_RESERVE, $work, null, null, null, [], $locale);
			$expected = Mail::compileParams((string) $template->getLocalizedData('subject', $locale),
				ReservationMailer::getParams($context, $work, null, null, null, $locale));
			$this->assertSame($expected, $subject, $locale);
		}
		[$de] = ReservationMailer::compose($context, ReservationEmailEventType::INVITATION_RESERVE, $work, null, null, null, [], 'de');
		[$en] = ReservationMailer::compose($context, ReservationEmailEventType::INVITATION_RESERVE, $work, null, null, null, [], 'en');
		$this->assertNotSame($de, $en);
	}

	/** Dates follow the email's language, with translated month names */
	public function testFormatDateInEmailLanguage(): void {
		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		$this->assertSame('', ReservationMailer::formatDate($context, null, 'en'));
		$this->assertSame('', ReservationMailer::formatDate($context, '', 'en'));
		foreach ($context->getSupportedLocales() as $locale) {
			$format = \PKP\core\PKPString::convertStrftimeFormat($context->getLocalizedDateFormatShort($locale));
			$this->assertSame((new \Carbon\Carbon('2026-03-05'))->locale($locale)->translatedFormat($format),
				ReservationMailer::formatDate($context, '2026-03-05', $locale), $locale);
		}
	}
}
