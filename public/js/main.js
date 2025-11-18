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
	function updateControlState(btn, expanded){
		if (!btn) return;
		var showLabel = btn.getAttribute('data-label-show') || 'View';
		var hideLabel = btn.getAttribute('data-label-hide') || 'Minimize';
		btn.textContent = expanded ? hideLabel : showLabel;
		btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
	}
	function expandRow(tr, trigger){
		if (!tr) return;
		var url = tr.getAttribute('data-expand-url');
		if (!url) return;
		var cols = parseInt(tr.getAttribute('data-expand-columns')||'1',10);
		var next = tr.nextElementSibling;
		var holder = (next && next.classList.contains('row-expansion')) ? next : null;
		var isOpen = !!holder && (!tr.id || holder.getAttribute('data-parent-id') === tr.id);
		var btn = trigger;
		if (!btn && tr.id) {
			btn = document.querySelector('[data-expand-control="' + tr.id + '"]');
		}
		if (isOpen){
			holder.parentNode.removeChild(holder);
			tr.classList.remove('is-expanded');
			tr.classList.remove('expanding');
			updateControlState(btn, false);
			return;
		}
		if (tr.classList.contains('expanding')) { return; }
		if (holder) { holder.parentNode.removeChild(holder); }
		holder = document.createElement('tr');
		holder.className = 'row-expansion';
		if (tr.id) { holder.setAttribute('data-parent-id', tr.id); }
		var td = document.createElement('td');
		td.colSpan = cols;
		td.style.padding = '0';
		td.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#64748b;">Loading…</div>';
		holder.appendChild(td);
		tr.parentNode.insertBefore(holder, tr.nextElementSibling);
		tr.classList.add('is-expanded');
		tr.classList.add('expanding');
		updateControlState(btn, true);
		fetch(url, { headers: { 'X-Requested-With':'XMLHttpRequest' }})
			.then(function(r){ return r.text(); })
			.then(function(html){
				td.innerHTML = html;
			})
			.catch(function(){
				td.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#dc2626;">Failed to load details.</div>';
			})
			.finally(function(){ tr.classList.remove('expanding'); });
	}
	document.addEventListener('click', function(e){
		var control = e.target.closest && e.target.closest('[data-expand-control]');
		if (control){
			e.preventDefault();
			e.stopPropagation();
			var targetId = control.getAttribute('data-expand-control');
			var row = targetId ? document.getElementById(targetId) : null;
			if (row) { expandRow(row, control); }
			return;
		}
		var tr = e.target.closest && e.target.closest('tr.expandable-row');
		if (tr) { expandRow(tr); }
	});
})();