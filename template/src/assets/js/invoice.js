/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	// Add Description
	$(".add-more-description").on('click', function () {

		var servicecontent = '<div class="grid grid-cols-1 xl:grid-cols-12 gap-x-6 extra-title-row">' +
			'<div class="col-span-6">' +
			'<div class="mb-3">' +
			'<label class="form-label">Description</label>' +
			'<input type="text" class="form-control">' +
			'</div>' +
			'</div>' +
			'<div class="col-span-6">' +
			'<div class="grid grid-cols-1 xl:grid-cols-12 gap-x-6">' +
			'<div class="col-span-4">' +
			'<div class="mb-3">' +
			'<label class="form-label">Qty</label>' +
			'<input type="text" class="form-control">' +
			'</div>' +
			'</div>' +
			'<div class="col-span-4">' +
			'<div class="mb-3">' +
			'<label class="form-label">Discount</label>' +
			'<input type="text" class="form-control">' +
			'</div>' +
			'</div>' +
			'<div class="col-span-4">' +
			'<div class="mb-3">' +
			'<label class="form-label">Rate</label>' +
			'<div class="flex items-center">' + 
			'<input type="text" class="form-control">' +
			'<a href="#" class="link-danger text-danger ms-2 delete-item"><i class="far fa-trash-alt"></i></a>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>';
		$(".add-description-info").append(servicecontent);
		return false;
	});
	
	$(".add-description-info").on('click', '.delete-item', function () {
		$(this).closest('.extra-title-row').remove();
		return false;
	});

})();