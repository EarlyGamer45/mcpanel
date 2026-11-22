// Configurator: keeps the price summary in sync with the sliders (progressive
// enhancement — the form still works without JavaScript, prices are recomputed
// server-side on submit).
(function () {
    'use strict';

    var form = document.querySelector('[data-configurator]');
    if (!form) {
        return;
    }
    var summary = document.querySelector('[data-summary]');
    var totalElement = document.querySelector('[data-total]');
    var timer = null;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }

    function syncOutputs() {
        form.querySelectorAll('input[type="range"]').forEach(function (input) {
            var output = form.querySelector('output[for="' + input.name + '"]');
            if (output) {
                var value = input.dataset.divide ? String(Math.round(Number(input.value) / Number(input.dataset.divide) * 10) / 10).replace('.', ',') : input.value;
                output.textContent = value + (input.dataset.suffix || '');
            }
        });
    }

    function refresh() {
        if (!summary || !totalElement) {
            return;
        }
        var data = new FormData(form);
        data.append('product_id', form.dataset.productId);
        fetch('/configurator/quote', {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (quote) {
                if (!quote) { return; }
                totalElement.textContent = quote.total;
                var lines = summary.querySelector('[data-lines]');
                if (lines) {
                    lines.innerHTML = quote.lines
                        .map(function (line) {
                            return '<div class="row"><span>' + escapeHtml(line.label) + '</span><span>' + escapeHtml(line.amount) + '</span></div>';
                        })
                        .join('');
                }
                var base = summary.querySelector('[data-base]');
                if (base) { base.textContent = quote.base; }
            })
            .catch(function () { /* offline: the server recalculates on submit */ });
    }

    form.addEventListener('input', function () {
        syncOutputs();
        clearTimeout(timer);
        timer = setTimeout(refresh, 250);
    });
    form.addEventListener('change', refresh);
    syncOutputs();
})();

// Interface: theme switch, mobile menus, slider fill, euro inputs, appearance preview.
(function () {
    'use strict';

    var root = document.documentElement;

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) { /* private mode */ }
        });
    });

    var openSidebar = document.querySelector('[data-sidebar-open]');
    if (openSidebar) {
        openSidebar.addEventListener('click', function () { document.body.classList.add('sidebar-open'); });
    }
    document.querySelectorAll('[data-sidebar-close]').forEach(function (element) {
        element.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { document.body.classList.remove('sidebar-open'); }
    });

    var navToggle = document.querySelector('[data-nav-toggle]');
    var siteNav = document.getElementById('site-nav');
    if (navToggle && siteNav) {
        navToggle.addEventListener('click', function () {
            var open = siteNav.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function paintRange(input) {
        var min = Number(input.min || 0), max = Number(input.max || 100), value = Number(input.value);
        var pct = max > min ? ((value - min) / (max - min)) * 100 : 0;
        input.style.setProperty('--fill', pct + '%');
    }
    document.querySelectorAll('input[type="range"]').forEach(function (input) {
        paintRange(input);
        input.addEventListener('input', function () { paintRange(input); });
    });

    // Euro fields: accept "12", "12.5", "12,5" and show them as "12,50".
    document.querySelectorAll('input[data-money]').forEach(function (input) {
        input.addEventListener('blur', function () {
            var raw = input.value.replace(/[€\s]/g, '');
            if (raw === '') { return; }
            var match = raw.match(/^(\d+)(?:[.,](\d{0,2}))?$/);
            if (!match) { return; }
            var cents = (match[2] || '').padEnd(2, '0');
            input.value = String(Number(match[1])) + ',' + cents;
        });
    });

    // Appearance page: preview colours and theme before saving.
    var appearance = document.querySelector('[data-appearance-form]');
    if (appearance && !appearance.closest('fieldset[disabled]')) {
        var preview = document.querySelector('[data-appearance-preview]');
        var sync = function () {
            var brand = appearance.querySelector('[name="brand_color"]');
            var accent = appearance.querySelector('[name="accent_color"]');
            if (brand && /^#[0-9a-f]{6}$/i.test(brand.value)) { root.style.setProperty('--brand', brand.value); }
            if (accent && /^#[0-9a-f]{6}$/i.test(accent.value)) { root.style.setProperty('--accent', accent.value); }
            var radius = appearance.querySelector('[name="radius"]:checked');
            if (radius) { root.style.setProperty('--radius', { sharp: '2px', normal: '6px', round: '12px' }[radius.value]); }
            var mode = appearance.querySelector('[name="theme_mode"]:checked');
            if (mode && preview) {
                var resolved = mode.value === 'auto'
                    ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
                    : mode.value;
                preview.setAttribute('data-preview-theme', resolved);
            }
        };
        document.querySelectorAll('[data-preset-brand]').forEach(function (button) {
            button.addEventListener('click', function () {
                [['brand_color', button.getAttribute('data-preset-brand')], ['accent_color', button.getAttribute('data-preset-accent')]].forEach(function (pair) {
                    var picker = appearance.querySelector('[name="' + pair[0] + '"]');
                    var text = appearance.querySelector('[data-color-text="' + pair[0] + '"]');
                    if (picker) { picker.value = pair[1]; }
                    if (text) { text.value = pair[1]; }
                });
                sync();
            });
        });
        appearance.addEventListener('input', sync);
        appearance.addEventListener('change', sync);
        sync();
        document.querySelectorAll('[data-color-text]').forEach(function (text) {
            var picker = appearance.querySelector('[name="' + text.getAttribute('data-color-text') + '"]');
            if (!picker) { return; }
            picker.addEventListener('input', function () { text.value = picker.value; });
            text.addEventListener('input', function () {
                if (/^#[0-9a-f]{6}$/i.test(text.value)) { picker.value = text.value; sync(); }
            });
        });
    }
})();

// Tables with a "select all" checkbox.
(function () {
    'use strict';
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        var table = master.closest('table');
        master.addEventListener('change', function () {
            (table || document).querySelectorAll('[data-check-item]').forEach(function (box) { box.checked = master.checked; });
        });
    });
})();

// Product editor: show the fixed-plan or the configurator fields.
(function () {
    'use strict';
    var group = document.querySelector('[data-plan-switch]');
    if (!group) { return; }
    function apply() {
        var checked = group.querySelector('input:checked');
        var type = checked ? checked.value : 'custom';
        document.querySelectorAll('[data-plan]').forEach(function (element) {
            element.hidden = element.getAttribute('data-plan') !== type;
        });
    }
    group.addEventListener('change', apply);
    apply();
})();

// Catalogue: close an open expansions list when clicking elsewhere, show how many are ticked.
document.addEventListener('click', (event) => {
    document.querySelectorAll('details.plan-packs[open]').forEach((details) => {
        if (!details.contains(event.target)) {
            details.removeAttribute('open');
        }
    });
});
document.querySelectorAll('details.plan-packs').forEach((details) => {
    const summary = details.querySelector('summary');
    const badge = document.createElement('span');
    badge.className = 'plan-packs-count';
    summary.appendChild(badge);
    const update = () => {
        const n = details.querySelectorAll('input:checked').length;
        badge.textContent = n > 0 ? String(n) : '';
        badge.hidden = n === 0;
    };
    details.addEventListener('change', update);
    update();
});
