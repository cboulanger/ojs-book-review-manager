<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\Reservation;

class ReservationTest extends TestCase {

	private array $range = ['min' => '2027-01-02', 'max' => '2027-06-01', 'default' => '2027-04-01'];

	private function valid(array $input = []): array {
		return array_merge(['acceptTerms' => '1', 'dueDate' => '2027-03-01', 'copyPreference' => 'have'], $input);
	}

	public function testValidInput(): void {
		[$errors, $data] = Reservation::validate($this->valid(), $this->range);
		$this->assertSame([], $errors);
		$this->assertSame('2027-03-01', $data['dueDate']);
		$this->assertSame('have', $data['copyPreference']);
		$this->assertNotEmpty($data['dateReserved']);
		$this->assertSame(Reservation::FIELDS, array_keys($data));
	}

	public function testTermsMustBeAccepted(): void {
		[$errors] = Reservation::validate($this->valid(['acceptTerms' => '']), $this->range);
		$this->assertArrayHasKey('acceptTerms', $errors);
	}

	public function testDueDateFormatAndRange(): void {
		foreach (['2027-3-1', '01.03.2027', '2027-02-30', ''] as $bad) {
			[$errors] = Reservation::validate($this->valid(['dueDate' => $bad]), $this->range);
			$this->assertSame('plugins.generic.bookReviewManager.reserve.error.dueDate', $errors['dueDate'] ?? null, $bad);
		}
		foreach (['2027-01-01', '2027-06-02'] as $outside) {
			[$errors] = Reservation::validate($this->valid(['dueDate' => $outside]), $this->range);
			$this->assertSame('plugins.generic.bookReviewManager.reserve.error.dueDateRange', $errors['dueDate'] ?? null, $outside);
		}
		foreach (['2027-01-02', '2027-06-01'] as $edge) {
			[$errors] = Reservation::validate($this->valid(['dueDate' => $edge]), $this->range);
			$this->assertArrayNotHasKey('dueDate', $errors, $edge);
		}
	}

	public function testCopyPreference(): void {
		[$errors] = Reservation::validate($this->valid(['copyPreference' => 'pdf']), $this->range);
		$this->assertArrayHasKey('copyPreference', $errors);
	}

	public function testPhysicalCopyNeedsAddressAndPhone(): void {
		[$errors] = Reservation::validate($this->valid(['copyPreference' => 'physical']), $this->range);
		$this->assertArrayHasKey('shippingAddress', $errors);
		$this->assertArrayHasKey('phone', $errors);

		[$errors] = Reservation::validate($this->valid(['copyPreference' => 'physical', 'shippingAddress' => 'Street 1', 'phone' => 'call me']), $this->range);
		$this->assertArrayHasKey('phone', $errors, 'phone must look like a number');

		[$errors, $data] = Reservation::validate($this->valid(['copyPreference' => 'physical', 'shippingAddress' => " Street 1\nTown ", 'phone' => '+49 (30) 123-456']), $this->range);
		$this->assertSame([], $errors);
		$this->assertSame("Street 1\nTown", $data['shippingAddress']);
	}

	public function testAddressIsIgnoredForOtherPreferences(): void {
		[, $data] = Reservation::validate($this->valid(['copyPreference' => 'electronic', 'shippingAddress' => 'Street 1', 'phone' => '123456']), $this->range);
		$this->assertNull($data['shippingAddress']);
		$this->assertNull($data['phone']);
	}

	public function testOtherNeedsNote(): void {
		[$errors] = Reservation::validate($this->valid(['copyPreference' => 'other']), $this->range);
		$this->assertArrayHasKey('copyNote', $errors);
		[$errors, $data] = Reservation::validate($this->valid(['copyPreference' => 'other', 'copyNote' => 'From the library']), $this->range);
		$this->assertSame([], $errors);
		$this->assertSame('From the library', $data['copyNote']);
	}

	public function testApplySetsAndUnsetsFields(): void {
		$assignment = $this->assignment(Assignment::STATUS_ACCEPTED, ['phone' => 'old', 'copyNote' => 'old']);
		Reservation::apply($assignment, ['dueDate' => '2027-03-01', 'copyPreference' => 'have', 'phone' => '', 'copyNote' => null]);
		$this->assertSame('2027-03-01', $assignment->getData('dueDate'));
		$this->assertFalse($assignment->hasData('phone'));
		$this->assertFalse($assignment->hasData('copyNote'));
	}

	public function testErasePersonalData(): void {
		$assignment = $this->assignment(Assignment::STATUS_ACCEPTED, ['shippingAddress' => 'Street', 'phone' => '123', 'dueDate' => '2027-01-01']);
		Reservation::erasePersonalData($assignment);
		$this->assertFalse($assignment->hasData('shippingAddress'));
		$this->assertFalse($assignment->hasData('phone'));
		$this->assertSame('2027-01-01', $assignment->getData('dueDate'));
	}

