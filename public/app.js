(() => {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const container = document.getElementById('locks');
    const template = document.getElementById('lock-template');

    async function api(action, deviceId = null, method = 'GET') {
        const params = new URLSearchParams({ action });
        if (deviceId) params.set('device', deviceId);

        const response = await fetch(`api.php?${params}`, {
            method,
            headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.status === 401) {
            window.location.reload();
            throw new Error('Не сте влезли.');
        }

        const data = await response.json().catch(() => ({ ok: false, error: `HTTP ${response.status}` }));
        if (!data.ok) throw new Error(data.error || 'Грешка');

        return data;
    }

    function formatBattery(value) {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'number') return `${value}%`;
        return { high: 'висока', medium: 'средна', low: 'ниска', poweroff: 'изтощена' }[value] || value;
    }

    function setMessage(card, text, type = '') {
        const el = card.querySelector('.lock-message');
        el.textContent = text;
        el.className = `lock-message ${type}`;
    }

    async function refresh(card, deviceId) {
        try {
            const { lock } = await api('status', deviceId);
            const online = card.querySelector('.lock-online');
            online.textContent = lock.online ? 'онлайн' : 'офлайн';
            online.classList.toggle('ok', lock.online);
            online.classList.toggle('off', !lock.online);
            card.querySelector('.lock-battery').textContent = formatBattery(lock.battery);
        } catch (err) {
            setMessage(card, err.message, 'error');
        }
    }

    async function showLogs(card, deviceId) {
        const list = card.querySelector('.lock-logs');
        list.innerHTML = '<li class="muted">Зареждане…</li>';

        try {
            const { logs } = await api('logs', deviceId);
            list.innerHTML = '';
            if (logs.length === 0) {
                list.innerHTML = '<li class="muted">Няма записи за последните 7 дни.</li>';
                return;
            }
            for (const log of logs) {
                const li = document.createElement('li');
                const when = log.time ? new Date(log.time).toLocaleString('bg-BG') : '—';
                const how = Object.keys(log.events).map((code) => code.replace(/^unlock_/, '')).join(', ');
                li.textContent = `${when} — ${log.user || 'неизвестен'}${how ? ` (${how})` : ''}`;
                list.appendChild(li);
            }
        } catch (err) {
            list.innerHTML = '';
            setMessage(card, err.message, 'error');
        }
    }

    async function operate(card, deviceId, action, button) {
        if (action === 'unlock' && !window.confirm('Сигурни ли сте, че искате да отключите?')) return;

        button.disabled = true;
        setMessage(card, action === 'unlock' ? 'Отключване…' : 'Заключване…');
        try {
            const { message } = await api(action, deviceId, 'POST');
            setMessage(card, message, 'success');
            setTimeout(() => refresh(card, deviceId), 3000);
        } catch (err) {
            setMessage(card, err.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    function renderLock(device) {
        const card = template.content.firstElementChild.cloneNode(true);
        card.querySelector('.lock-name').textContent = device.label;

        card.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const action = button.dataset.action;
            if (action === 'refresh') refresh(card, device.id);
            else if (action === 'logs') showLogs(card, device.id);
            else operate(card, device.id, action, button);
        });

        container.appendChild(card);
        refresh(card, device.id);
    }

    api('devices')
        .then(({ devices }) => {
            container.innerHTML = '';
            if (devices.length === 0) {
                container.innerHTML = '<p class="muted">Няма конфигурирани брави (TUYA_DEVICE_IDS в .env).</p>';
                return;
            }
            devices.forEach(renderLock);
        })
        .catch((err) => {
            container.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'error';
            p.textContent = err.message;
            container.appendChild(p);
        });
})();
