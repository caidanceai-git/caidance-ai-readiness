/**
 * Caidance — AI-Readiness Score
 *
 * Handles the "Run scan now" button on Settings → Caidance.
 * All runtime config (REST endpoint, nonce, idle/running labels) is read
 * from data-* attributes on the button itself — no PHP interpolation is
 * needed in this file.
 *
 * On click: POST to the REST endpoint with the X-WP-Nonce header, then
 * reload the page so the server-side render picks up the latest stored
 * scan result.
 *
 * @package Caidance\AiReadiness
 */
(function () {
    var btn = document.getElementById('caidance-air-run-scan');
    var status = document.getElementById('caidance-air-scan-status');
    if (!btn) {
        return;
    }

    btn.addEventListener('click', function () {
        var labelRunning = btn.getAttribute('data-label-running');
        var labelIdle = btn.getAttribute('data-label-idle');
        var endpoint = btn.getAttribute('data-endpoint');
        var nonce = btn.getAttribute('data-nonce');

        btn.disabled = true;
        btn.textContent = labelRunning;
        if (status) {
            status.textContent = '';
        }

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
            }
        }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (t) {
                    throw new Error('HTTP ' + response.status + ': ' + t.slice(0, 200));
                });
            }
            return response.json();
        }).then(function () {
            if (status) {
                status.textContent = 'Scan complete. Reloading…';
            }
            window.location.reload();
        }).catch(function (e) {
            btn.disabled = false;
            btn.textContent = labelIdle;
            if (status) {
                status.textContent = 'Scan failed: ' + e.message;
            }
        });
    });
})();
