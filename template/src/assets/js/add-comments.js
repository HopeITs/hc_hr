/*
Author       : Dreamstechnologies
Template Name: Smarthr - Bootstrap Admin Template
*/
(function () {
    "use strict";
	
	// Add Comment

	if($('.add-comment').length > 0) {
		$(".add-comment").on("click", function(a) {
		$(this).closest(".notes-editor").children(".note-edit-wrap").slideToggle();
		});
		$(".add-cancel").on("click", function(a) {
		$(this).closest(".note-edit-wrap").slideUp();
		});
	}

	$(document).on('click','.add-sign',function(){

		var signcontent = '<div class="grid grid-cols-1 md:grid-cols-12 gap-y-4 gap-x-6 sign-cont mt-4">' +
			'<div class="md:col-span-6">' +
				'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +
			'</div>' +
			'<div class="md:col-span-6">' +
				'<div class="flex items-center">' +    
					'<div class="flex-1 me-2">' +    
					'<input type="text" class="bg-white border-borderColor text-gray-900 text-sm rounded-input  block w-full py-2 px-2.5 h-[38px] placeholder:text-gray-400">' +    
					'</div>' +
					'<div class="input-btn">' +
						'<a href="javascript:void(0);" class="size-7 flex items-center justify-center rounded text-primary  hover:bg-dark-transparent hover:text-dark trash-sign"><i class="ti ti-trash"></i></a>' +
					'</div>' +
				'</div>' +
			'</div>' +
		'</div>';
		$(".sign-content").append(signcontent);
		return false;
	});
	
	// Remove Sign
	$(document).on('click', '.trash-sign', function () {
		$(this).closest('.sign-cont').remove();
		return false;
	});

})();