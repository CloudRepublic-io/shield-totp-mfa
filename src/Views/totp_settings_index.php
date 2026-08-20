<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('TotpMfa.settingsHeading') ?></h1>

<?php if (session('message')) : ?>
    <div class="alert alert-success"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<?php if ($enrolled) : ?>
    <p><?= lang('TotpMfa.enrolledIntro') ?></p>

    <form method="post" action="<?= url_to('totp-settings-disable') ?>" onsubmit="return confirm('<?= lang('TotpMfa.disableConfirm') ?>');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger">
            <?= lang('TotpMfa.disableButton') ?>
        </button>
    </form>
<?php else : ?>
    <p><?= lang('TotpMfa.notEnrolledIntro') ?></p>

    <a href="<?= url_to('totp-settings-enroll') ?>" class="btn btn-primary">
        <?= lang('TotpMfa.enableButton') ?>
    </a>
<?php endif ?>

<?= $this->endSection() ?>
