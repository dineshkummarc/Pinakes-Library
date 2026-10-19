<?php
$workspaceHeader = ['title' => __('Il tuo spazio di lavoro'), 'context' => \App\Support\HtmlHelper::e($_SESSION['user']['role_name'] ?? '')];
require __DIR__ . '/../partials/workspace-header.php';
?>
<div class="px-4 sm:px-6 lg:px-8 py-6">
  <p><?= __('Accedi alle sezioni abilitate per il tuo ruolo.') ?></p>
  <ul class="space-y-4 mt-6">
  <?php foreach (['catalog.manage' => ['/admin/books', __('Catalogazione')], 'loans.manage' => ['/admin/loans', __('Prestiti')], 'users.manage' => ['/admin/users', __('Utenti')], 'settings.manage' => ['/admin/settings', __('Impostazioni')]] as $permission => [$path, $label]): ?>
    <?php if (\App\Support\Permissions::can($permission)): ?><li><a class="ui-button btn-outline" href="<?= \App\Support\HtmlHelper::e(url($path)) ?>"><?= \App\Support\HtmlHelper::e($label) ?></a></li><?php endif; ?>
  <?php endforeach; ?>
  </ul>
  <p class="mt-6"><a href="<?= \App\Support\HtmlHelper::e(url('/')) ?>"><?= __('Vai al catalogo pubblico') ?></a></p>
</div>
