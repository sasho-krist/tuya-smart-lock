(() => {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const container = document.getElementById('locks');
    const template = document.getElementById('lock-template');

    let remoteUnlock = true;

    async function api(action, deviceId = null, method = 'GET', body = null) {
        const params = new URLSearchParams({ action });
        if (deviceId) params.set('device', deviceId);

        const response = await fetch(`api.php?${params}`, {
            method,
            headers: { 'X-CSRF-Token': csrf, Accept: 'application/json', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null,
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

    const METHOD_LABELS = {
        unlock_fingerprint: 'отпечатък',
        unlock_password: 'код',
        unlock_card: 'карта',
        unlock_temporary: 'временен код',
        unlock_dynamic: 'динамичен код',
        unlock_offline_pd: 'офлайн код',
        unlock_app: 'приложение',
        unlock_face: 'лице',
        unlock_key: 'ключ',
        unlock_hand: 'длан',
        unlock_finger_vein: 'вени',
        unlock_ble: 'Bluetooth',
    };

    function methodLabel(code) {
        return METHOD_LABELS[code] || code.replace(/^unlock_/, '');
    }

    async function renameMethod(card, deviceId, key, current) {
        const name = window.prompt('Име за този начин на отключване (празно = изтрий):', current || '');
        if (name === null) return;
        try {
            await api('name-set', deviceId, 'POST', { key, name });
            showLogs(card, deviceId);
        } catch (err) {
            setMessage(card, err.message, 'error');
        }
    }

    async function showLogs(card, deviceId) {
        const list = card.querySelector('.lock-logs');
        list.innerHTML = '<li class="muted">Зареждане…</li>';

        try {
            const { logs, names } = await api('logs', deviceId);
            list.innerHTML = '';
            if (logs.length === 0) {
                list.innerHTML = '<li class="muted">Няма записи за последните 7 дни.</li>';
                return;
            }
            for (const log of logs) {
                const li = document.createElement('li');
                const when = log.time ? new Date(log.time).toLocaleString('bg-BG') : '—';
                const methods = Object.entries(log.events).filter(([code]) => code.startsWith('unlock_'));

                const text = document.createElement('span');
                if (methods.length === 0) {
                    text.textContent = `${when} — ${log.user || 'неизвестен'}`;
                    li.appendChild(text);
                }

                for (const [code, value] of methods) {
                    const key = `${code}:${value}`;
                    const name = names[key] || log.user || '';
                    text.textContent = `${when} — ${name || 'без име'} (${methodLabel(code)} №${value})`;
                    li.appendChild(text);

                    const edit = document.createElement('button');
                    edit.type = 'button';
                    edit.className = 'btn-link';
                    edit.textContent = names[key] ? 'смени' : 'именувай';
                    edit.addEventListener('click', () => renameMethod(card, deviceId, key, names[key]));
                    li.appendChild(edit);
                    break;
                }
                list.appendChild(li);
            }
        } catch (err) {
            list.innerHTML = '';
            setMessage(card, err.message, 'error');
        }
    }

    const SETTING_LABELS = {
        language: 'Език',
        beep_volume: 'Сила на звука',
        alarm_volume: 'Сила на алармата',
        doorbell_volume: 'Сила на звънеца',
        doorbell_song: 'Мелодия на звънеца',
        key_tone: 'Звук на клавиатурата',
        automatic_lock: 'Автоматично заключване',
        auto_lock_time: 'Време до автоматично заключване',
        auto_lock_timer: 'Време до автоматично заключване',
        do_not_disturb: 'Не безпокойте',
        normal_open_switch: 'Режим „винаги отворено“',
        passage_mode: 'Режим „винаги отворено“',
        double_lock: 'Двойно заключване',
        arming_switch: 'Охрана',
        rtc_lock: 'Заключване по график',
        unlock_switch: 'Комбинирано отключване',
        motor_torque: 'Сила на мотора',
        motor_direction: 'Посока на мотора',
        lock_screen: 'Заключване на екрана',
        verify_lock_switch: 'Потвърждение при заключване',
        special_function: 'Специална функция',
        manual_lock: 'Ръчно заключване',
    };

    const VALUE_LABELS = {
        chinese_simplified: 'китайски', english: 'английски', russian: 'руски', german: 'немски',
        french: 'френски', spanish: 'испански', italian: 'италиански', portuguese: 'португалски',
        bulgarian: 'български', turkish: 'турски', arabic: 'арабски', japanese: 'японски', korean: 'корейски',
        chinese_traditional: 'китайски (традиционен)', vietnamese: 'виетнамски', thai: 'тайландски',
        mute: 'без звук', low: 'ниска', normal: 'нормална', high: 'висока', middle: 'средна',
    };

    function settingControl(card, deviceId, setting) {
        const label = document.createElement('label');
        label.className = 'setting';
        const title = document.createElement('span');
        title.textContent = SETTING_LABELS[setting.code] || setting.code;
        label.appendChild(title);

        let input;
        if (setting.type === 'bool') {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = setting.value === true;
            label.classList.add('setting-bool');
        } else if (setting.type === 'enum') {
            input = document.createElement('select');
            for (const value of setting.range) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = VALUE_LABELS[value] || value;
                option.selected = value === setting.value;
                input.appendChild(option);
            }
            if (!setting.range.includes(setting.value)) input.selectedIndex = -1;
        } else {
            input = document.createElement('input');
            input.type = 'number';
            if (setting.min !== null) input.min = setting.min;
            if (setting.max !== null) input.max = setting.max;
            if (setting.step) input.step = setting.step;
            input.value = setting.value ?? '';
            if (setting.unit) title.textContent += ` (${setting.unit})`;
        }

        input.addEventListener('change', async () => {
            const value = setting.type === 'bool' ? input.checked : setting.type === 'int' ? Number(input.value) : input.value;
            input.disabled = true;
            try {
                const { message } = await api('setting-set', deviceId, 'POST', { code: setting.code, value });
                setMessage(card, message, 'success');
            } catch (err) {
                setMessage(card, err.message, 'error');
            } finally {
                input.disabled = false;
            }
        });

        label.appendChild(input);
        return label;
    }

    async function showSettings(card, deviceId) {
        const section = card.querySelector('.settings');
        section.hidden = false;
        const list = section.querySelector('.settings-list');
        list.innerHTML = '<p class="muted">Зареждане…</p>';

        try {
            const { settings } = await api('settings', deviceId);
            list.innerHTML = '';
            for (const setting of settings) list.appendChild(settingControl(card, deviceId, setting));
        } catch (err) {
            list.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'error';
            p.textContent = err.message;
            list.appendChild(p);
        }
    }

    const PHASES = { 1: 'изчаква бравата', 2: 'активен', 3: 'замразен', 4: 'изтрит', 5: 'изтекъл' };

    async function showUsers(card, deviceId) {
        const section = card.querySelector('.users');
        section.hidden = false;
        const list = section.querySelector('.users-list');
        list.innerHTML = '<li class="muted">Зареждане…</li>';

        try {
            const { passwords } = await api('passwords', deviceId);
            list.innerHTML = '';
            if (passwords.length === 0) {
                list.innerHTML = '<li class="muted">Няма кодове, добавени от приложението.</li>';
                return;
            }
            for (const pw of passwords) {
                const li = document.createElement('li');
                const text = document.createElement('span');
                const until = pw.valid_to ? `до ${formatDate(pw.valid_to)}` : '';
                const phase = PHASES[pw.phase] || '';
                text.textContent = `${pw.name || 'без име'} — ${[phase, until].filter(Boolean).join(', ')}`;
                li.appendChild(text);

                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn-link danger';
                del.textContent = 'изтрий';
                del.addEventListener('click', () => deleteUser(card, deviceId, pw));
                li.appendChild(del);
                list.appendChild(li);
            }
        } catch (err) {
            list.innerHTML = '';
            setMessage(card, err.message, 'error');
        }
    }

    async function createUser(card, deviceId, form) {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        setMessage(card, 'Добавяне…');
        try {
            const data = Object.fromEntries(new FormData(form));
            const result = await api('password-create', deviceId, 'POST', {
                name: data.name,
                password: data.password,
                days: Number(data.days),
            });
            card.querySelector('.temp-password').textContent = result.password;
            card.querySelector('.temp-valid').textContent =
                `Постоянен код за ${data.name}, валиден до ${formatDate(result.valid_to)}. Бравата го получава при следващото събуждане.`;
            card.querySelector('.temp-result').hidden = false;
            form.reset();
            setMessage(card, '');
            showUsers(card, deviceId);
        } catch (err) {
            setMessage(card, err.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async function deleteUser(card, deviceId, pw) {
        if (!window.confirm(`Да изтрия ли кода на „${pw.name || 'без име'}“?`)) return;
        try {
            await api('password-delete', deviceId, 'POST', { id: pw.id });
            setMessage(card, 'Кодът е изтрит. Бравата ще го премахне при следващото събуждане.', 'success');
            showUsers(card, deviceId);
        } catch (err) {
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

    function formatDate(seconds) {
        return new Date(seconds * 1000).toLocaleString('bg-BG', { dateStyle: 'short', timeStyle: 'short' });
    }

    function shareText(card) {
        const password = card.querySelector('.temp-password').textContent;
        const valid = card.querySelector('.temp-valid').textContent;
        return `Код за вратата: ${password}\n${valid}`;
    }

    async function generatePassword(card, deviceId, form) {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        setMessage(card, 'Генериране…');
        try {
            const data = Object.fromEntries(new FormData(form));
            const { password } = await api('temp-password', deviceId, 'POST', {
                name: data.name,
                type: data.type,
                hours: Number(data.hours),
            });
            card.querySelector('.temp-password').textContent = password.password;
            card.querySelector('.temp-valid').textContent =
                `${password.type === 'once' ? 'Еднократна' : 'Многократна'}, валидна от ${formatDate(password.valid_from)} до ${formatDate(password.valid_to)}`;
            card.querySelector('.temp-result').hidden = false;
            setMessage(card, '');
        } catch (err) {
            setMessage(card, err.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async function copyPassword(card) {
        try {
            await navigator.clipboard.writeText(shareText(card));
            setMessage(card, 'Копирано.', 'success');
        } catch {
            setMessage(card, 'Копирането не е разрешено от браузъра.', 'error');
        }
    }

    async function sharePassword(card) {
        if (!navigator.share) {
            copyPassword(card);
            return;
        }
        try {
            await navigator.share({ text: shareText(card) });
        } catch {
            // потребителят е затворил менюто за споделяне
        }
    }

    function renderLock(device) {
        const card = template.content.firstElementChild.cloneNode(true);
        card.querySelector('.lock-name').textContent = device.label;
        if (!remoteUnlock) card.querySelectorAll('.remote-only').forEach((el) => el.remove());

        const userForm = card.querySelector('.user-form');
        userForm.addEventListener('submit', (event) => {
            event.preventDefault();
            createUser(card, device.id, userForm);
        });

        const form = card.querySelector('.temp-form');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            generatePassword(card, device.id, form);
        });

        card.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const action = button.dataset.action;
            if (action === 'refresh') refresh(card, device.id);
            else if (action === 'logs') showLogs(card, device.id);
            else if (action === 'temp-password') form.hidden = !form.hidden;
            else if (action === 'settings') {
                const settings = card.querySelector('.settings');
                if (settings.hidden) showSettings(card, device.id);
                else settings.hidden = true;
            }
            else if (action === 'users') {
                const users = card.querySelector('.users');
                if (users.hidden) showUsers(card, device.id);
                else users.hidden = true;
            }
            else if (action === 'copy') copyPassword(card);
            else if (action === 'share') sharePassword(card);
            else operate(card, device.id, action, button);
        });

        container.appendChild(card);
        refresh(card, device.id);
    }

    api('devices')
        .then(({ devices, remote_unlock: remote }) => {
            remoteUnlock = remote !== false;
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
