<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\CrossrefLookup;
use PHPUnit\Framework\Attributes\DataProvider;

class CrossrefLookupTest extends TestCase {

	const BOOK = 'http://purl.org/coar/resource_type/c_2f33';

	public static function dois(): array {
		return [
			'bare' => ['10.1515/9783110732870', '10.1515/9783110732870'],
			'doi: prefix' => ['doi:10.1515/9783110732870', '10.1515/9783110732870'],
			'DOI: prefix with space' => ['DOI: 10.1515/9783110732870', '10.1515/9783110732870'],
			'https resolver' => ['https://doi.org/10.1515/9783110732870', '10.1515/9783110732870'],
			'http dx resolver' => ['http://dx.doi.org/10.1515/9783110732870', '10.1515/9783110732870'],
			'resolver without scheme' => ['doi.org/10.1515/9783110732870', '10.1515/9783110732870'],
			'url-encoded' => ['https://doi.org/10.1002%2F%28SICI%291097', '10.1002/(SICI)1097'],
			'surrounding spaces' => ['  10.1000/xyz  ', '10.1000/xyz'],
			'registrant too short' => ['10.123/abc', null],
			'no suffix' => ['10.1515/', null],
			'not a DOI' => ['9783110732870', null],
			'other URL' => ['https://example.org/10.1515/x', null],
		];
	}

	#[DataProvider('dois')]
	public function testNormalizeDoi(string $input, ?string $expected): void {
		$this->assertSame($expected, CrossrefLookup::normalizeDoi($input));
	}

	public function testDoiUrlEncodesButKeepsSlashes(): void {
		$this->assertSame('https://doi.org/10.1515/9783110732870', CrossrefLookup::doiUrl('doi:10.1515/9783110732870'));
		$this->assertSame('https://doi.org/10.1002/%28SICI%291097', CrossrefLookup::doiUrl('10.1002/(SICI)1097'));
		$this->assertNull(CrossrefLookup::doiUrl('no doi'));
	}

	public function testMapWorkWithAuthorsAndSubtitle(): void {
		$data = CrossrefLookup::mapWork([
			'title' => ['Goethe <i>medial</i>'],
			'subtitle' => ['Aspekte einer  Beziehung'],
			'author' => [['family' => 'Wyder', 'given' => 'Margrit'], ['name' => 'A Consortium'], ['family' => 'Solo']],
			'publisher' => 'De Gruyter',
			'issued' => ['date-parts' => [[2021, 3]]],
			'type' => 'monograph',
		], '10.1515/x');
		$this->assertSame([
			'identifier' => '10.1515/x',
			'title' => 'Goethe medial: Aspekte einer Beziehung',
			'authors' => 'Wyder, Margrit; A Consortium; Solo',
			'publisher' => 'De Gruyter',
			'year' => '2021',
			'resourceType' => self::BOOK,
			'openAccess' => null,
		], $data);
	}

	public static function licenses(): array {
		$cc = 'https://creativecommons.org/licenses/by/4.0';
		return [
			'CC BY, published version' => [[['URL' => $cc, 'content-version' => 'vor', 'delay-in-days' => 0]], true],
			'CC without version' => [[['URL' => 'http://creativecommons.org/licenses/by-nc-nd/4.0/']], true],
			'public domain mark' => [[['URL' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'content-version' => 'unspecified']], true],
			'CC after an embargo' => [[['URL' => $cc, 'content-version' => 'vor', 'delay-in-days' => 365]], false],
			'CC for text and data mining only' => [[['URL' => $cc, 'content-version' => 'tdm']], false],
			'CC for the accepted manuscript only' => [[['URL' => $cc, 'content-version' => 'am']], false],
			'publisher license' => [[['URL' => 'https://www.springer.com/tdm', 'content-version' => 'tdm']], false],
			'lookalike host' => [[['URL' => 'https://creativecommons.org.example.com/licenses/by/4.0']], false],
			'no license' => [[], false],
			'CC among others' => [[['URL' => 'https://www.springer.com/tdm', 'content-version' => 'tdm'], ['URL' => $cc, 'content-version' => 'vor']], true],
		];
	}

	#[DataProvider('licenses')]
	public function testIsOpenAccess(array $licenses, bool $expected): void {
		$this->assertSame($expected, CrossrefLookup::isOpenAccess(['license' => $licenses]));
		$this->assertSame($expected ? 'open' : null, CrossrefLookup::mapWork(['license' => $licenses], '10.1/x')['openAccess']);
	}

	public function testMapWorkFallsBackToEditors(): void {
		$data = CrossrefLookup::mapWork([
			'title' => ['Edited Volume'],
			'editor' => [['family' => 'Felten', 'given' => 'Georges']],
			'type' => 'edited-book',
		], '10.1/x');
		$this->assertSame('Felten, Georges (Ed.)', $data['authors']);
	}

	public function testMapWorkDoesNotRepeatSubtitleAlreadyInTitle(): void {
		$data = CrossrefLookup::mapWork(['title' => ['Title: The Subtitle'], 'subtitle' => ['The Subtitle']], '10.1/x');
		$this->assertSame('Title: The Subtitle', $data['title']);
	}

	public function testMapWorkYearFallbackOrder(): void {
		$this->assertSame('2019', CrossrefLookup::mapWork(['published-print' => ['date-parts' => [[2019]]], 'created' => ['date-parts' => [[2018]]]], '10.1/x')['year']);
		$this->assertSame('2018', CrossrefLookup::mapWork(['created' => ['date-parts' => [[2018]]]], '10.1/x')['year']);
		$this->assertSame('', CrossrefLookup::mapWork([], '10.1/x')['year']);
	}

	public static function types(): array {
		return [
			['book', self::BOOK],
			['book-chapter', 'http://purl.org/coar/resource_type/c_3248'],
			['journal-article', 'http://purl.org/coar/resource_type/c_6501'],
			['proceedings-article', 'http://purl.org/coar/resource_type/c_5794'],
			['dissertation', 'http://purl.org/coar/resource_type/c_46ec'],
			['posted-content', 'http://purl.org/coar/resource_type/c_816b'],
			['dataset', 'http://purl.org/coar/resource_type/c_ddb1'],
			['peer-review', null],
		];
	}

	#[DataProvider('types')]
	public function testMapWorkResourceType(string $crossrefType, ?string $expected): void {
		$this->assertSame($expected, CrossrefLookup::mapWork(['type' => $crossrefType], '10.1/x')['resourceType']);
	}
}
