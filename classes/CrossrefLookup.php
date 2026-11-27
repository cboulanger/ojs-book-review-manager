<?php

/**
 * @file plugins/generic/bookReviewManager/classes/CrossrefLookup.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CrossrefLookup
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Resolve a DOI via the Crossref REST API and map the result to
 *  the fields of the objects for review forms.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use PKP\core\JSONMessage;

class CrossrefLookup {

	const API_URL = 'https://api.crossref.org/works/';

	/**
	 * Crossref work types mapped to the COAR resource type URIs used by the forms.
	 */
	const TYPE_MAP = [
		'book' => 'http://purl.org/coar/resource_type/c_2f33',
		'monograph' => 'http://purl.org/coar/resource_type/c_2f33',
		'edited-book' => 'http://purl.org/coar/resource_type/c_2f33',
		'reference-book' => 'http://purl.org/coar/resource_type/c_2f33',
		'book-chapter' => 'http://purl.org/coar/resource_type/c_3248',
		'book-section' => 'http://purl.org/coar/resource_type/c_3248',
		'book-part' => 'http://purl.org/coar/resource_type/c_3248',
		'reference-entry' => 'http://purl.org/coar/resource_type/c_3248',
		'journal-article' => 'http://purl.org/coar/resource_type/c_6501',
		'proceedings-article' => 'http://purl.org/coar/resource_type/c_5794',
		'dissertation' => 'http://purl.org/coar/resource_type/c_46ec',
		'posted-content' => 'http://purl.org/coar/resource_type/c_816b',
		'dataset' => 'http://purl.org/coar/resource_type/c_ddb1',
	];

	/**
	 * Handle a lookup request from one of the forms (request var "doi").
	 * @param \PKP\core\PKPRequest $request
	 * @return JSONMessage On success, the content holds the form field values
	 */
	public static function handleRequest($request) {
		$doi = self::normalizeDoi($request->getUserVar('doi'));
		if (!$doi) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.doiLookup.invalid'));
		}
		try {
			return new JSONMessage(true, self::lookup($doi));
		} catch (\Exception $e) {
			return new JSONMessage(false, __($e->getMessage()));
		}
	}

	/**
	 * Normalize user input (bare DOI, "doi:" prefix or doi.org URL) to a bare DOI.
	 * @param string $input
	 * @return string|null The DOI, or null if the input is not a DOI
	 */
	public static function normalizeDoi($input) {
		$doi = trim((string) $input);
		$doi = preg_replace('#^(https?://)?(dx\.)?doi\.org/#i', '', $doi);
		$doi = preg_replace('#^doi:\s*#i', '', $doi);
		$doi = rawurldecode($doi);
		return preg_match('#^10\.\d{4,9}/\S+$#', $doi) ? $doi : null;
	}

	/**
	 * Get the resolver URL for a DOI.
	 * @param string $input A DOI in any form accepted by normalizeDoi()
	 * @return string|null https://doi.org/ URL, or null if the input is not a DOI
	 */
	public static function doiUrl($input) {
		$doi = self::normalizeDoi($input);
		return $doi ? 'https://doi.org/' . str_replace('%2F', '/', rawurlencode($doi)) : null;
	}

	/**
	 * Look up a DOI at Crossref.
	 * @param string $doi A normalized DOI
	 * @return array Form field values (identifier, title, authors, publisher, year, resourceType)
	 * @throws \Exception with a locale key as message if the lookup fails
	 */
	public static function lookup($doi) {
		try {
			$response = Application::get()->getHttpClient()->request(
				'GET',
				self::API_URL . rawurlencode($doi),
				['timeout' => 10, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (ClientException $e) {
			if ($e->getResponse() && $e->getResponse()->getStatusCode() == 404) {
				throw new \Exception('plugins.generic.bookReviewManager.doiLookup.notFound');
			}
			throw new \Exception('plugins.generic.bookReviewManager.doiLookup.failed');
		} catch (GuzzleException $e) {
			throw new \Exception('plugins.generic.bookReviewManager.doiLookup.failed');
		}

		$json = json_decode((string) $response->getBody(), true);
		if (!is_array($json) || !isset($json['message']) || !is_array($json['message'])) {
			throw new \Exception('plugins.generic.bookReviewManager.doiLookup.failed');
		}

		return self::mapWork($json['message'], $doi);
	}

	/**
	 * Map a Crossref work record to form field values.
	 * @param array $work The "message" part of a Crossref works response
	 * @param string $doi
	 * @return array
	 */
	public static function mapWork(array $work, $doi) {
		$title = self::clean($work['title'][0] ?? '');
		$subtitle = self::clean($work['subtitle'][0] ?? '');
		if ($subtitle !== '' && stripos($title, $subtitle) === false) {
			$title .= ': ' . $subtitle;
		}

		// Fall back to editors for edited volumes without authors
		$people = !empty($work['author']) ? $work['author'] : ($work['editor'] ?? []);
		$names = [];
		foreach ($people as $person) {
			if (!empty($person['family'])) {
				$names[] = trim($person['family'] . (!empty($person['given']) ? ', ' . $person['given'] : ''));
			} elseif (!empty($person['name'])) {
				$names[] = trim($person['name']);
			}
		}
		$authors = implode('; ', $names);
		if ($authors !== '' && empty($work['author'])) {
			$authors .= ' (' . __('plugins.generic.bookReviewManager.doiLookup.editorSuffix') . ')';
		}

		$year = '';
		foreach (['issued', 'published-print', 'published-online', 'created'] as $dateField) {
			if (!empty($work[$dateField]['date-parts'][0][0])) {
				$year = (string) $work[$dateField]['date-parts'][0][0];
				break;
			}
		}

		return [
			'identifier' => $doi,
			'title' => $title,
			'authors' => $authors,
			'publisher' => self::clean($work['publisher'] ?? ''),
			'year' => $year,
			'resourceType' => self::TYPE_MAP[$work['type'] ?? ''] ?? null,
			// Only evidence of open access is used; without it the status stays unknown
			'openAccess' => self::isOpenAccess($work) ? ObjectForReview::OPEN_ACCESS : null,
		];
	}

	/**
	 * Is the work open access? Crossref lists its licenses: a Creative Commons license
	 * (or public domain mark) for the published version that applies without embargo.
	 * Text and data mining licenses don't count.
	 */
	public static function isOpenAccess(array $work): bool {
		foreach ($work['license'] ?? [] as $license) {
			$url = (string) ($license['URL'] ?? '');
			$version = (string) ($license['content-version'] ?? 'unspecified');
			if (preg_match('#^https?://(www\.)?creativecommons\.org/(licenses|publicdomain)/#i', $url)
					&& in_array($version, ['vor', 'unspecified'], true)
					&& (int) ($license['delay-in-days'] ?? 0) <= 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip markup (Crossref titles may contain JATS/HTML tags) and collapse whitespace.
	 * @param string $value
	 * @return string
	 */
	protected static function clean($value) {
		$value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim(preg_replace('/\s+/u', ' ', $value));
	}
}
