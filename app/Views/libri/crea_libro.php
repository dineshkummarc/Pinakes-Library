<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->


    <!-- Header -->
    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __("Aggiungi Nuovo Libro") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-gray-600"><?= __("Compila i dettagli del libro per aggiungerlo alla biblioteca") ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <?= __("Nuovo") ?>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Main Form -->
    <?php
      $mode = 'create';
      $book = [
        'stato' => 'Disponibile',
        'copie_totali' => 1,
        'copie_disponibili' => 1,
      ];
      include __DIR__ . '/partials/book_form.php';
    ?>
  </div>
</div>
