</section></main></div><script>
document.querySelectorAll('[data-search]').forEach(input=>input.addEventListener('input',()=>{const q=input.value.toLowerCase();document.querySelectorAll(input.dataset.search+' tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q)?'':'none')}));
</script></body></html>