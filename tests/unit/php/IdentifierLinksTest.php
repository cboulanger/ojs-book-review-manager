<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\IdentifierLinks;
use PHPUnit\Framework\Attributes\DataProvider;

class IdentifierLinksTest extends TestCase {

	protected function tearDown(): void {
		IdentifierLinks::reset();
		parent::tearDown();
	}

	public static function urls(): array {
		return [
			'DOI' => ['doi', '10.1515/x', 'https://doi.org/10.1515/x'],
			'DOI as URL' => ['doi', 'https://doi.org/10.1515/x', 'https://doi.org/10.1515/x'],
			'ISBN' => ['isbn', '978-0-262-04630-5', 'https://www.worldcat.org/isbn/9780262046305'],
			'invalid ISBN' => ['isbn', '123', null],
			'URI' => ['uri', 'https://example.org/w', 'https://example.org/w'],
			'URI not http' => ['uri', 'javascript:alert(1)', null],
			'PURL' => ['purl', 'http://purl.org/x/y', 'http://purl.org/x/y'],
			'handle' => ['handle', 'hdl:20.500.12345/1', 'https://hdl.handle.net/20.500.12345/1'],
			'handle invalid' => ['handle', 'nonsense', null],
			'arXiv' => ['arxiv', 'arXiv:2101.00001v2', 'https://arxiv.org/abs/2101.00001v2'],
			'arXiv old style' => ['arxiv', 'hep-th/9901001', 'https://arxiv.org/abs/hep-th/9901001'],
			'PMID' => ['pmid', '12345678', 'https://pubmed.ncbi.nlm.nih.gov/12345678/'],
			'PMCID' => ['pmcid', 'pmc123456', 'https://www.ncbi.nlm.nih.gov/pmc/articles/PMC123456/'],
			'ISSN' => ['issn', '0028-0836', 'https://portal.issn.org/resource/ISSN/0028-0836'],
			'ISSN with check digit X' => ['issn', '2049363x', 'https://portal.issn.org/resource/ISSN/2049-363X'],
			'URN:NBN' => ['urn', 'urn:nbn:de:101-2020', 'https://nbn-resolving.org/urn:nbn:de:101-2020'],
			'other URN' => ['urn', 'urn:isbn:9780262046305', null],
			'ARK' => ['ark', 'ark:/12148/btv1b8449691v', 'https://n2t.net/ark:/12148/btv1b8449691v'],
			'upper-case type' => ['DOI', '10.1515/x', 'https://doi.org/10.1515/x'],
			'no resolver' => ['other', 'abc', null],
			'empty' => ['doi', '', null],
		];
	}

	#[DataProvider('urls')]
	public function testUrl(string $type, string $identifier, ?string $expected): void {
		$this->assertSame($expected, IdentifierLinks::url($type, $identifier));
	}

	public function testLabel(): void {
		$this->assertSame('isbn: 9780262046305', IdentifierLinks::label('ISBN', ' 9780262046305 '));
		$this->assertSame('abc', IdentifierLinks::label('', 'abc'));
		$this->assertSame('', IdentifierLinks::label('isbn', ''));
	}

	public function testHtml(): void {
		$this->assertSame('doi: <a href="https://doi.org/10.1515/x" target="_blank" rel="noopener noreferrer">10.1515/x</a>',
			IdentifierLinks::html('doi', '10.1515/x'));
		$this->assertSame('other: &lt;b&gt;', IdentifierLinks::html('other', '<b>'));
		$this->assertSame('', IdentifierLinks::html('doi', null));
	}

	public function testRegister(): void {
		$this->assertFalse(IdentifierLinks::isLinkable('ecli'));
		IdentifierLinks::register('ECLI', fn (string $id) => 'https://e-justice.europa.eu/ecli/' . $id);
		$this->assertTrue(IdentifierLinks::isLinkable('ecli'));
		$this->assertSame('https://e-justice.europa.eu/ecli/ECLI:EU:C:2014:317', IdentifierLinks::url('ecli', 'ECLI:EU:C:2014:317'));
	}

	public function testResolverMayNotReturnNonWebUrls(): void {
		IdentifierLinks::register('x', fn (string $id) => 'javascript:' . $id);
		$this->assertNull(IdentifierLinks::url('x', 'alert(1)'));
	}

	public function testUnregisterAndReset(): void {
		IdentifierLinks::unregister('isbn');
		$this->assertNull(IdentifierLinks::url('isbn', '9780262046305'));
		IdentifierLinks::reset();
		$this->assertNotNull(IdentifierLinks::url('isbn', '9780262046305'));
	}
}
