<?php

declare(strict_types=1);

use SmartLock\Auth;
use SmartLock\Env;

require __DIR__.'/../bootstrap.php';

Auth::startSession();

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (! Auth::validCsrf($_POST['csrf'] ?? null)) {
        $error = 'Сесията е изтекла. Опитайте отново.';
    } elseif (isset($_POST['logout'])) {
        Auth::logout();
        header('Location: index.php');
        exit;
    } elseif (Auth::attempt((string) ($_POST['password'] ?? ''), Env::get('APP_PASSWORD_HASH'), STORAGE_DIR)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Грешна парола или твърде много опити.';
    }
}

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $e(Auth::csrfToken()) ?>">
    <title>Смарт брава</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>🔐 Смарт брава</h1>

<?php if (! Auth::check()): ?>
    <form method="post" class="card login">
        <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
        <label for="password">Парола</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
        <?php if ($error !== null): ?><p class="error"><?= $e($error) ?></p><?php endif; ?>
        <button type="submit" class="btn primary">Вход</button>
    </form>
<?php else: ?>
    <div id="locks" class="locks"><p class="muted">Зареждане…</p></div>

    <template id="lock-template">
        <section class="card lock">
            <header>
                <h2 class="lock-name"></h2>
                <span class="badge lock-online"></span>
            </header>
            <p class="muted">Батерия: <strong class="lock-battery">—</strong></p>
            <div class="actions">
                <button type="button" class="btn primary remote-only" data-action="unlock">Отключи</button>
                <button type="button" class="btn remote-only" data-action="lock">Заключи</button>
                <button type="button" class="btn primary" data-action="temp-password">Временна парола</button>
                <button type="button" class="btn" data-action="users">Потребители</button>
                <button type="button" class="btn" data-action="settings">Настройки</button>
                <button type="button" class="btn ghost" data-action="refresh">Обнови</button>
                <button type="button" class="btn ghost" data-action="logs">История</button>
            </div>
            <form class="temp-form" hidden>
                <label>Име <input type="text" name="name" maxlength="30" placeholder="напр. Куриер"></label>
                <label>Вид
                    <select name="type">
                        <option value="once">Еднократна</option>
                        <option value="multiple">Многократна</option>
                    </select>
                </label>
                <label>Валидна (часове) <input type="number" name="hours" min="1" max="720" value="24"></label>
                <button type="submit" class="btn primary">Генерирай</button>
            </form>
            <section class="users" hidden>
                <h3>Потребители с постоянен код</h3>
                <form class="user-form">
                    <label>Име <input type="text" name="name" maxlength="30" required placeholder="напр. Иван"></label>
                    <label>Код (6–10 цифри) <input type="text" name="password" inputmode="numeric" pattern="\d{6,10}" placeholder="празно = случаен"></label>
                    <label>Валиден
                        <select name="days">
                            <option value="1">1 ден</option>
                            <option value="7">7 дни</option>
                            <option value="30">30 дни</option>
                            <option value="365">1 година</option>
                            <option value="1825" selected>5 години</option>
                        </select>
                    </label>
                    <button type="submit" class="btn primary">Добави</button>
                </form>
                <ul class="users-list"></ul>
            </section>
            <section class="settings" hidden>
                <h3>Настройки на бравата</h3>
                <p class="muted small">Промените се прилагат, когато бравата се събуди (докоснете клавиатурата).</p>
                <div class="settings-list"></div>
            </section>
            <div class="temp-result" hidden>
                <p class="muted">Парола:</p>
                <p class="temp-password"></p>
                <p class="muted temp-valid"></p>
                <div class="actions">
                    <button type="button" class="btn" data-action="copy">Копирай</button>
                    <button type="button" class="btn" data-action="share">Изпрати</button>
                </div>
            </div>
            <p class="lock-message" role="status"></p>
            <ul class="lock-logs"></ul>
        </section>
    </template>

    <form method="post" class="logout">
        <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
        <button type="submit" name="logout" value="1" class="btn ghost">Изход</button>
    </form>

    <script src="app.js" defer></script>
<?php endif; ?>
</main>
</body>
</html>
