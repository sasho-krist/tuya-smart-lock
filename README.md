# Tuya Smart Lock

Малко самостоятелно PHP + JavaScript приложение за управление на смарт брава (Tuya / Smart Life) през Tuya Cloud OpenAPI.
Няма външни зависимости: трябват само PHP 8.1+ с `curl` и `json`.

**Какво прави:**
- показва статуса на бравата: онлайн/офлайн и батерия;
- отключва и заключва дистанционно без парола (ticket → `door-operate`, при грешка fallback към `open-door`);
- показва историята на отключванията за последните 7 дни;
- генерира офлайн временни пароли (еднократни или многократни) с бутони „Копирай“ и „Изпрати“;
- поддържа няколко брави;
- защитава интерфейса с парола (bcrypt), CSRF и заключване след 5 грешни опита за 15 мин;
- записва audit log на всяко отключване/заключване в `storage/audit.log`.

## Настройка в Tuya

1. Регистрирай се на https://platform.tuya.com и създай **Cloud → Development → Create Cloud Project**.
   - Data Center: същият регион като акаунта ти в Smart Life/Tuya app (за България обикновено *Central Europe*).
   - Включи API услугите **IoT Core** и **Smart Lock Open Service**.
2. В проекта: **Devices → Link App Account → Add App Account** и сканирай QR кода от Smart Life/Tuya приложението
   (Me → иконата за сканиране горе вдясно). Бравата ще се появи в списъка с устройства.
3. Копирай **Access ID/Client ID** и **Access Secret/Client Secret** от Overview и **Device ID** на бравата.
4. В приложението на бравата включи **дистанционно отключване** (Remote unlock), ако бравата го поддържа.
   Много Wi-Fi брави изискват това. Bluetooth бравите изискват gateway.

## Инсталация

```bash
cp .env.example .env
php bin/hash-password.php          # генерира APP_PASSWORD_HASH за .env
php tests/run.php                  # тестове (без мрежа)
php bin/check.php                  # проверка на връзката с Tuya и бравите
php -S 127.0.0.1:8000 -t public    # локално: http://127.0.0.1:8000
```

`.env`:

```dotenv
TUYA_BASE_URL=https://openapi.tuyaeu.com
TUYA_CLIENT_ID=...
TUYA_CLIENT_SECRET=...
TUYA_DEVICE_IDS=bf0123456789abcdef:Входна врата,bf9876543210fedcba:Склад
APP_PASSWORD_HASH='$2y$10$...'
```

На хостинг: document root трябва да сочи към `public/`. Така `.env`, `src/` и `storage/` не са достъпни от уеба.
`storage/` трябва да е writable за PHP. **Използвай само HTTPS.**

## Структура

```
bootstrap.php             autoload, .env, helper функции
src/TuyaClient.php        HTTP клиент: подпис HMAC-SHA256 (v2), кеширан token, retry при изтекъл token
src/SmartLockService.php  status / operate (unlock, lock) / logs
src/Auth.php              сесия, парола, CSRF, rate limit
src/Env.php               минимален .env loader
public/index.php          вход + UI
public/api.php            JSON API (използва се от app.js)
public/app.js             фронтенд логика (fetch)
tests/run.php             тестове с fake transport
```

## JSON API

Всички заявки изискват логнат потребител. POST заявките изискват и header `X-CSRF-Token`.

| Метод | URL | Описание |
|-------|-----|----------|
| GET  | `api.php?action=devices` | списък с конфигурираните брави |
| GET  | `api.php?action=status&device=ID` | онлайн, батерия, суров статус |
| GET  | `api.php?action=logs&device=ID` | история на отключванията (7 дни) |
| POST | `api.php?action=unlock&device=ID` | отключване |
| POST | `api.php?action=lock&device=ID` | заключване (ако бравата го поддържа) |
| POST | `api.php?action=temp-password&device=ID` | офлайн временна парола; JSON body: `{"type": "once"\|"multiple", "hours": 24, "name": "Куриер"}` |

## Използване от друг PHP код (напр. Laravel)

`TuyaClient` и `SmartLockService` нямат зависимости и могат да се ползват директно:

```php
$client = new SmartLock\TuyaClient('https://openapi.tuyaeu.com', $clientId, $clientSecret, storage_path('tuya_token.json'));
$lock = new SmartLock\SmartLockService($client);

$lock->status($deviceId);
$lock->operate($deviceId, open: true);
$lock->logs($deviceId);

// произволна заявка към Tuya OpenAPI
$client->request('GET', "/v1.0/devices/{$deviceId}/functions");
```

## Брави без дистанционно отключване

Много брави на батерии „спят“ и не изпълняват команди от cloud-а. Ако в Smart Life няма бутон за отключване, сложи
`TUYA_REMOTE_UNLOCK=false` в `.env`. Така бутоните „Отключи“ и „Заключи“ се скриват и остават временните пароли.
Офлайн паролите се изчисляват в cloud-а и бравата ги приема, без да е онлайн.

## Чести грешки

| Код | Причина |
|-----|---------|
| 1004 | грешен подпис: провери Client Secret и часовника на сървъра (NTP) |
| 1106 | няма права: устройството не е свързано към проекта (Link App Account) |
| 1108 | API услугата не е включена или бравата не поддържа операцията |
| 2001 | устройството е офлайн |
