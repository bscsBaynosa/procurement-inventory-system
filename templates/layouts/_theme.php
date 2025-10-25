<?php

?>
<style>

.switch { --h: 26px; --w: 48px; position: relative; display:inline-block; width: var(--w); height: var(--h); }
.switch input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:#d1d5db; transition:.2s; border-radius: 999px; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.slider:before { position:absolute; content:""; height: calc(var(--h) - 6px); width: calc(var(--h) - 6px); left:3px; top:3px; background:white; transition:.2s; border-radius:999px; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
.switch input:checked + .slider { background: #22c55e; }
.switch input:checked + .slider:before { transform: translateX(calc(var(--w) - var(--h))); }


:root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; }
html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; }


*, *::before, *::after { box-sizing: border-box; }
input, select, textarea { width: 100%; max-width: 100%; min-width: 0; display: block; }
.card form { display: grid; gap: 12px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 1100px){ .form-row { grid-template-columns: 1fr; } }
</style>
<script>

(function(){
  try{
    var saved = localStorage.getItem('pocc_admin_theme');
    if(saved === 'dark' || saved === 'light'){
      document.documentElement.setAttribute('data-theme', saved);
    }
  }catch(e){}
})();


function bindThemeToggle(selector){
  var el = document.querySelector(selector);
  if(!el) return;
  try{
    var current = document.documentElement.getAttribute('data-theme') || 'light';
    el.checked = current === 'dark';
    el.addEventListener('change', function(){
      var mode = el.checked ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', mode);
      try{ localStorage.setItem('pocc_admin_theme', mode); }catch(e){}
    });
  }catch(e){}
}
</script>