	public function testDueMonthsAndRange(): void {
		$this->assertSame([Reservation::DEFAULT_DUE_MONTHS, Reservation::DEFAULT_MAX_DUE_MONTHS], Reservation::getDueMonths($this->plugin(), 1));
		$this->assertSame([2, 2], Reservation::getDueMonths($this->plugin(['ofrDueMonthsDefault' => 5, 'ofrDueMonthsMax' => 2]), 1),
			'the default never exceeds the maximum');

		$range = Reservation::getDueDateRange($this->plugin(['ofrDueMonthsDefault' => 1, 'ofrDueMonthsMax' => 4]), 1);
		$today = new \DateTimeImmutable('today');
		$this->assertSame($today->modify('+1 day')->format('Y-m-d'), $range['min']);
		$this->assertSame($today->modify('+1 months')->format('Y-m-d'), $range['default']);
		$this->assertSame($today->modify('+4 months')->format('Y-m-d'), $range['max']);
	}

	public function testReminderDays(): void {
		$this->assertSame(Reservation::DEFAULT_REMINDER_DAYS, Reservation::getReminderDays($this->plugin(), 1));
		$this->assertSame(0, Reservation::getReminderDays($this->plugin(['ofrReminderDays' => '0']), 1), '0 disables reminders');
	}

	public function testDisplayData(): void {
		$data = Reservation::getDisplayData($this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreference' => 'physical', 'dueDate' => '2027-01-01']));
		$this->assertSame('2027-01-01', $data['dueDate']);
		$this->assertNotSame('', (string) $data['copyPreferenceLabel']);
		$this->assertStringNotContainsString('##', (string) $data['copyPreferenceLabel'], 'label is translated');
	}

	public function testPerWorkCopyPreferences(): void {
		$members = [$this->object(['objectId' => 5]), $this->object(['objectId' => 6])];
		$input = $this->valid(['copyPreference' => null, 'copyPreferences' => ['5' => 'physical', '6' => 'have']]);
		[$errors] = Reservation::validate($input, $this->range, $members);
		$this->assertArrayHasKey('shippingAddress', $errors, 'a physical copy of one work needs the address');
		$this->assertArrayHasKey('phone', $errors);

		[$errors, $data] = Reservation::validate($input + ['shippingAddress' => 'Street 1', 'phone' => '+49 30 123456'], $this->range, $members);
		$this->assertSame([], $errors);
		$this->assertSame([5 => 'physical', 6 => 'have'], $data['copyPreferences']);
		$this->assertNull($data['copyPreference']);
		$this->assertSame('Street 1', $data['shippingAddress']);
	}

	public function testPerWorkCopyPreferenceMissingOrInvalid(): void {
		$members = [$this->object(['objectId' => 5]), $this->object(['objectId' => 6])];
		foreach ([['5' => 'have'], ['5' => 'have', '6' => 'pdf'], [], null] as $preferences) {
			[$errors, $data] = Reservation::validate($this->valid(['copyPreferences' => $preferences]), $this->range, $members);
			$this->assertSame('plugins.generic.bookReviewManager.reserve.error.copyPreference', $errors['copyPreference'] ?? null, json_encode($preferences));
			$this->assertNull($data['copyPreferences']);
		}
		[$errors] = Reservation::validate($this->valid(['copyPreferences' => ['5' => 'physical']]), $this->range, $members);
		$this->assertArrayHasKey('copyPreference', $errors);
		$this->assertArrayHasKey('shippingAddress', $errors, 'all errors show at once');
	}

	public function testPerWorkOtherNeedsNote(): void {
		$members = [$this->object(['objectId' => 5]), $this->object(['objectId' => 6])];
		$input = $this->valid(['copyPreferences' => ['5' => 'other', '6' => 'electronic'], 'shippingAddress' => 'Street 1']);
		[$errors, $data] = Reservation::validate($input, $this->range, $members);
		$this->assertArrayHasKey('copyNote', $errors);
		$this->assertNull($data['shippingAddress'], 'no physical copy: the address is not kept');
		[$errors, $data] = Reservation::validate($input + ['copyNote' => 'Library'], $this->range, $members);
		$this->assertSame([], $errors);
		$this->assertSame('Library', $data['copyNote']);
	}

	public function testPerWorkPreferencesAreShownOnePerLine(): void {
		$assignment = $this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreferences' => [999999901 => 'physical', 999999902 => 'have']]);
		$label = (string) Reservation::getDisplayData($assignment)['copyPreferenceLabel'];
		$this->assertCount(2, explode("\n", $label));
		$this->assertStringContainsString(__('plugins.generic.bookReviewManager.reserve.copy.physical'), $label);
		$this->assertStringContainsString(__('plugins.generic.bookReviewManager.reserve.copy.have'), $label);
	}
}
