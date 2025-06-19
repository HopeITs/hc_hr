/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	//Leads Append

	// Attach click event to the "add-lead-phno" button
	$(document).on('click', '.add-modal-row', function() {
	 
  
		// Create the new HTML structure for the additional input and select
		var newRow = '<div class="grid md:grid-cols-3 gap-4 phone-add-row">'+
		'<div class="col-span-2">'+
			'<div class="input-block mb-3">'+
			'<input class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400" type="text">'+
			'</div>'+
		'</div>'+
		'<div class="flex items-end w-full">'+
			'<div class="input-block w-full mb-3 flex items-center">'+
			'<div class="w-full">'+
				'<select class="select">'+
				'<option>Work</option>'+
				'<option>Home</option>'+
				'</select>'+
			'</div>'+
			'<a href="#" class="avatar avatar-md rounded delete-phone text-primary"><i class="ti ti-trash ms-2"></i></a>'+
			'</div>'+
		'</div>'+
	  '</div>'+
	  '</div>';
	
		  
	
		  $(".lead-phno-col").append(newRow);
		  $('.select').select2({
			minimumResultsForSearch: -1,
			width: '100%'
		});
		  return false;
		 
	});
	
	  
	  // Remove phone
	$(document).on('click', '.delete-phone', function () {
		$(this).closest('.phone-add-row').remove();
		return false;
	});

	//email Append

	$(document).on('click', '.add-email-row', function() {
		var expandemail = '<div class="grid md:grid-cols-3 gap-4 email-add-row">'+
		  '<div class="col-span-2">'+
			'<div class="input-block mb-3">'+
			  '<input class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400" type="text">'+
			'</div>'+
		  '</div>'+
		  '<div class="flex items-end">'+
			'<div class="input-block w-full mb-3 flex items-center">'+
			  '<div class="w-full">'+
				'<select class="select">'+
				  '<option>Work</option>'+
				  '<option>Home</option>'+
				'</select>'+
			  '</div>'+
			  '<a href="#" class="ms-2 avatar avatar-md rounded delete-email text-primary"><i class="ti ti-trash"></i></a>'+
			'</div>'+
		  '</div>'+
	'</div>'+
	'</div>';
	$(".lead-email-col").append(expandemail);
	$('.select').select2({
		minimumResultsForSearch: -1,
		width: '100%'
	});
	return false;
  	});

	// Remove email
	$(document).on('click', '.delete-email', function () {
		$(this).closest('.email-add-row').remove();
		return false;
	});
	
})();