{**
 * plugins/generic/bookReviewManager/templates/identifierLookup.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * "Look up DOI/ISBN" button for the objects for review forms. Fetches metadata
 * via the grid handler's lookupDoi (Crossref) or lookupIsbn (Crossref,
 * Open Library) op and fills the form fields. Shown for the identifier types DOI and ISBN.
 *
 * Parameters:
 *   $doiLookupUrl   URL of the lookupDoi op
 *   $isbnLookupUrl  URL of the lookupIsbn op
 *}
{assign var=idLookupId value="orIdLookup"|concat:"-"|uniqid}
<span class="orIdLookup" id="{$idLookupId|escape}"
	data-doi-url="{$doiLookupUrl|escape}"
	data-doi-label="{translate|escape key="plugins.generic.bookReviewManager.doiLookup.button"}"
	data-doi-loading="{translate|escape key="plugins.generic.bookReviewManager.doiLookup.loading"}"
	data-doi-done="{translate|escape key="plugins.generic.bookReviewManager.doiLookup.done"}"
	data-isbn-url="{$isbnLookupUrl|escape}"
	data-isbn-label="{translate|escape key="plugins.generic.bookReviewManager.isbnLookup.button"}"
	data-isbn-loading="{translate|escape key="plugins.generic.bookReviewManager.isbnLookup.loading"}"
	data-msg-failed="{translate|escape key="plugins.generic.bookReviewManager.isbnLookup.failed"}">
	<button type="button" class="pkp_button orIdLookupButton"></button>
	<span class="orIdLookupStatus" role="status" aria-live="polite"></span>
</span>
<script>
	(function($box) {ldelim}{literal}
		var $form = $box.closest('form'),
			$button = $box.find('.orIdLookupButton'),
			$status = $box.find('.orIdLookupStatus'),
			$type = $form.find('[name="identifierType"]');

		function currentType() {
			var type = String($type.val()).toLowerCase();
			return (type === 'doi' || type === 'isbn') ? type : null;
		}

		function field(name) {
			return $form.find('[name="' + name + '"]');
		}

		function setValue(name, value) {
			if (value) {
				field(name).val(value).trigger('change');
			}
		}

		function toggle() {
			var type = currentType();
			$box.toggle(!!type);
			if (type) {
				$button.text($box.data(type + 'Label'));
			}
			$status.text('');
		}
		$type.on('change', toggle);
		toggle();

		$button.on('click', function() {
			var type = currentType(), params = {};
			if (!type) {
				return;
			}
			params[type] = field('identifier').val();
			$button.prop('disabled', true);
			$status.text($box.data(type + 'Loading'));
			$.getJSON($box.data(type + 'Url'), params)
				.done(function(json) {
					if (!json || !json.status) {
						$status.text((json && json.content) || $box.data('msgFailed'));
						return;
					}
					var data = json.content;
					setValue('identifier', data.identifier);
					setValue('resourceType', data.resourceType);
					setValue('authors', data.authors);
					setValue('title', data.title);
					setValue('publisher', data.publisher);
					setValue('year', data.year);
					$status.text(data.message || $box.data(type + 'Done'));
				})
				.fail(function() {
					$status.text($box.data('msgFailed'));
				})
				.always(function() {
					$button.prop('disabled', false);
				});
		});
	{/literal}{rdelim})($('#{$idLookupId|escape:"javascript"}'));
</script>
