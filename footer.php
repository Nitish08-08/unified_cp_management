</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
	const toggle = document.getElementById('theme-toggle');
	if (!toggle) return;
	toggle.addEventListener('click', function () {
		const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
		const next = current === 'dark' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', next);
		try {
			window.localStorage.setItem('ucp-theme', next);
		} catch (err) {
			// localStorage unavailable (private mode). Ignore.
		}
	});
});
</script>
</body>
</html>
