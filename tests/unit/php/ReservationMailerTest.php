<?php

/**
 * @file tests/unit/php/ReservationMailerTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Notices to the editor, and the switch that turns the plugin's emails off
 *  (writes to the database, mail is faked).
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
}
