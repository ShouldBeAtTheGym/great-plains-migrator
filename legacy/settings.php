<?php

define('APP_SETTINGS', true);

session_start();

require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/layout.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!hash_equals($_SESSION['csrf'], $token)) {
        $error = 'This form expired. Save again.';
    } else {
        setting_put('gp_server', trim((string) ($_POST['gp_server'] ?? '')));
        setting_put('gp_database', trim((string) ($_POST['gp_database'] ?? '')));
        setting_put('gp_user', trim((string) ($_POST['gp_user'] ?? '')));
        $password = (string) ($_POST['gp_password'] ?? '');
        if ($password !== '') {
            setting_put('gp_password', $password);
        }
        setting_put('gp_trust_cert', isset($_POST['gp_trust_cert']) ? '1' : '0');
        $company = trim((string) ($_POST['company_label'] ?? ''));
        setting_put('company_label', $company !== '' ? $company : 'BLUE');

        require __DIR__ . '/gdbase.inc.php';
        if ($conn) {
            $notice = 'Settings saved. Connected to GP.';
        } else {
            $error = 'Settings saved, but GP did not connect.';
            $sqlErrors = sqlsrv_errors(SQLSRV_ERR_ALL);
            if (is_array($sqlErrors)) {
                foreach ($sqlErrors as $sqlError) {
                    $message = isset($sqlError['message']) ? (string) $sqlError['message'] : '';
                    if ($message !== '') {
                        $error .= ' ' . $message;
                    }
                }
            }
        }
    }
}

$trust = setting('gp_trust_cert', '1') === '1';

layout_start(
    'Settings',
    'Settings',
    'GP connection and the company label shown in the header. These values are stored in the local SQLite database. GP company data stays on SQL Server.'
);

if ($notice !== '') {
    echo '<div class="alert alert-success">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>';
}
if ($error !== '') {
    echo '<div class="alert alert-danger">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
}
?>
<form method="post" class="bg-white border rounded p-4" style="max-width: 40rem" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <h2 class="h6">GP connection</h2>
    <div class="mb-3">
        <label class="form-label" for="gp_server">Server</label>
        <input class="form-control" id="gp_server" name="gp_server" value="<?= htmlspecialchars(setting('gp_server'), ENT_QUOTES, 'UTF-8') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="gp_database">Database</label>
        <input class="form-control" id="gp_database" name="gp_database" value="<?= htmlspecialchars(setting('gp_database'), ENT_QUOTES, 'UTF-8') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="gp_user">User</label>
        <input class="form-control" id="gp_user" name="gp_user" value="<?= htmlspecialchars(setting('gp_user'), ENT_QUOTES, 'UTF-8') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="gp_password">Password</label>
        <input class="form-control" id="gp_password" name="gp_password" type="password" value="" placeholder="Leave blank to keep the saved password" autocomplete="new-password">
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="gp_trust_cert" name="gp_trust_cert" value="1"<?= $trust ? ' checked' : '' ?>>
        <label class="form-check-label" for="gp_trust_cert">Trust server certificate</label>
    </div>
    <h2 class="h6">Display</h2>
    <div class="mb-4">
        <label class="form-label" for="company_label">Company label</label>
        <input class="form-control" id="company_label" name="company_label" value="<?= htmlspecialchars(setting('company_label', 'BLUE'), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-outline-secondary" href="index.php">Back</a>
</form>
<?php
layout_end();
