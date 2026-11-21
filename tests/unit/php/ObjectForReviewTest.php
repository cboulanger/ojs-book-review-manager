<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use PHPUnit\Framework\Attributes\DataProvider;

class ObjectForReviewTest extends TestCase {

	public function testDefaults(): void {
		$object = new ObjectForReview();
		$this->assertSame(ObjectForReview::MODE_DIRECT, $object->getAssignmentMode());
		$this->assertFalse($object->isBidding());
		$this->assertFalse($object->isHidden());
		$this->assertTrue($object->isReturnRequired(), 'offers from before the review copy workflow require returns');
		$this->assertNull($object->getPublisherContactId());
		$this->assertNull($object->getResponsibleEditorId());
		$this->assertNull($object->getId());
	}

	public function testOfferSettings(): void {
		$object = $this->object(['assignmentMode' => 'bidding', 'returnRequired' => false, 'publisherContactId' => '5', 'responsibleEditorId' => '3', 'hidden' => 1]);
		$this->assertTrue($object->isBidding());
		$this->assertFalse($object->isReturnRequired());
		$this->assertSame(5, $object->getPublisherContactId());
		$this->assertSame(3, $object->getResponsibleEditorId());
		$this->assertTrue($object->isHidden());
	}

	public function testUnknownModeIsDirect(): void {
		$this->assertSame(ObjectForReview::MODE_DIRECT, $this->object(['assignmentMode' => 'auction'])->getAssignmentMode());
	}

	public function testSetOfferSettingUnsetsEmptyValues(): void {
		$object = $this->object(['hidden' => true]);
		foreach ([null, '', false] as $empty) {
			$object->setOfferSetting('hidden', true);
			$object->setOfferSetting('hidden', $empty);
			$this->assertFalse($object->hasData('hidden'), var_export($empty, true) . ' removes the setting');
		}
		$object->setOfferSetting('bidDeadline', '2027-01-01');
		$this->assertSame('2027-01-01', $object->getData('bidDeadline'));
	}

	public function testCitation(): void {
		$this->assertSame('Cormen, Thomas: A Title. MIT Press. 2022.',
			$this->object(['authors' => 'Cormen, Thomas', 'publisher' => 'MIT Press', 'year' => '2022'])->getCitation());
		$this->assertSame('A Title', $this->object()->getCitation());
		$this->assertSame('A Title. 2022.', $this->object(['year' => '2022'])->getCitation());
	}

	public static function identifierUrls(): array {
		return [
			'DOI' => ['doi', 'doi:10.1515/x', 'https://doi.org/10.1515/x'],
			'ISBN' => ['isbn', '978-0-262-04630-5', 'https://www.worldcat.org/isbn/9780262046305'],
			'invalid ISBN' => ['isbn', '123', null],
			'link (as saved by the forms)' => ['uri', 'https://example.org/work', 'https://example.org/work'],
			'link (older data)' => ['link', 'https://example.org/work', 'https://example.org/work'],
			'upper-case type' => ['ISBN', '9780262046305', 'https://www.worldcat.org/isbn/9780262046305'],
			'link not http' => ['uri', 'javascript:alert(1)', null],
			'link without scheme' => ['link', 'example.org/work', null],
			'other type' => ['handle', '20.500.12345/1', null],
		];
	}

	#[DataProvider('identifierUrls')]
	public function testIdentifierUrl(string $type, string $identifier, ?string $expected): void {
		$this->assertSame($expected, $this->object(['identifierType' => $type, 'identifier' => $identifier])->getIdentifierUrl());
	}

	public function testAssignmentStatusGroups(): void {
		foreach (Assignment::BIDDER_STATUSES as $status) {
			$this->assertTrue($this->assignment($status)->isBidder(), $status);
		}
		foreach (Assignment::REVIEWER_STATUSES as $status) {
			$this->assertTrue($this->assignment($status)->isReviewer(), $status);
			$this->assertFalse($this->assignment($status)->isBidder(), $status);
		}
		foreach (Assignment::INVITATION_STATUSES as $status) {
			$this->assertTrue($this->assignment($status)->isInvitee(), $status);
			$this->assertFalse($this->assignment($status)->isReviewer(), $status);
		}
		$this->assertFalse($this->assignment(Assignment::STATUS_OFFERED)->isBidder());
		$this->assertFalse($this->assignment(Assignment::STATUS_OFFERED)->isReviewer());
	}

	public function testReviewEssayFlags(): void {
		$container = $this->object(['resourceType' => ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY, 'identifier' => '', 'identifierType' => '']);
		$this->assertTrue($container->isReviewEssay());
		$this->assertNull($container->getBundleId());
		$member = $this->object(['bundleId' => '7']);
		$this->assertFalse($member->isReviewEssay());
		$this->assertTrue($member->isBundleMember());
		$this->assertSame(7, $member->getBundleId());
	}
}
