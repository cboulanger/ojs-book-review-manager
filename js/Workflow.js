/**
 * @file plugins/generic/bookReviewManager/js/Workflow.js
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2000-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief OJS 3.5+: add an "Objects for review" item to the publication menu of the
 *   Vue-based workflow page and render the plugin's (legacy) grid in it.
 *
 *   The Template::Workflow::Publication hook used up to OJS 3.4 was removed in 3.5,
 *   see pkp/pkp-lib#10766. Configuration (grid URL, label) is provided by the plugin
 *   in window.pkpBookReviewManager.
 */
(function () {
	if (typeof pkp === 'undefined' || !pkp.registry || !pkp.registry.storeExtend) {
		return; // OJS < 3.5
	}

	var MENU_KEY = 'bookReviewManager';

	function getConfig() {
		return window.pkpBookReviewManager || {};
	}

	pkp.registry.registerComponent('ObjectsForReviewWorkflowGrid', {
		props: {
			submission: {type: Object, required: true},
		},
		template: '<div class="bookReviewManagerWorkflowGrid"><div ref="container"></div></div>',
		mounted: function () {
			this.load();
		},
		watch: {
			'submission.id': function () {
				this.load();
			},
		},
		beforeUnmount: function () {
			if (this.$refs.container) {
				$(this.$refs.container).empty();
			}
		},
		methods: {
			load: function () {
				var config = getConfig();
				if (!config.gridUrl || !this.$refs.container) {
					return;
				}
				var url = config.gridUrl.replace(
					'__submissionId__',
					encodeURIComponent(this.submission.id),
				);
				var $container = $('<div></div>');
				$(this.$refs.container).empty().append($container);
				$container.pkpHandler('$.pkp.controllers.UrlInDivHandler', {
					sourceUrl: url,
					refreshOn: null,
				});
			},
		},
	});

	pkp.registry.storeExtend('workflow', function (piniaContext) {
		var store = piniaContext.store;

		store.extender.extendFn('getMenuItems', function (menuItems) {
			var label = getConfig().label || 'Objects for review';
			return menuItems.map(function (item) {
				if (item.key !== 'publication' || !Array.isArray(item.items)) {
					return item;
				}
				return Object.assign({}, item, {
					items: item.items.concat([
						{
							key: 'publication_' + MENU_KEY,
							label: label,
							state: {
								primaryMenuItem: 'publication',
								secondaryMenuItem: MENU_KEY,
								title: label,
							},
						},
					]),
				});
			});
		});

		store.extender.extendFn('getPrimaryItems', function (primaryItems, args) {
			var state = (args && args.selectedMenuState) || {};
			if (
				state.primaryMenuItem === 'publication' &&
				state.secondaryMenuItem === MENU_KEY
			) {
				return [
					{
						component: 'ObjectsForReviewWorkflowGrid',
						props: {submission: args.submission},
					},
				];
			}
			return primaryItems;
		});
	});
})();
