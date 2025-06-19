/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	//Earnings Append
	$(document).on('click', '.add-earnings', function() {
		var expandearning= '<div class="md:col-span-12 earning-add-row">'+
		'<div class="grid md:grid-cols-12 gap-4 ">'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Basic</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">DA(40%)</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">HRA(15%)</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+                               
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Conveyance</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Allowance </label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+                                       						
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Medical Allowance</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+         
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Others</label>'+
				'<div class="flex items-center">'+
					'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
				'<a href="#" class="text-danger ms-2 delete-earning"><i class="far fa-trash-alt"></i></a>'+ 
				'</div>'+       
			'</div>'+
		'</div>'+
		'</div>';
		$(".earning-row").append(expandearning);
		$('.select').select2({
		minimumResultsForSearch: -1,
		width: '100%'
	});
		return false;
	
	});

	// Remove earning
	$(document).on('click', '.delete-earning', function () {
		$(this).closest('.earning-add-row').remove();
		return false;
	});

	//Deduction Append
	$(document).on('click', '.add-deduction', function() {
		var expanddeduction= 
		'<div class="md:col-span-12 deduction-add-row">'+
		'<div class="grid md:grid-cols-12 gap-4 ">'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">TDS</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">ESI</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">PF</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+                               
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Leave</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Prof.Tax</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+                                       						
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Labour Welfare</label>'+
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+         
			'</div>'+
			'<div class="col-span-3">'+
				'<label class="block mb-1 text-sm leading-normal font-medium text-title">Others</label>'+
				'<div class="flex items-center">'+
					'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">'+
				'<a href="#" class="text-danger ms-2 delete-deduction"><i class="far fa-trash-alt"></i></a>'+ 
				'</div>'+       
			'</div>'+
		'</div>'+
		'</div>';
		$(".deduction-row").append(expanddeduction);
		$('.select').select2({
		minimumResultsForSearch: -1,
		width: '100%'
	});
		return false;
	
	});

	// Remove earning
	$(document).on('click', '.delete-deduction', function () {
		$(this).closest('.deduction-add-row').remove();
	return false;
	});
	
})();