</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom install prompt — a centered modal instead of relying on
         the browser's own small, easy-to-miss install banner. -->
    <div class="modal fade" id="kpaw-install-modal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-3">
                <div class="modal-body">
                    <img src="/assets/img/icon-192.png" alt="" width="72" height="72" class="mb-3 rounded">
                    <h5 class="mb-2">Install Ahar</h5>
                    <p class="text-muted small mb-4">Add it to your home screen for quick access — opens like a real app, no browser bar.</p>
                    <button type="button" id="kpaw-install-btn" class="btn btn-primary w-100 mb-2">Install</button>
                    <button type="button" class="btn btn-link w-100" data-bs-dismiss="modal" id="kpaw-install-later-btn">Later</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js');
    }

    (function () {
        // Already running as an installed app? Never show this again.
        if (window.matchMedia('(display-mode: standalone)').matches || localStorage.getItem('kpawInstalled') === '1') {
            return;
        }
        // Dismissed once already this browser session ("Later")? Don't
        // re-show on every internal page navigation within that same
        // visit — it'll show again next time they open the site fresh.
        if (sessionStorage.getItem('kpawInstallDismissed') === '1') {
            return;
        }

        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            var modalEl = document.getElementById('kpaw-install-modal');
            if (modalEl) {
                new bootstrap.Modal(modalEl).show();
            }
        });

        window.addEventListener('appinstalled', function () {
            localStorage.setItem('kpawInstalled', '1');
        });

        var installBtn = document.getElementById('kpaw-install-btn');
        if (installBtn) {
            installBtn.addEventListener('click', async function () {
                var modalEl = document.getElementById('kpaw-install-modal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                await deferredPrompt.userChoice;
                deferredPrompt = null;
            });
        }

        var laterBtn = document.getElementById('kpaw-install-later-btn');
        if (laterBtn) {
            laterBtn.addEventListener('click', function () {
                sessionStorage.setItem('kpawInstallDismissed', '1');
            });
        }
    })();
    </script>

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