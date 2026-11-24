<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewGridCellProvider;
use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewManagementGridCellProvider;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridRow;

/**
 * The grids show the identifier ("type: identifier", linked) in the description column.
 */
class GridCellProviderTest extends TestCase {

	private function cell(object $provider, array $data, string $columnId = 'description'): string {
		$row = new GridRow();
		$row->setData($data);
		return $provider->getTemplateVarsFromRowColumn($row, new GridColumn($columnId, 'x'))['label'];
	}

	private function data(array $overrides = []): array {
		$work = $this->object();
		return $overrides + [
			'description' => 'Cormen: A <script>x</script>Title',
			'identifierHtml' => $work->getIdentifierHtml(),
			'essayRole' => null,
		];
	}

	public function testManagementGridDescriptionHasLinkedIdentifier(): void {
		$html = $this->cell(new ObjectsForReviewManagementGridCellProvider(), $this->data());
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('<div class="ofrIdentifier">isbn: <a href="https://www.worldcat.org/isbn/', $html);
	}

	public function testManagementGridWithoutIdentifier(): void {
		$html = $this->cell(new ObjectsForReviewManagementGridCellProvider(), $this->data(['identifierHtml' => '']));
		$this->assertStringNotContainsString('ofrIdentifier', $html);
	}

	public function testSubmissionGridDescriptionHasLinkedIdentifier(): void {
		$html = $this->cell(new ObjectsForReviewGridCellProvider(), $this->data());
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('<div class="ofrIdentifier">isbn: <a href="https://www.worldcat.org/isbn/', $html);
	}
}
