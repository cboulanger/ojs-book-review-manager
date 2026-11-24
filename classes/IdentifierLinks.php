<?php

/**
 * @file plugins/generic/bookReviewManager/classes/IdentifierLinks.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IdentifierLinks
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Map an identifier (type + value) to the URL of the external resource it
 *  identifies, and display it as "type: identifier", linked where possible.
 *
 *  Extensible: register(type, resolver) adds or replaces the resolver of a type,
 *  e.g. IdentifierLinks::register('ecli', fn ($id) => 'https://...' . $id).
 *  A resolver gets the trimmed identifier and returns a URL or null.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

class IdentifierLinks {

	/** @var array<string, callable(string): ?string>|null type => resolver */
	private static ?array $resolvers = null;

	/**
	 * Add or replace the resolver of an identifier type (case-insensitive).
	 * @param callable(string): ?string $resolver
	 */
	public static function register(string $type, callable $resolver): void {
		self::resolvers();
		self::$resolvers[strtolower(trim($type))] = $resolver;
	}

	/** Remove the resolver of an identifier type. */
	public static function unregister(string $type): void {
		self::resolvers();
		unset(self::$resolvers[strtolower(trim($type))]);
	}

	/** Back to the built-in resolvers. */
	public static function reset(): void {
		self::$resolvers = null;
	}

	/** Whether identifiers of this type can be linked. */
	public static function isLinkable(string $type): bool {
		return isset(self::resolvers()[strtolower(trim($type))]);
	}

	/**
	 * URL of the resource the identifier identifies, or null if it can't be linked
	 * (unknown type, or an identifier the type's resolver doesn't accept).
	 */
	public static function url(?string $type, ?string $identifier): ?string {
		$identifier = trim((string) $identifier);
		$resolver = self::resolvers()[strtolower(trim((string) $type))] ?? null;
		if ($identifier === '' || !$resolver) {
			return null;
		}
		$url = $resolver($identifier);
		// Never link anything but web URLs (no javascript: etc.)
		return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
	}

	/** Plain text "type: identifier" ("identifier" without a type, "" without an identifier). */
	public static function label(?string $type, ?string $identifier): string {
		$type = strtolower(trim((string) $type));
		$identifier = trim((string) $identifier);
		if ($identifier === '') {
			return '';
		}
		return $type === '' ? $identifier : $type . ': ' . $identifier;
	}

	/** HTML (escaped) "type: identifier", the identifier linked to its resource where possible. */
	public static function html(?string $type, ?string $identifier): string {
		$identifier = trim((string) $identifier);
		if ($identifier === '') {
			return '';
		}
		$type = strtolower(trim((string) $type));
		$text = htmlspecialchars($identifier, ENT_QUOTES);
		$url = self::url($type, $identifier);
		if ($url) {
			$text = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
		}
		return $type === '' ? $text : htmlspecialchars($type, ENT_QUOTES) . ': ' . $text;
	}

	/** @return array<string, callable(string): ?string> */
	private static function resolvers(): array {
		if (self::$resolvers === null) {
			self::$resolvers = self::builtInResolvers();
		}
		return self::$resolvers;
	}

	/** @return array<string, callable(string): ?string> */
	private static function builtInResolvers(): array {
		$web = fn (string $id): ?string => preg_match('#^https?://\S+$#i', $id) ? $id : null;
		$strip = fn (string $prefix, string $id): string => preg_replace('#^' . $prefix . '\s*#i', '', $id);
		return [
			'doi' => fn (string $id): ?string => CrossrefLookup::doiUrl($id),
			'isbn' => fn (string $id): ?string => IsbnLookup::worldcatUrl($id),
			// "Link" in the forms; "link" in older data
			'uri' => $web,
			'link' => $web,
			'purl' => $web,
			'handle' => function (string $id) use ($strip): ?string {
				$id = $strip('(https?://hdl\.handle\.net/|hdl:)', $id);
				return preg_match('#^\d[\d.]*/\S+$#', $id) ? 'https://hdl.handle.net/' . $id : null;
			},
			'arxiv' => function (string $id) use ($strip): ?string {
				$id = $strip('(https?://arxiv\.org/abs/|arxiv:)', $id);
				return preg_match('#^(\d{4}\.\d{4,5}|[a-z-]+(\.[A-Z]{2})?/\d{7})(v\d+)?$#i', $id) ? 'https://arxiv.org/abs/' . $id : null;
			},
			'pmid' => function (string $id) use ($strip): ?string {
				$id = $strip('pmid:', $id);
				return preg_match('#^\d+$#', $id) ? 'https://pubmed.ncbi.nlm.nih.gov/' . $id . '/' : null;
			},
			'pmcid' => function (string $id) use ($strip): ?string {
				$id = strtoupper($strip('pmcid:', $id));
				if (preg_match('#^\d+$#', $id)) {
					$id = 'PMC' . $id;
				}
				return preg_match('#^PMC\d+$#', $id) ? 'https://www.ncbi.nlm.nih.gov/pmc/articles/' . $id . '/' : null;
			},
			'issn' => function (string $id) use ($strip): ?string {
				$id = strtoupper(preg_replace('#[^0-9xX]#', '', $strip('issn:', $id)));
				return strlen($id) === 8 ? 'https://portal.issn.org/resource/ISSN/' . substr($id, 0, 4) . '-' . substr($id, 4) : null;
			},
			// Only national bibliography numbers have a common resolver
			'urn' => fn (string $id): ?string => preg_match('#^urn:nbn:\S+$#i', $id) ? 'https://nbn-resolving.org/' . $id : null,
			'ark' => function (string $id): ?string {
				$id = preg_replace('#^ark:/?#i', '', $id);
				return preg_match('#^\d+/\S+$#', $id) ? 'https://n2t.net/ark:/' . $id : null;
			},
		];
	}
}
