/* Renders the TOTP provisioning URI as an inline SVG QR code. */
(function () {
    'use strict';

    function render() {
        if (typeof qrcode !== 'function') {
            return;
        }
        document.querySelectorAll('[data-qr]').forEach(function (target) {
            if (target.dataset.qrRendered === '1') {
                return;
            }
            var value = target.getAttribute('data-qr');
            if (!value) {
                return;
            }
            try {
                var qr = qrcode(0, 'M');
                qr.addData(value);
                qr.make();
                target.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
                target.dataset.qrRendered = '1';
            } catch (error) {
                target.textContent = value;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
