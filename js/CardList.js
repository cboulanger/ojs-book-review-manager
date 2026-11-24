/**
 * @file plugins/generic/bookReviewManager/js/CardList.js
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Sorting of card lists (templates/cardListHeader.tpl, templates/card.tpl): a click on a
 * button of the header sorts the list's cards by their data-sort-<key> value, a second click
 * reverses the order. Values are compared as text (natural order, so numbers and ISO dates
 * sort correctly); cards without a value come last in both directions.
 */
(function () {
	'use strict';

	var collator = new Intl.Collator(document.documentElement.lang || undefined, {numeric: true, sensitivity: 'base'});

	function sortCards(button) {
		var list = button.closest('.ofrCardList');
		if (!list) {
			return;
		}
		var key = button.getAttribute('data-sort-key').toLowerCase();
		var descending = button.getAttribute('aria-sort') === 'ascending';
		list.querySelectorAll('.ofrCardSort').forEach(function (other) {
			other.removeAttribute('aria-sort');
		});
		button.setAttribute('aria-sort', descending ? 'descending' : 'ascending');

		var cards = Array.prototype.slice.call(list.querySelectorAll(':scope > .ofrCard'));
		var value = function (card) {
			return (card.getAttribute('data-sort-' + key) || '').trim();
		};
		cards
			.map(function (card, index) {
				return {card: card, value: value(card), index: index};
			})
			.sort(function (a, b) {
				if (!a.value !== !b.value) {
					return a.value ? -1 : 1;
				}
				var result = collator.compare(a.value, b.value);
				return (descending ? -result : result) || a.index - b.index;
			})
			.forEach(function (item) {
				list.appendChild(item.card);
			});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('.ofrCardSort');
		if (button) {
			sortCards(button);
		}
	});
})();
