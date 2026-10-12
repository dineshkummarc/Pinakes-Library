<?php use App\Support\Csrf; $csrf = Csrf::ensureToken(); ?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->

    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>
          <?= __("Crea Nuovo Genere") ?>
        <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

        <p class="text-gray-600"><?= __('Aggiungi un genere o un sottogenere alla struttura.') ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>

        <li>
          <a href="<?= htmlspecialchars(url('/admin/genres'), ENT_QUOTES, 'UTF-8') ?>">
            <?= __('Generi') ?>
          </a>
        </li>

        <li aria-current="page"><?= __('Nuovo') ?></li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <form method="post" action="<?= htmlspecialchars(url('/admin/genres/create'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

      <div class="card">
        <div class="grid grid-cols-1 gap-6">
          <div>
            <label for="nome" class="form-label"><?= __('Nome genere') ?> <span class="text-red-500">*</span></label>
            <input id="nome" name="nome" required aria-required="true" class="form-input" placeholder="<?= htmlspecialchars(__('es. Fantasy contemporaneo'), ENT_QUOTES, 'UTF-8') ?>" />
          </div>
          <div>
            <label for="parent_id" class="form-label"><?= __('Genere padre (opzionale)') ?></label>
            <select id="parent_id" name="parent_id" class="form-input">
              <option value=""><?= __('Nessun padre (genere principale)') ?></option>
              <?php foreach (($generiParentOptions ?? []) as $g): if (($g['parent_id'] ?? null) === null): ?>
                <option value="<?php echo (int)$g['id']; ?>">
                  <?php echo htmlspecialchars($g['nome'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endif; endforeach; ?>
            </select>
          </div>
        </div>
        <div class="pk-form-actions mt-6 flex gap-3 justify-end">
          <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i><?= __("Salva") ?></button>
        </div>
      </div>
    </form>
  </div>

</div>
