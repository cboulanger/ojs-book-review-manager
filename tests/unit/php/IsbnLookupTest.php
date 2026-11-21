<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\IsbnLookup;
use PHPUnit\Framework\Attributes\DataProvider;

class IsbnLookupTest extends TestCase {

	public static function isbns(): array {
		return [
			'ISBN-13 with hyphens' => ['978-0-262-04630-5', '9780262046305'],
			'ISBN-13 with spaces' => ['978 0 262 04630 5', '9780262046305'],
			'ISBN-13 with prefix' => ['ISBN 978-0-262-04630-5', '9780262046305'],
			'ISBN-13: prefix' => ['ISBN-13: 9780262046305', '9780262046305'],
			'979 prefix' => ['979-10-90636-07-1', '9791090636071'],
			'ISBN-10' => ['0-19-886529-5', '0198865295'],
			'ISBN-10 with X' => ['0-262-04630-X', '026204630X'],
			'ISBN-10 with lower-case x' => ['026204630x', '026204630X'],
			'ISBN-13 wrong check digit' => ['9780262046306', null],
			'ISBN-10 wrong check digit' => ['0198865292', null],
			'too short' => ['978026204630', null],
			'not 978/979' => ['9770262046305', null],
			'X in ISBN-13' => ['978026204630X', null],
			'empty' => ['', null],
			'text' => ['not an isbn', null],
		];
	}

	#[DataProvider('isbns')]
	public function testNormalizeIsbn(string $input, ?string $expected): void {
		$this->assertSame($expected, IsbnLookup::normalizeIsbn($input));
	}

	public static function names(): array {
		return [
			'given family' => ['Alfons Bora', 'Bora, Alfons'],
			'middle initial' => ['Thomas H. Cormen', 'Cormen, Thomas H.'],
			'particle' => ['Teun A. van Dijk', 'van Dijk, Teun A.'],
			'two particles' => ['Ludwig van der Rohe', 'van der Rohe, Ludwig'],
			'already inverted' => ['Bora, Alfons', 'Bora, Alfons'],
			'single name' => ['Plato', 'Plato'],
			'annotated' => ['Jane Doe (ed.)', 'Jane Doe (ed.)'],
			'Chinese' => ['张伟', '张伟'],
			'markup and spaces' => ['  <b>Alfons</b>   Bora ', 'Bora, Alfons'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('names')]
	public function testInvertName(string $input, string $expected): void {
		$this->assertSame($expected, IsbnLookup::invertName($input));
	}

	public function testWorldcatUrl(): void {
		$this->assertSame('https://www.worldcat.org/isbn/9780262046305', IsbnLookup::worldcatUrl('978-0-262-04630-5'));
		$this->assertNull(IsbnLookup::worldcatUrl('nonsense'));
	}
}
