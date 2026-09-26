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
                <button type="button" class="btn primary" data-action="unlock">Отключи</button>
                <button type="button" class="btn" data-action="lock">Заключи</button>
                <button type="button" class="btn ghost" data-action="refresh">Обнови</button>
                <button type="button" class="btn ghost" data-action="logs">История</button>
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
