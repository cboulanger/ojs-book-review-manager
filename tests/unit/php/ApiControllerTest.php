<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\api\ApiError;
use APP\plugins\generic\bookReviewManager\api\ObjectsForReviewController;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Helpers and constants of the REST controller. The endpoints themselves are covered
 * by the API E2E suite (tests/e2e/api).
 */
class ApiControllerTest extends TestCase {

	private ObjectsForReviewController $controller;

	protected function setUp(): void {
		$this->controller = new ObjectsForReviewController($this->plugin());
	}

	public function testRoutesAreUnderThePluginPath(): void {
		$this->assertSame('bookReviewManager', $this->controller->getHandlerPath());
		$middleware = $this->controller->getRouteGroupMiddleware();
		$this->assertContains('has.user', $middleware);
		$this->assertContains('has.context', $middleware);
	}

	public static function isbn13(): array {
		return [
			['0198865295', '9780198865292'],
			['026204630X', '9780262046305'],
			['9780262046305', '9780262046305'],
			[null, null],
		];
	}

	#[DataProvider('isbn13')]
	public function testIsbn13(?string $input, ?string $expected): void {
		$this->assertSame($expected, $this->invoke(ObjectsForReviewController::class, 'isbn13', $input));
	}

	public function testValidDate(): void {
		$this->assertSame('2027-02-28', $this->invoke($this->controller, 'validDate', '2027-02-28', 'dueDate'));
		foreach (['2027-02-30', '2027-2-28', '28.02.2027', '', '2027-02-28 10:00:00'] as $bad) {
			try {
				$this->invoke($this->controller, 'validDate', $bad, 'dueDate');
				$this->fail("accepted {$bad}");
			} catch (ApiError $e) {
				$this->assertSame(400, $e->status);
				$this->assertSame('invalidDate', $e->error);
				$this->assertStringContainsString('dueDate', $e->getMessage());
			}
		}
	}

	public function testValidDateTime(): void {
		$this->assertSame('2027-02-28 00:00:00', $this->invoke($this->controller, 'validDateTime', '2027-02-28', 'd'));
		$this->assertSame('2027-02-28 13:45:10', $this->invoke($this->controller, 'validDateTime', '2027-02-28 13:45:10', 'd'));
		$this->expectException(ApiError::class);
		$this->invoke($this->controller, 'validDateTime', '2027-02-28T13:45:10', 'd');
	}

	public static function booleans(): array {
		return [[true, true], [false, false], ['true', true], ['false', false], ['1', true], ['0', false], [1, true], [0, false], ['yes', true], ['no', false], ['', false]];
	}

	#[DataProvider('booleans')]
	public function testBool($input, bool $expected): void {
		$this->assertSame($expected, $this->invoke($this->controller, 'bool', $input));
	}

	public function testErrorResponse(): void {
		$response = (new ApiError(409, 'duplicateIdentifier', 'exists', ['objectId' => 3]))->response();
		$this->assertSame(409, $response->getStatusCode());
		$this->assertSame(['error' => 'duplicateIdentifier', 'errorMessage' => 'exists', 'objectId' => 3], $response->getData(true));
	}

	public function testImportableStatusesAreKnownAssignmentStatuses(): void {
		$reflection = new \ReflectionClass(Assignment::class);
		$known = array_filter($reflection->getConstants(), fn ($name) => str_starts_with($name, 'STATUS_'), ARRAY_FILTER_USE_KEY);
		foreach (ObjectsForReviewController::IMPORT_STATUSES as $status) {
			$this->assertContains($status, $known);
		}
		$this->assertNotContains(Assignment::STATUS_CANCELLED, ObjectsForReviewController::IMPORT_STATUSES);
	}

	public function testCopyStatusesMatchCopyWorkflow(): void {
		$reflection = new \ReflectionClass(CopyWorkflow::class);
		$constants = $reflection->getConstants();
		$statuses = array_values(array_filter(array_map(fn ($name) => $constants[$name],
			['NOT_NEEDED', 'PENDING', 'REQUESTED', 'SENT', 'RECEIVED', 'RETURN_REQUESTED', 'RETURN_SENT', 'RETURNED'])));
		$this->assertEqualsCanonicalizing($statuses, ObjectsForReviewController::COPY_STATUSES);
		foreach (CopyWorkflow::STEPS as $step => $definition) {
			$this->assertContains($definition['to'], ObjectsForReviewController::COPY_STATUSES, "target of {$step}");
		}
	}

	public function testResourceTypesMatchTheLookups(): void {
		foreach (\APP\plugins\generic\bookReviewManager\classes\CrossrefLookup::TYPE_MAP as $type => $uri) {
			$this->assertContains($uri, ObjectsForReviewController::RESOURCE_TYPES, $type);
		}
		$this->assertContains(\APP\plugins\generic\bookReviewManager\classes\IsbnLookup::BOOK_TYPE, ObjectsForReviewController::RESOURCE_TYPES);
		$this->assertContains(Reservation::COPY_HAVE, Reservation::COPY_PREFERENCES);
	}
}
