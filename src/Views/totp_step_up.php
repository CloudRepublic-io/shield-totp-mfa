<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('TotpMfa.stepUpHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('TotpMfa.stepUpIntro') ?></p>

<form method="post" action="<?= url_to('totp-step-up-verify') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="code" class="form-label"><?= lang('TotpMfa.codeLabel') ?></label>
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

    <button type="submit" class="btn btn-primary">
        <?= lang('TotpMfa.verifyButton') ?>
    </button>
</form>

<?= $this->endSection() ?>
