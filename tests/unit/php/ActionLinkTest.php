<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\ActionLink;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * One-click links: hashed, expiring, single use. Writes to brm_links
 * with an assignment id no real assignment has, and removes the rows afterwards.
 */
#[Group('db')]
class ActionLinkTest extends TestCase {

	const ASSIGNMENT_ID = -4242;

	protected function tearDown(): void {
		DB::table(ActionLink::TABLE)->where('assignment_id', self::ASSIGNMENT_ID)->delete();
	}

	private function future(): string {
		return date('Y-m-d H:i:s', strtotime('+1 day'));
	}

	public function testCreateAndFind(): void {
		$key = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_CONTRACT, $this->future());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $key);

		$link = ActionLink::find($key);
		$this->assertNotNull($link);
		$this->assertSame(self::ASSIGNMENT_ID, (int) $link->assignment_id);
		$this->assertSame(ActionLink::ACTION_CONTRACT, $link->action);
	}

	public function testOnlyTheHashIsStored(): void {
		$key = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_LEAVE, $this->future());
		$row = DB::table(ActionLink::TABLE)->where('assignment_id', self::ASSIGNMENT_ID)->first();
		$this->assertSame(hash('sha256', $key), $row->key_hash);
		$this->assertStringNotContainsString($key, json_encode($row));
	}

	public function testKeysAreUnique(): void {
		$keys = array_map(fn () => ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_LEAVE, $this->future()), range(1, 20));
		$this->assertCount(20, array_unique($keys));
	}

	public function testMalformedAndUnknownKeys(): void {
		$this->assertNull(ActionLink::find(''));
		$this->assertNull(ActionLink::find('xyz'));
		$this->assertNull(ActionLink::find(str_repeat('A', 48)), 'upper case is not a key');
		$this->assertNull(ActionLink::find(str_repeat('0', 48)));
		$key = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_LEAVE, $this->future());
		$this->assertNull(ActionLink::find($key . 'a'));
	}

	public function testExpiredLinksAreNotFound(): void {
		$key = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_CONTRACT, date('Y-m-d H:i:s', strtotime('-1 second')));
		$this->assertNull(ActionLink::find($key));
	}

	public function testLinksAreSingleUse(): void {
		$key = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_RECEIVED, $this->future());
		ActionLink::markUsed((int) ActionLink::find($key)->link_id);
		$this->assertNull(ActionLink::find($key));
	}

	public function testInvalidateOneActionOrAll(): void {
		$contract = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_CONTRACT, $this->future());
		$retract = ActionLink::create(self::ASSIGNMENT_ID, ActionLink::ACTION_RETRACT, $this->future());

		ActionLink::invalidate(self::ASSIGNMENT_ID, ActionLink::ACTION_CONTRACT);
		$this->assertNull(ActionLink::find($contract));
		$this->assertNotNull(ActionLink::find($retract));

		ActionLink::invalidate(self::ASSIGNMENT_ID);
		$this->assertNull(ActionLink::find($retract));
	}
}
