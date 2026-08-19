</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Live countdown for any element with class="kpaw-countdown" and
        // data-closes="<ISO 8601 datetime>". No-op if none exist on the page.
        (function () {
            const els = document.querySelectorAll('.kpaw-countdown');
            if (!els.length) return;

            function tick() {
                const now = Date.now();
                els.forEach(function (el) {
                    const closes = new Date(el.dataset.closes).getTime();
                    let diff = Math.max(0, closes - now);
                    if (diff <= 0) {
                        el.textContent = 'closing now';
                        return;
                    }
                    const h = Math.floor(diff / 3600000);
                    const m = Math.floor((diff % 3600000) / 60000);
                    const s = Math.floor((diff % 60000) / 1000);
                    el.textContent = h > 0
                        ? `closes in ${h}h ${m}m`
                        : `closes in ${m}m ${s}s`;
                    el.classList.toggle('text-danger', diff < 15 * 60000); // urgent under 15 min
                });
            }
            tick();
            setInterval(tick, 1000);
        })();
    </script>
</body>
</html>