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

// Inline row expansion for PR and PO lists (dropdown style)
(function(){
	function expandRow(tr){
		if (!tr || tr.classList.contains('expanding')) return;
		var url = tr.getAttribute('data-expand-url');
		var cols = parseInt(tr.getAttribute('data-expand-columns')||'1',10);
		if (!url) return;
		// Toggle collapse if next row is expansion
		var next = tr.nextElementSibling;
		if (next && next.classList.contains('row-expansion')){ next.parentNode.removeChild(next); return; }
		// Create placeholder row
		var holder = document.createElement('tr');
		holder.className = 'row-expansion';
		var td = document.createElement('td');
		td.colSpan = cols;
		td.style.padding = '0';
		td.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#64748b;">Loading…</div>';
		holder.appendChild(td);
		tr.parentNode.insertBefore(holder, tr.nextElementSibling);
		tr.classList.add('expanding');
		fetch(url, { headers: { 'X-Requested-With':'XMLHttpRequest' }})
			.then(function(r){ return r.text(); })
			.then(function(html){
				td.innerHTML = html;
			})
			.catch(function(){ td.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#dc2626;">Failed to load details.</div>'; })
			.finally(function(){ tr.classList.remove('expanding'); });
	}
	document.addEventListener('click', function(e){
		var tr = e.target.closest && e.target.closest('tr.expandable-row');
		if (tr) { expandRow(tr); }
	});
})();