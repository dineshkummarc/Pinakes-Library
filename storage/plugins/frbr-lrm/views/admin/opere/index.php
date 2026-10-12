<?php
/**
 * @var array<int, array<string, mixed>> $opere
 * @var string $pageTitle
 */
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-2xl font-bold text-gray-900 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

            <?= __("Opere (FRBR/LRM)") ?>
            <span class="text-sm font-normal bg-gray-100 text-gray-600 px-2 py-1 rounded-full"><?= count($opere) ?></span>
          <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

        <a href="<?= htmlspecialchars(url('/admin/opere/new'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors text-sm font-medium inline-flex items-center">
          <i class="fas fa-plus mr-2"></i><?= __("Nuova Opera") ?>
        </a>
      <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
    <ol>
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a></li>
        <li aria-current="page"><span><?= __("Opere") ?></span></li>
    </ol>
</nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 6) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <div class="bg-white shadow rounded-lg overflow-hidden">
      <?php if (empty($opere)): ?>
        <div class="p-8 text-center text-gray-500">
          <i class="fas fa-sitemap text-3xl mb-3 text-gray-300"></i>
          <p><?= __("Nessuna opera ancora. Raggruppa le edizioni dei tuoi libri creando la prima opera.") ?></p>
        </div>
      <?php else: ?>
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Titolo uniforme") ?></th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Autore") ?></th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Lingua orig.") ?></th>
              <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= __("Edizioni") ?></th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($opere as $o): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                  <a href="<?= htmlspecialchars(url('/admin/opere/' . (int) $o['id']), ENT_QUOTES, 'UTF-8') ?>" class="font-medium text-gray-900 hover:text-primary">
                    <?= htmlspecialchars((string) $o['titolo_uniforme'], ENT_QUOTES, 'UTF-8') ?>
                  </a>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars((string) ($o['autore_nome'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars((string) ($o['lingua_originale'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="px-4 py-3 text-center">
                  <span class="text-sm font-medium bg-gray-100 text-gray-700 px-2 py-1 rounded-full"><?= (int) ($o['num_edizioni'] ?? 0) ?></span>
                </td>
                <td class="px-4 py-3 text-right">
                  <a href="<?= htmlspecialchars(url('/admin/opere/' . (int) $o['id'] . '/edit'), ENT_QUOTES, 'UTF-8') ?>" class="btn-icon btn-secondary text-gray-400 hover:text-gray-700" title="<?= htmlspecialchars(__("Modifica"), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__("Modifica"), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-edit"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
