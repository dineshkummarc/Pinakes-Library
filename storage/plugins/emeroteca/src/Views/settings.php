<?php
declare(strict_types=1);
$plugin=$GLOBALS['plugins']['emeroteca']??null;
if (!$plugin instanceof \EmerotecaPlugin) { return; }
$mode=$plugin->contributionService()->mode();
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __('Emeroteca') ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-gray-600"><?= __('Gestione di riviste, giornali e periodici: testate, annate e fascicoli.') ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li><a href="<?= $e(url('/admin/dashboard')) ?>"><?= __('Home') ?></a></li>

        <li><a href="<?= $e(url('/admin/plugins')) ?>"><?= __('Plugin') ?></a></li>

        <li aria-current="page"><?= __('Emeroteca') ?></li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 5) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>
    <?php $modeReturnTo='/admin/plugins'; require __DIR__.'/article-mode.php'; ?>
  </div>
</div>
