<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The plugin's locale files: every language has the same keys, and the history's
 * event messages exist.
 */
class LocaleTest extends TestCase {

	const LOCALES = ['en', 'de', 'fi'];

	private static function keys(string $locale, string $file = 'locale.po'): array {
		$po = file_get_contents(dirname(__DIR__, 3) . "/locale/{$locale}/{$file}");
		preg_match_all('/^msgid "(.+)"$/m', $po, $matches);
		return $matches[1];
	}

	public static function files(): array {
		return [['locale.po'], ['emails.po']];
	}

	#[DataProvider('files')]
	public function testAllLanguagesHaveTheSameKeys(string $file): void {
		$english = self::keys('en', $file);
		$this->assertNotEmpty($english);
		$this->assertSame(count($english), count(array_unique($english)), "duplicate keys in en/{$file}");
		foreach (array_diff(self::LOCALES, ['en']) as $locale) {
			$keys = self::keys($locale, $file);
			$this->assertSame([], array_values(array_diff($english, $keys)), "missing in {$locale}/{$file}");
			$this->assertSame([], array_values(array_diff($keys, $english)), "only in {$locale}/{$file}");
		}
	}

	public function testEveryHistoryEventHasAMessage(): void {
		$events = array_filter((new \ReflectionClass(ReservationLog::class))->getConstants(),
			fn ($name) => str_starts_with($name, 'EVENT_') && $name !== 'EVENT_MESSAGES', ARRAY_FILTER_USE_KEY);
		$keys = self::keys('en');
		foreach ($events as $name => $value) {
			$this->assertArrayHasKey($value, ReservationLog::EVENT_MESSAGES, "{$name} has no message");
			$this->assertContains(ReservationLog::EVENT_MESSAGES[$value], $keys, "message of {$name} is not in en/locale.po");
		}
		$this->assertSame(count($events), count(array_unique($events)), 'event codes are unique');
	}

	public function testImportedEventIsTranslated(): void {
		foreach (self::LOCALES as $locale) {
			$text = __(ReservationLog::EVENT_MESSAGES[ReservationLog::EVENT_IMPORTED], ['recipientName' => 'Rita'], $locale);
			$this->assertStringContainsString('Rita', $text, $locale);
			$this->assertStringContainsString('API', $text, $locale);
		}
	}
}
