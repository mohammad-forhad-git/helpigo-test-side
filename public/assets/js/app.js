// Helpigo front-end helpers. Every page works without JavaScript; this only improves them.
(function () {
    'use strict';

    // Prevent double submits (slow mobile networks): ignore clicks after the first submit.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.setAttribute('aria-busy', 'true');
        }
    });

    // Coming back with the browser's Back button restores the page from cache: allow submitting again.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting]').forEach(function (form) {
            delete form.dataset.submitting;
        });
    });

    // ---------------------------------------------------------------- New task form
    var taskForm = document.getElementById('task-form');
    if (taskForm) {
        var rows = document.getElementById('item-rows');
        var addItem = document.getElementById('add-item');
        var max = rows ? parseInt(rows.parentElement.dataset.max, 10) : 0;

        var money = function (paisa) {
            var taka = Math.floor(paisa / 100), rest = paisa % 100;
            return '৳' + taka.toLocaleString('en-US') + (rest ? '.' + String(rest).padStart(2, '0') : '');
        };
        var itemCount = function () {
            if (!rows) { return 0; }
            return Array.prototype.filter.call(rows.querySelectorAll('input[name="item_name[]"]'), function (input) {
                return input.value.trim() !== '';
            }).length;
        };
        var toPaisa = function (value) {
            var match = String(value).replace(/[,\s৳]/g, '').match(/^(\d{1,10})(?:\.(\d{1,2}))?$/);
            return match ? parseInt(match[1], 10) * 100 + parseInt((match[2] || '0').padEnd(2, '0'), 10) : null;
        };
        // Mirrors PricingService::quote(); the server recalculates and its value is what is stored.
        var updateSummary = function () {
            var d = taskForm.dataset, base = parseInt(d.base, 10), worker;
            if (d.mode === 'offer') {
                var offer = toPaisa((taskForm.elements.offer_fee || {}).value || '');
                worker = offer !== null && offer >= base ? offer : null;
            } else {
                var included = parseInt(d.included, 10);
                worker = base + (included > 0 ? Math.max(0, itemCount() - included) * parseInt(d.perItem, 10) : 0);
            }
            var platform = parseInt(d.platform, 10);
            var credit = worker === null ? 0 : Math.min(parseInt(d.credit || '0', 10), worker + platform);
            document.getElementById('sum-worker').textContent = worker === null ? '—' : money(worker);
            document.getElementById('sum-platform').textContent = money(platform);
            document.getElementById('sum-discount-row').hidden = credit <= 0;
            document.getElementById('sum-discount').textContent = '−' + money(credit);
            document.getElementById('sum-total').textContent = worker === null ? '—' : money(worker + platform - credit);
        };
        taskForm.addEventListener('input', updateSummary);
        updateSummary();

        if (rows && addItem) {
            addItem.hidden = false;
            addItem.addEventListener('click', function () {
                var count = rows.children.length;
                if (count >= max) { return; }
                var row = rows.children[0].cloneNode(true);
                row.querySelectorAll('input').forEach(function (input) { input.value = ''; input.placeholder = input.classList.contains('item-row__note') ? input.placeholder : ''; });
                rows.appendChild(row);
                row.querySelector('input').focus();
                if (count + 1 >= max) { addItem.hidden = true; }
            });
        }

        var toggle = function (field, show) {
            if (field) { field.hidden = !show; }
        };
        var whenField = document.getElementById('preferred-at-field');
        var syncWhen = function () {
            var later = taskForm.querySelector('input[name="when"]:checked');
            toggle(whenField, later && later.value === 'later');
        };
        var rx = document.getElementById('has-prescription');
        var rxField = document.getElementById('prescription-field');
        var syncRx = function () { toggle(rxField, rx && rx.checked); };
        taskForm.addEventListener('change', function () { syncWhen(); syncRx(); });
        syncWhen();
        syncRx();
    }

    // ---------------------------------------------------------------- Register: referral code only for customers
    var roleInputs = document.querySelectorAll('input[name="role"]');
    var referral = document.querySelector('[data-role-only="customer"]');
    if (roleInputs.length && referral) {
        var syncRole = function () {
            var checked = document.querySelector('input[name="role"]:checked');
            referral.hidden = !checked || checked.value !== 'customer';
        };
        roleInputs.forEach(function (input) { input.addEventListener('change', syncRole); });
        syncRole();
    }

    // ---------------------------------------------------------------- Task chat (polling every 5 s while visible)
    var chat = document.querySelector('[data-chat-url]');
    if (chat && window.fetch) {
        var log = chat.querySelector('.chat__log');
        var form = chat.querySelector('.chat__form');
        var lastId = 0;
        var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var render = function (message) {
            var row = document.createElement('div');
            row.className = 'chat__msg' + (message.mine ? ' chat__msg--mine' : '');
            var body = document.createElement('p');
            body.textContent = message.body;                      // textContent: never HTML
            var meta = document.createElement('span');
            meta.className = 'chat__meta';
            meta.textContent = (message.mine ? '' : message.name + ' · ') + message.time;
            row.appendChild(body);
            row.appendChild(meta);
            log.appendChild(row);
        };
        var load = function () {
            if (document.hidden) { return; }
            fetch(chat.dataset.chatUrl + '?after=' + lastId, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) { return; }
                    if (lastId === 0 && data.messages.length === 0 && !log.querySelector('.chat__empty')) {
                        var empty = document.createElement('p');
                        empty.className = 'chat__empty muted small';
                        empty.textContent = log.dataset.empty;
                        log.appendChild(empty);
                    }
                    if (data.messages.length) {
                        var emptyNote = log.querySelector('.chat__empty');
                        if (emptyNote) { emptyNote.remove(); }
                        data.messages.forEach(render);
                        lastId = data.messages[data.messages.length - 1].id;
                        log.scrollTop = log.scrollHeight;
                    }
                    if (form && !data.can_write) { form.hidden = true; }
                })
                .catch(function () {});
        };
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = form.querySelector('input[name="body"]');
                var text = input.value.trim();
                if (!text) { delete form.dataset.submitting; return; }
                var body = new URLSearchParams();
                body.append('_token', token);
                body.append('body', text);
                fetch(chat.dataset.chatUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        delete form.dataset.submitting;
                        if (data.ok) { input.value = ''; load(); } else { window.alert(data.message); }
                    })
                    .catch(function () { delete form.dataset.submitting; });
            });
        }
        load();
        setInterval(load, 5000);
    }

    // ---------------------------------------------------------------- Notification badge (every 30 s)
    var bell = document.querySelector('[data-unread-url]');
    if (bell && window.fetch) {
        var badge = bell.querySelector('.bell__count');
        setInterval(function () {
            if (document.hidden) { return; }
            fetch(bell.dataset.unreadUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) { return; }
                    badge.hidden = data.unread === 0;
                    badge.textContent = data.unread > 9 ? '9+' : String(data.unread);
                })
                .catch(function () {});
        }, 30000);
    }

    // ---------------------------------------------------------------- Installable app (service worker)
    if ('serviceWorker' in navigator && document.body.dataset.sw && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(document.body.dataset.sw).catch(function () {});
        });
    }

    // ---------------------------------------------------------------- Live task status (polling every 10 s)
    var polled = document.querySelector('[data-poll-url]');
    if (polled && window.fetch) {
        var version = polled.dataset.pollVersion;
        var poll = function () {
            if (document.hidden) { return; }
            fetch(polled.dataset.pollUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (data && String(data.version) !== String(version)) {
                        window.location.reload();
                    }
                })
                .catch(function () { /* offline: try again next time */ });
        };
        setInterval(poll, 10000);
        document.addEventListener('visibilitychange', poll);
    }
})();
