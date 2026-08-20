<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('TotpMfa.heading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('TotpMfa.enrollIntro') ?></p>

<div id="totp-qr" class="mb-3" style="width:200px;height:200px;"></div>

<p class="text-muted small">
    <?= lang('TotpMfa.manualKeyIntro') ?><br>
    <code><?= esc($manualKey) ?></code>
</p>

<form method="post" action="<?= url_to('auth-action-verify') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="code" class="form-label"><?= lang('TotpMfa.enrollCodeLabel') ?></label>
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="one-time-code"
            id="code"
            name="code"
            class="form-control"
            maxlength="6"
            autofocus
            required
        >
    </div>

    <?php if ($rememberEnabled) : ?>
        <div class="mb-2 form-check">
            <input type="checkbox" class="form-check-input" id="remember_device" name="remember_device" value="1">
            <label class="form-check-label" for="remember_device">
                <?= str_replace('{days}', (string) $rememberDays, lang('TotpMfa.rememberLabel')) ?>
            </label>
        </div>
        <div class="mb-3">
            <label for="device_name" class="form-label"><?= lang('TotpMfa.deviceNameLabel') ?></label>
            <input
                type="text"
                id="device_name"
                name="device_name"
                class="form-control"
                maxlength="191"
                placeholder="<?= lang('TotpMfa.deviceNamePlaceholder') ?>"
            >
        </div>
    <?php endif ?>

    <button type="submit" class="btn btn-primary">
        <?= lang('TotpMfa.verifyButton') ?>
    </button>
</form>

<!--
    QR is rendered client-side from the otpauth:// URI already present
    in this page - the secret is never sent to any third-party QR
    generation service.
-->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    new QRCode(document.getElementById('totp-qr'), {
        text: <?= json_encode($provisioningUri) ?>,
        width: 200,
        height: 200,
    });
</script>

<?= $this->endSection() ?>
