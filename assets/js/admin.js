/**
 * WP Agent Admin Scripts
 *
 * Handles interactive elements on WP Agent admin pages:
 * pairing code submission, API key visibility toggle, etc.
 */
(function () {
    'use strict';

    var config = window.wpAgentChat || {};

    // --- Pairing code submission ---
    var pairBtn = document.getElementById('wp_agent_pair_submit');
    var pairInput = document.getElementById('wp_agent_pair_code');
    var pairResult = document.getElementById('wp_agent_pair_result');

    if (pairBtn && pairInput) {
        pairBtn.addEventListener('click', function () {
            var code = pairInput.value.trim();
            if (!code || code.length !== 6) {
                showPairResult('Please enter a valid 6-digit pairing code.', 'error');
                return;
            }

            pairBtn.disabled = true;
            pairBtn.textContent = 'Pairing...';

            fetch(config.restUrl + 'pair', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ code: code }),
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            throw new Error(data.message || 'Pairing failed');
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    showPairResult('Successfully paired! Channel: ' + (data.channel || 'unknown'), 'success');
                    pairInput.value = '';
                    setTimeout(function () { location.reload(); }, 1500);
                })
                .catch(function (err) {
                    showPairResult(err.message, 'error');
                })
                .finally(function () {
                    pairBtn.disabled = false;
                    pairBtn.textContent = 'Pair';
                });
        });

        pairInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                pairBtn.click();
            }
        });
    }

    function showPairResult(message, type) {
        if (!pairResult) return;
        pairResult.textContent = message;
        pairResult.className = 'wp-agent-pair-result wp-agent-pair-result--' + type;
    }

    // --- API key field: clear on focus if masked ---
    var apiKeyFields = document.querySelectorAll('input[type="password"][name*="api_key"], input[type="password"][name*="bot_token"]');
    apiKeyFields.forEach(function (field) {
        var originalValue = field.value;
        field.addEventListener('focus', function () {
            if (this.value.indexOf('\u2022') !== -1 || this.value.indexOf('*') !== -1) {
                this.value = '';
                this.type = 'text';
            }
        });
        field.addEventListener('blur', function () {
            if (this.value === '') {
                this.value = originalValue;
                this.type = 'password';
            }
        });
    });
})();
