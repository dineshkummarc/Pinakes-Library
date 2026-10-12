<?php
/**
 * Optional translation destinations. Only the selected language has fields;
 * the initial editor follows the site language. Safe inside an existing form.
 * @var string $translationEditorLocale
 * @var string $translationEditorPath
 * @var string $translationEditorFragment
 * @var string $translationEditorId
 * @var array<string,string> $translationEditorQuery
 */
$translationLocales = \App\Support\I18n::getAvailableLocales();
if (count($translationLocales) <= 1) { return; }
$translationEsc = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<details class="pk-translations-menu" id="<?= $translationEsc($translationEditorId) ?>">
  <summary>
    <?= __('Altre traduzioni') ?>
    <i class="fas fa-chevron-down" aria-hidden="true"></i>
  </summary>
  <nav class="pk-translations-menu__list" aria-label="<?= $translationEsc(__('Altre traduzioni')) ?>">
    <p><?= __('Modifica una traduzione senza cambiare la lingua del sito.') ?></p>
    <?php foreach ($translationLocales as $translationLocale => $translationName):
      $translationQuery = $translationEditorQuery;
      $translationQuery['content_locale'] = $translationLocale;
      $translationUrl = url($translationEditorPath) . '?' . http_build_query($translationQuery) . '#' . rawurlencode($translationEditorFragment);
      ?>
      <a href="<?= $translationEsc($translationUrl) ?>"<?= $translationLocale === $translationEditorLocale ? ' aria-current="page"' : '' ?>>
        <?= $translationEsc($translationName) ?>
        <?php if ($translationLocale === $translationEditorLocale): ?><i class="fas fa-check" aria-hidden="true"></i><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</details>
