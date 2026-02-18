/**
 * Taxonomy Admin JavaScript
 */
(function($) {
	'use strict';

	const TaxonomyAdmin = {
		init: function() {
			this.bindEvents();
			this.cacheTaxonomies();
		},

		bindEvents: function() {
			$('#wppp-taxonomy-form').on('submit', this.handleSubmit.bind(this));
			$('.wppp-edit-taxonomy').on('click', this.handleEdit.bind(this));
			$('.wppp-delete-taxonomy').on('click', this.handleDelete.bind(this));
			$('#wppp-cancel-edit').on('click', this.handleCancel.bind(this));
			$('#wppp-reset-taxonomies').on('click', this.handleReset.bind(this));
			$('#wppp-slug').on('input', this.validateSlug.bind(this));
		},

		cacheTaxonomies: function() {
			this.taxonomies = {};
			$('.wppp-taxonomy-list tbody tr[data-slug]').each((i, row) => {
				const $row = $(row);
				const slug = $row.data('slug');
				const taxonomyData = $row.data('taxonomy');
				if (taxonomyData) {
					this.taxonomies[slug] = taxonomyData;
				}
			});
		},

		handleSubmit: function(e) {
			e.preventDefault();
			
			const action = $('#wppp-form-action').val();
			const formData = new FormData(e.target);
			formData.set('action', action);

			this.showMessage('Processing...', 'info');
			$('#wppp-submit-taxonomy').prop('disabled', true);

			$.ajax({
				url: wpppTaxonomyAdmin.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: (response) => {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						setTimeout(() => {
							location.reload();
						}, 1000);
					} else {
						this.showMessage(response.data.message, 'error');
						$('#wppp-submit-taxonomy').prop('disabled', false);
					}
				},
				error: (xhr) => {
					this.showMessage('An error occurred. Please try again.', 'error');
					$('#wppp-submit-taxonomy').prop('disabled', false);
				}
			});
		},

		handleEdit: function(e) {
			const slug = $(e.currentTarget).data('slug');
			const taxonomy = this.taxonomies[slug];

			if (!taxonomy) {
				return;
			}

			// Switch to edit mode
			$('#wppp-form-title').text('Edit Taxonomy');
			$('#wppp-form-action').val('wppp_update_taxonomy');
			$('#wppp-original-slug').val(slug);
			$('#wppp-submit-taxonomy').text('Update Taxonomy');
			$('#wppp-cancel-edit').show();

			// Populate form
			$('#wppp-slug').val(taxonomy.slug).prop('readonly', true);
			$('#wppp-name').val(taxonomy.name);
			$('#wppp-singular').val(taxonomy.singular);
			$('#wppp-hierarchical').prop('checked', taxonomy.hierarchical);
			$('#wppp-public').prop('checked', taxonomy.public);
			$('#wppp-show-ui').prop('checked', taxonomy.show_ui);
			$('#wppp-show-in-rest').prop('checked', taxonomy.show_in_rest);

			// Scroll to form
			$('html, body').animate({
				scrollTop: $('.wppp-taxonomy-form').offset().top - 50
			}, 500);

			this.hideMessage();
		},

		handleDelete: function(e) {
			const slug = $(e.currentTarget).data('slug');
			const taxonomy = this.taxonomies[slug];

			if (!taxonomy) {
				return;
			}

			if (!confirm(`Are you sure you want to delete the "${taxonomy.name}" taxonomy? This action cannot be undone.`)) {
				return;
			}

			const data = {
				action: 'wppp_delete_taxonomy',
				nonce: wpppTaxonomyAdmin.nonce,
				slug: slug
			};

			$.post(wpppTaxonomyAdmin.ajaxUrl, data, (response) => {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data.message);
				}
			});
		},

		handleCancel: function(e) {
			e.preventDefault();
			this.resetForm();
		},

		handleReset: function(e) {
			if (!confirm('Are you sure you want to reset all taxonomies to their default values? This will remove any custom taxonomies you have created.')) {
				return;
			}

			const data = {
				action: 'wppp_reset_taxonomies',
				nonce: wpppTaxonomyAdmin.nonce
			};

			$.post(wpppTaxonomyAdmin.ajaxUrl, data, (response) => {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data.message || 'An error occurred.');
				}
			});
		},

		resetForm: function() {
			$('#wppp-taxonomy-form')[0].reset();
			$('#wppp-form-title').text('Add New Taxonomy');
			$('#wppp-form-action').val('wppp_add_taxonomy');
			$('#wppp-original-slug').val('');
			$('#wppp-submit-taxonomy').text('Add Taxonomy');
			$('#wppp-cancel-edit').hide();
			$('#wppp-slug').prop('readonly', false);
			$('#wppp-public').prop('checked', true);
			$('#wppp-show-ui').prop('checked', true);
			$('#wppp-show-in-rest').prop('checked', true);
			this.hideMessage();
		},

		validateSlug: function(e) {
			const $input = $(e.currentTarget);
			const value = $input.val();
			const pattern = /^wppp_[a-z0-9_]*$/;

			if (value && !pattern.test(value)) {
				$input.css('border-color', '#d63638');
			} else {
				$input.css('border-color', '');
			}
		},

		showMessage: function(message, type) {
			const $msg = $('#wppp-taxonomy-message');
			$msg.removeClass('success error info')
				.addClass(type)
				.html(message)
				.show();
		},

		hideMessage: function() {
			$('#wppp-taxonomy-message').hide();
		}
	};

	$(document).ready(() => {
		TaxonomyAdmin.init();
	});

})(jQuery);
