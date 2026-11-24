<?php

/**
 * @file tests/unit/php/ReservationLogTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief The editors' notes in the history (writes to the database).
 */

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class ReservationLogTest extends TestCase {

	protected ?ObjectForReview $work = null;

	protected function setUp(): void {
		$dao = new ObjectForReviewDAO();
		$this->work = $dao->newDataObject();
		$this->work->setContextId(self::CONTEXT_ID);
		$this->work->setIdentifier('978' . random_int(1000000000, 9999999999));
		$this->work->setIdentifierType('isbn');
		$this->work->setResourceType('http://purl.org/coar/resource_type/c_2f33');
		$this->work->setCreator('manager');
		$this->work->setTitle('Note test');
		$this->work->setOfferSetting('hidden', true);
		$dao->insertObject($this->work);
	}

	protected function tearDown(): void {
		(new ObjectForReviewDAO())->deleteById($this->work->getId());
	}

	public function testNoteValidation(): void {
		$today = date('Y-m-d');
		$this->assertNull(ReservationLog::validateNote('Replied by phone', $today));
		$this->assertNull(ReservationLog::validateNote('Replied by phone', '2020-02-29'));
		$this->assertSame('plugins.generic.bookReviewManager.history.note.error.required', ReservationLog::validateNote("  \n ", $today));
		$this->assertSame('plugins.generic.bookReviewManager.history.note.error.tooLong',
			ReservationLog::validateNote(str_repeat('x', ReservationLog::MAX_NOTE_LENGTH + 1), $today));
		foreach (['', '2021-02-29', '24.11.2026', date('Y-m-d', strtotime('+1 day'))] as $bad) {
			$this->assertSame('plugins.generic.bookReviewManager.history.note.error.date', ReservationLog::validateNote('x', $bad), $bad);
		}
	}

	public function testNotesInHistory(): void {
		$editor = Repo::user()->getByUsername('admin', true);
		ReservationLog::addNote($this->work, $editor, "  Reviewer called:\n<b>needs</b> two more weeks  ", date('Y-m-d'));
		ReservationLog::addNote($this->work, $editor, 'Asked by email', '2020-01-15');

		$history = ReservationLog::getHistory($this->work);
		$this->assertCount(2, $history);
		[$today, $earlier] = $history;
		$this->assertSame('note', $today['type']);
		$this->assertSame('Note', $today['label']);
		$this->assertSame("Reviewer called:\n<b>needs</b> two more weeks", $today['body'], 'stored as typed (trimmed), escaped on display');
		$this->assertSame($editor->getFullName(), $today['actor']);
		$this->assertFalse($today['dateOnly']);
		$this->assertSame('Asked by email', $earlier['body']);
		$this->assertSame('2020-01-15 00:00:00', $earlier['date']);
		$this->assertTrue($earlier['dateOnly']);
	}

	public function testInvalidNoteIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		ReservationLog::addNote($this->work, Repo::user()->getByUsername('admin', true), '', date('Y-m-d'));
	}
}
