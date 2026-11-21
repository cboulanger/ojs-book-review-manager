<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow as C;

/**
 * Review copy states and which steps are allowed. Return steps need a publisher
 * contact with a mailing address: the dev journal's "publisher" account.
 */
class CopyWorkflowTest extends TestCase {

	public function testInit(): void {
		foreach (['physical' => C::PENDING, 'electronic' => C::PENDING, 'have' => C::NOT_NEEDED, 'other' => C::NOT_NEEDED] as $preference => $expected) {
			$assignment = $this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreference' => $preference]);
			C::init($assignment);
			$this->assertSame($expected, C::getStatus($assignment), $preference);
			$this->assertNotEmpty($assignment->getData('copyStatusDate'));
		}
	}

	public function testOnAssignmentEndedOnlyDropsPendingCopies(): void {
		$pending = $this->assignment(Assignment::STATUS_CANCELLED, ['copyStatus' => C::PENDING]);
		C::onAssignmentEnded($pending);
		$this->assertSame(C::NOT_NEEDED, C::getStatus($pending));

		$sent = $this->assignment(Assignment::STATUS_CANCELLED, ['copyStatus' => C::SENT]);
		C::onAssignmentEnded($sent);
		$this->assertSame(C::SENT, C::getStatus($sent), 'a copy on its way must still be tracked');
	}

	public function testStatusAndPhysical(): void {
		$this->assertNull(C::getStatus($this->assignment(Assignment::STATUS_BID)));
		$this->assertTrue(C::isPhysical($this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreference' => 'physical'])));
		$this->assertFalse(C::isPhysical($this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreference' => 'electronic'])));
	}

	public function testPendingCopySteps(): void {
		$pending = fn () => $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::PENDING, 'copyPreference' => 'electronic']);
		$this->assertSame([], C::getAllowedSteps($this->plugin(), $this->object(), $pending()), 'no publisher contact, no stock');
		$this->assertSame(['requestCopy'], C::getAllowedSteps($this->plugin(), $this->object(['publisherContactId' => 99]), $pending()));
		$this->assertSame(['sendFromStock'], C::getAllowedSteps($this->plugin(), $this->object(['journalHoldsCopy' => true]), $pending()));
		$this->assertSame([], C::getAllowedSteps($this->plugin(), $this->object(['publisherContactId' => 99]),
			$this->assignment(Assignment::STATUS_CANCELLED, ['copyStatus' => C::PENDING])), 'only for an active reviewer');
	}

	public function testRequestedAndSentSteps(): void {
		$object = $this->object(['returnRequired' => false]);
		$this->assertSame(['publisherSent', 'withdrawRequest'],
			C::getAllowedSteps($this->plugin(), $object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::REQUESTED])));
		$this->assertSame(['markReceived'],
			C::getAllowedSteps($this->plugin(), $object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::SENT, 'copyPreference' => 'physical'])));
		$this->assertSame(['returned'],
			C::getAllowedSteps($this->plugin(), $object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::RETURN_SENT])));
		$this->assertSame([],
			C::getAllowedSteps($this->plugin(), $object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::RETURNED])));
	}

	public function testReturnNeedsPhysicalCopyReturnRequiredAndAnAddress(): void {
		$contact = $this->publisherContact();
		$object = $this->object(['publisherContactId' => $contact->getId()]);
		$received = fn (string $status, string $preference = 'physical') => $this->assignment($status, ['copyStatus' => C::RECEIVED, 'copyPreference' => $preference]);

		$this->assertSame(['requestReturn'], C::getAllowedSteps($this->plugin(), $object, $received(Assignment::STATUS_ACCEPTED)));
		$this->assertSame(['requestReturn'], C::getAllowedSteps($this->plugin(), $object, $received(Assignment::STATUS_CANCELLED)));
		$this->assertSame([], C::getAllowedSteps($this->plugin(), $object, $received(Assignment::STATUS_ACCEPTED, 'electronic')));
		$this->assertSame([], C::getAllowedSteps($this->plugin(), $object, $received(Assignment::STATUS_SUBMITTED)), 'the review came');
		$this->assertSame([], C::getAllowedSteps($this->plugin(), $this->object(['publisherContactId' => $contact->getId(), 'returnRequired' => false]),
			$received(Assignment::STATUS_ACCEPTED)));
	}

	public function testIsOpen(): void {
		$object = $this->object(['publisherContactId' => $this->publisherContact()->getId()]);
		foreach (C::OPEN_STATUSES as $status) {
			$this->assertTrue(C::isOpen($object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => $status])), $status);
		}
		$this->assertFalse(C::isOpen($object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::RECEIVED, 'copyPreference' => 'physical'])));
		$this->assertTrue(C::isOpen($object, $this->assignment(Assignment::STATUS_CANCELLED, ['copyStatus' => C::RECEIVED, 'copyPreference' => 'physical'])),
			'a reviewer who dropped out has a copy to return');
		$this->assertFalse(C::isOpen($object, $this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::NOT_NEEDED])));
	}

	public function testReturnTargets(): void {
		$contact = $this->publisherContact();
		$targets = C::getReturnTargets($this->plugin(), $this->object(['publisherContactId' => $contact->getId()]));
		$this->assertStringContainsString($contact->getFullName(), (string) $targets[C::BY_PUBLISHER]);
		$this->assertStringContainsString(trim($contact->getMailingAddress()), (string) $targets[C::BY_PUBLISHER]);
		$this->assertNull(C::getReturnTargets($this->plugin(), $this->object())[C::BY_PUBLISHER]);
	}

	public function testStatusLabel(): void {
		$this->assertSame('', C::getStatusLabel($this->assignment(Assignment::STATUS_BID)));
		$plain = C::getStatusLabel($this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::SENT]));
		$withSender = C::getStatusLabel($this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => C::SENT, 'copySentBy' => C::BY_PUBLISHER]));
		$this->assertStringStartsWith($plain . ' (', $withSender);
		foreach (array_merge([C::NOT_NEEDED], C::OPEN_STATUSES, [C::RECEIVED, C::RETURNED]) as $status) {
			$label = C::getStatusLabel($this->assignment(Assignment::STATUS_ACCEPTED, ['copyStatus' => $status]));
			$this->assertStringNotContainsString('##', $label, "label of {$status} is translated");
		}
	}

	public function testDeliveryDetails(): void {
		$reviewer = Repo::user()->newDataObject();
		$reviewer->setGivenName('Rita', 'en');
		$reviewer->setFamilyName('<Reviewer>', 'en');
		$reviewer->setEmail('rita@example.org');

		$physical = C::getDeliveryDetails($this->assignment(Assignment::STATUS_ACCEPTED,
			['copyPreference' => 'physical', 'shippingAddress' => "Street 1\nTown", 'phone' => '123456']), $reviewer, 'en');
		$this->assertStringContainsString('Street 1<br />', $physical);
		$this->assertStringContainsString('123456', $physical);
		$this->assertStringContainsString('&lt;Reviewer&gt;', $physical, 'names are escaped');

		$electronic = C::getDeliveryDetails($this->assignment(Assignment::STATUS_ACCEPTED, ['copyPreference' => 'electronic']), $reviewer, 'en');
		$this->assertStringContainsString('&lt;rita@example.org&gt;', $electronic);
	}

	private function publisherContact() {
		$user = Repo::user()->getByUsername('publisher', true);
		if (!$user || trim((string) $user->getMailingAddress()) === '') {
			$this->markTestSkipped('Needs the dev journal\'s "publisher" account with a mailing address (dev/devSetup.php users)');
		}
		return $user;
	}
}
