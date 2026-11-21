<?php

/**
 * Base class of the plugin's PHP unit tests: factories for data objects and a fake plugin.
 */

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** Context id of the dev journal */
	const CONTEXT_ID = 1;

	/**
	 * A work offered for review (not saved).
	 * @param array $data Data object fields, e.g. ['assignmentMode' => 'bidding']
	 */
	protected function object(array $data = []): ObjectForReview {
		$object = new ObjectForReview();
		$object->setAllData(array_merge([
			'objectId' => 1,
			'contextId' => self::CONTEXT_ID,
			'identifier' => '9780262046305',
			'identifierType' => 'isbn',
			'resourceType' => 'http://purl.org/coar/resource_type/c_2f33',
			'title' => 'A Title',
		], $data));
		return $object;
	}

	/**
	 * An assignment (not saved).
	 */
	protected function assignment(string $status, array $data = []): Assignment {
		$assignment = new Assignment();
		$assignment->setAllData(array_merge([
			'id' => random_int(1000, 9999),
			'objectId' => 1,
			'contextId' => self::CONTEXT_ID,
			'userId' => 2,
			'status' => $status,
		], $data));
		return $assignment;
	}

	protected function plugin(array $settings = []): FakePlugin {
		return new FakePlugin($settings);
	}

	/** Call a protected or private method */
	protected function invoke(object|string $target, string $method, ...$args) {
		$reflection = new \ReflectionMethod($target, $method);
		return $reflection->invoke(is_object($target) ? $target : null, ...$args);
	}
}

/**
 * Stands in for BookReviewManagerPlugin where only its settings are used.
 */
class FakePlugin {
	public array $updated = [];

	public function __construct(public array $settings = []) {
	}

	public function getSetting($contextId, $name) {
		return $this->settings[$name] ?? null;
	}

	public function updateSetting($contextId, $name, $value, $type = null) {
		$this->settings[$name] = $value;
		$this->updated[$name] = $value;
	}

	public function notifyEditor(...$args) {
	}
}
