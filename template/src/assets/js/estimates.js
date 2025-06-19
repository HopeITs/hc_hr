/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	$(".add-more-estimate").on('click', function () {

		var servicecontent = '<div class="grid grid-cols-1 md:grid-cols-12 gap-y-4 gap-x-6 add-title-row">' +
		'<div class="col-span-2">'+
		'<label class="block mb-1 text-sm leading-normal font-medium text-title">Item</label>'+
		'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
	'</div>'+
	'<div class="md:col-span-4">'+
		'<label class="block mb-1 text-sm leading-normal font-medium text-title">Description</label>'+
		'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
	'</div>'+
	'<div class="col-span-2">'+
		'<label class="block mb-1 text-sm leading-normal font-medium text-title">Unit Cost</label>'+
		'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
	'</div>'+
	'<div class="col-span-2">'+
		'<label class="block mb-1 text-sm leading-normal font-medium text-title">Qty</label>'+
		'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
	'</div>'+

			'<div class="col-span-2">' +
			'<label class="block mb-1 text-sm leading-normal font-medium text-title">Amount</label>' +
			'<div class="flex items-center">' + 
			'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +
			'<a href="#" class="text-danger ms-2 delete-item"><i class="far fa-trash-alt"></i></a>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>';
		$(".add-estimate-info").append(servicecontent);
		return false;
	});
	
	$(".add-estimate-info").on('click', '.delete-item', function () {
		$(this).closest('.add-title-row').remove();
		return false;
	});
	
})();