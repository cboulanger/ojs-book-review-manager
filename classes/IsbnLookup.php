<?php

/**
 * @file plugins/generic/bookReviewManager/classes/IsbnLookup.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IsbnLookup
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Resolve an ISBN to book metadata (Crossref, which has the publishers'
 *  data for academic books, with Open Library as fallback) and build WorldCat
 *  links for ISBNs.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use GuzzleHttp\Exception\GuzzleException;
use PKP\core\JSONMessage;

class IsbnLookup {

	const OPEN_LIBRARY_URL = 'https://openlibrary.org/api/books';
	const CROSSREF_URL = 'https://api.crossref.org/works';
	const WORLDCAT_URL = 'https://www.worldcat.org/isbn/';
	const BOOK_TYPE = 'http://purl.org/coar/resource_type/c_2f33';

	/** Lowercase name particles that belong to the family name ("van Dijk", "von Savigny") */
	const NAME_PARTICLES = ['van', 'von', 'de', 'der', 'den', 'du', 'da', 'di', 'del', 'la', 'le', 'zu', 'ten', 'ter'];

	/**
	 * Handle a lookup request from one of the forms (request var "isbn").
	 * @param \PKP\core\PKPRequest $request
	 * @return JSONMessage On success, the content holds the form field values and a message
	 */
	public static function handleRequest($request) {
		$isbn = self::normalizeIsbn($request->getUserVar('isbn'));
		if (!$isbn) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.isbnLookup.invalid'));
		}
		try {
			$data = self::lookup($isbn);
		} catch (\Exception $e) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.isbnLookup.failed'));
		}
		if (!$data) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.isbnLookup.notFound'));
		}
		$data['message'] = __('plugins.generic.bookReviewManager.isbnLookup.done', ['source' => $data['source']]);
		return new JSONMessage(true, $data);
	}

	/**
	 * Normalize an ISBN-10 or ISBN-13 (hyphens, spaces and an "ISBN" prefix are removed).
	 * @return string|null The ISBN digits (with a final "X" for ISBN-10), or null if invalid
	 */
	public static function normalizeIsbn($input) {
		$isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', preg_replace('/^\s*ISBN(-1[03])?:?/i', '', (string) $input)));
		if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
			$sum = 0;
			for ($i = 0; $i < 10; $i++) {
				$sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
			}
			return $sum % 11 === 0 ? $isbn : null;
		}
		if (preg_match('/^97[89]\d{10}$/', $isbn)) {
			$sum = 0;
			for ($i = 0; $i < 13; $i++) {
				$sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1);
			}
			return $sum % 10 === 0 ? $isbn : null;
		}
		return null;
	}

	/**
	 * WorldCat URL for an ISBN.
	 * @return string|null
	 */
	public static function worldcatUrl($input) {
		$isbn = self::normalizeIsbn($input);
		return $isbn ? self::WORLDCAT_URL . $isbn : null;
	}

	/**
	 * Look up an ISBN.
	 * @param string $isbn A normalized ISBN
	 * @return array|null Form field values plus "source", or null if every source answered and none has it
	 * @throws \Exception if a source could not be queried and the others don't have it (e.g. Crossref
	 *   rate-limited): "not found" would be wrong then, and the caller can try again later
	 */
	public static function lookup($isbn) {
		$failures = 0;
		foreach (['fromCrossref', 'fromOpenLibrary'] as $source) {
			try {
				if ($data = self::$source($isbn)) {
					return $data + ['identifier' => $isbn, 'resourceType' => self::BOOK_TYPE];
				}
			} catch (GuzzleException $e) {
				$failures++;
			}
		}
		if ($failures > 0) {
			throw new \Exception('ISBN lookup failed');
		}
		return null;
	}

	protected static function getJson($url, array $query) {
		$response = Application::get()->getHttpClient()->request('GET', $url, [
			'query' => $query,
			'timeout' => 10,
			'headers' => ['Accept' => 'application/json'],
		]);
		return json_decode((string) $response->getBody(), true);
	}

	protected static function fromOpenLibrary($isbn) {
		$json = self::getJson(self::OPEN_LIBRARY_URL, ['bibkeys' => 'ISBN:' . $isbn, 'format' => 'json', 'jscmd' => 'data']);
		$book = $json['ISBN:' . $isbn] ?? null;
		if (!is_array($book) || empty($book['title'])) {
			return null;
		}
		$title = self::clean($book['title']);
		if (!empty($book['subtitle'])) {
			$title .= ': ' . self::clean($book['subtitle']);
		}
		$authors = array_map(fn ($author) => self::invertName($author['name'] ?? ''), $book['authors'] ?? []);
		preg_match('/\b(1[5-9]|20)\d{2}\b/', (string) ($book['publish_date'] ?? ''), $year);
		return [
			'title' => $title,
			'authors' => implode('; ', array_filter($authors)),
			'publisher' => self::clean($book['publishers'][0]['name'] ?? ''),
			'year' => $year[0] ?? '',
			'source' => 'Open Library',
		];
	}

	protected static function fromCrossref($isbn) {
		// Without the type filter the ISBN also matches every chapter of the book
		$json = self::getJson(self::CROSSREF_URL, [
			'filter' => 'isbn:' . $isbn . ',type:book,type:monograph,type:edited-book,type:reference-book',
			'rows' => 1,
		]);
		$work = $json['message']['items'][0] ?? null;
		if (!is_array($work)) {
			return null;
		}
		$data = CrossrefLookup::mapWork($work, $isbn);
		unset($data['identifier'], $data['resourceType']);
		return $data + ['source' => 'Crossref'];
	}

	/**
	 * "Alfons Bora" -> "Bora, Alfons"; "Teun A. van Dijk" -> "van Dijk, Teun A."
	 */
	public static function invertName($name) {
		$name = self::clean($name);
		// Already inverted, annotated, or in a script that puts the family name first
		if ($name === '' || preg_match('/[,()\[\]]|\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $name)) {
			return $name;
		}
		$parts = explode(' ', $name);
		if (count($parts) < 2) {
			return $name;
		}
		$family = [array_pop($parts)];
		while (count($parts) > 1 && in_array(end($parts), self::NAME_PARTICLES, true)) {
			array_unshift($family, array_pop($parts));
		}
		return implode(' ', $family) . ', ' . implode(' ', $parts);
	}

	protected static function clean($value) {
		return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
	}
}
