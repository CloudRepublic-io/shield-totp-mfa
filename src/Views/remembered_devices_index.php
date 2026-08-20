<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('TotpMfa.devicesHeading') ?></h1>

<p class="text-muted"><?= lang('TotpMfa.devicesIntro') ?></p>

<?php if (session('message')) : ?>
    <div class="alert alert-success"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<?php if ($devices === []) : ?>
    <p><?= lang('TotpMfa.noDevices') ?></p>
<?php else : ?>
    <table class="table">
        <thead>
            <tr>
                <th><?= lang('TotpMfa.deviceColumnName') ?></th>
                <th><?= lang('TotpMfa.deviceColumnLastUsed') ?></th>
                <th><?= lang('TotpMfa.deviceColumnExpires') ?></th>
                <th><?= lang('TotpMfa.deviceColumnActions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $device) : ?>
                <tr>
                    <td>
                        <?= esc($device['device_name'] ?? 'Unnamed device') ?>
                        <?php if ($device['selector'] === $currentSelector) : ?>
                            <span class="badge bg-secondary"><?= lang('TotpMfa.thisDevice') ?></span>
                        <?php endif ?>
                    </td>
                    <td><?= $device['last_used_at'] ? esc($device['last_used_at']) : '—' ?></td>
                    <td><?= esc($device['expires_at']) ?></td>
                    <td>
                        <form method="post" action="<?= url_to('account-devices-delete', $device['id']) ?>" onsubmit="return confirm('Remove this device?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <?= lang('TotpMfa.removeButton') ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<?= $this->endSection() ?>
