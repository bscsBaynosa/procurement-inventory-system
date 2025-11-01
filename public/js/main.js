// Simple UI helpers for unit "others" toggles across forms
(function(){
	function hook(select){
		var targetId = select.getAttribute('data-target');
		if (!targetId) return;
		var input = document.getElementById(targetId);
		if (!input) return;
		var sync = function(){
			if (select.value === 'others'){
				input.style.display = 'inline-block';
				input.required = true;
			} else {
				input.style.display = 'none';
				input.required = false;
			}
		};
		select.addEventListener('change', sync);
		// initial
		sync();
	}
	document.addEventListener('DOMContentLoaded', function(){
		var selects = document.querySelectorAll('select[data-unit-select="true"]');
		for (var i=0; i<selects.length; i++){ hook(selects[i]); }
	});
})();