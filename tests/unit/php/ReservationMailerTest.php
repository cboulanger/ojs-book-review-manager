<?php

/**
 * @file tests/unit/php/ReservationMailerTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Notices to the editor (writes to the database, mail is faked).
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
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class ReservationMailerTest extends TestCase {

	protected ?int $objectId = null;

	protected function tearDown(): void {
		if ($this->objectId) {
			DB::table('email_log')->where('assoc_type', ReservationLog::ASSOC_TYPE)->where('assoc_id', $this->objectId)->delete();
			(new ObjectForReviewDAO())->deleteById($this->objectId);
		}
	}

	/** Without a responsible editor, the notice goes to the notification address (used to fail: to() isn't allowed) */
	public function testNoticeWithoutResponsibleEditorGoesToNotificationAddress(): void {
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

		$context = Application::getContextDAO()->getById(self::CONTEXT_ID);
		$plugin = new class(['ofrNotifyEmail' => 'notices@example.org']) extends FakePlugin {
			public function getInstallEmailTemplatesFile() {
				return dirname(__DIR__, 3) . '/emailTemplates.xml';
			}
		};

		Mail::fake();
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_DECLINED, $work, null);
		Mail::assertSent(ObjectsForReviewInvitationDeclined::class,
			fn ($mailable) => $mailable->hasTo('notices@example.org'));
	}
}
