/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	// Add Salary Settings

	$(document).on('click','.add-salary-btn',function(){

		var expensescontent = '<div class="grid grid-cols-3 gap-4 flex items-center justify-center w-full salary-add-row">' +
			'<div class="col-span-1 w-full">' +
				'<div class="mt-2">' +
					'<label class="block mb-1 text-sm leading-normal font-medium text-title">Salary From</label>' +
					'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +
				'</div>' +
			'</div>' +
			'<div class="col-span-1 w-full">' +
				'<div class="mt-2">' +
					'<label class="block mb-1 text-sm leading-normal font-medium text-title">Salary To</label>' +
					'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +
				'</div>' +
			'</div>' +
			'<div class="col-span-1 w-full">' +
				'<div class="flex items-center">'+
					'<div class="mt-2">' +
						'<label class="block mb-1 text-sm leading-normal font-medium text-title">Percentage</label>' +
						'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +
					'</div>' +
					'<div class="pt-8 ms-3">'+
						'<a href = "#" class="w-8 h-8 rounded bg-light flex items-center justify-center delete-salary text-primary text-white"><i class="ti ti-trash text-primary"></i>'
				'</div>'+
				
			'</div>' +
		'</div>' +
	'</div>' ;	
		'</div>'

		
		$(".add-salary-info").append(expensescontent);
		return false;
	});

	// Remove Salary
	$(document).on('click', '.delete-salary', function () {
		$(this).closest('.salary-add-row').remove();
		return false;
	});
	
})();