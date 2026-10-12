<?php
/**
 * Book Club — discussions: thread list (pinned first, then by activity)
 * plus the new-thread form for members.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $threads
 * @var list<array<string, mixed>> $books     club books (non-pending)
 * @var list<array<string, mixed>> $sections  reading sections ([] when the reading module is absent)
 * @var bool $isMember
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$kindLabels = [
    'general' => __('Generale'),
    'chapter' => __('Capitolo'),
    'character' => __('Personaggio'),
    'free' => __('Libera'),
    'announcement' => __('Annuncio'),
];
$kindBadges = [
    'general' => 'bc-badge bc-badge-closed',
    'chapter' => 'bc-badge bc-badge-closed',
    'character' => 'bc-badge bc-badge-closed',
    'free' => 'bc-badge bc-badge-closed',
    'announcement' => 'bc-badge bc-badge-warn',
];
?>
<?php $bookClubStyles = true; ?>
<div class="container py-4">
  <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)) ?>" class="bc-muted no-underline">
    <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
  </a>

  <div class="flex items-center justify-between mt-3 mb-4">
    <h1 class="h2 font-bold mb-0"><?= $e(__('Discussioni')) ?></h1>
    <span class="bc-muted"><?= count($threads) ?> <?= $e(__n('discussione', 'discussioni', count($threads))) ?></span>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($isMember || $canManage): ?>
    <!-- New thread -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-comment-medical"></i>
        <h2><?= $e(__('Apri una nuova discussione')) ?></h2>
      </div>
      <form method="post" action="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/discussions/new')) ?>">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="title" required maxlength="190"
               placeholder="<?= $e(__('Titolo della discussione')) ?>"
               class="form-input mb-3">
        <div class="flex flex-wrap -mx-3 gap-y-3">
          <div class="w-full px-3 md:w-1/3">
            <select name="kind" class="form-input">
              <?php foreach ($kindLabels as $value => $label): ?>
                <?php if ($value === 'announcement' && !$canManage) { continue; } ?>
                <option value="<?= $e($value) ?>" <?= $value === 'free' ? 'selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="w-full px-3 md:w-1/3">
            <select name="club_book_id" class="form-input">
              <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($sections !== []): ?>
            <div class="w-full px-3 md:w-1/3">
              <select name="section_id" class="form-input">
                <option value=""><?= $e(__('Nessuna sezione collegata')) ?></option>
                <?php foreach ($sections as $section): ?>
                  <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <button type="submit" class="bc-btn mt-3"><?= $e(__('Apri discussione')) ?></button>
      </form>
    </section>
  <?php endif; ?>

  <!-- Thread list -->
  <section class="bc-card">
    <?php if ($threads === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna discussione: apri la prima!')) ?></p>
    <?php endif; ?>
    <?php foreach ($threads as $thread): ?>
      <div class="bc-list-item">
        <div style="min-width: 0">
          <div class="flex items-center gap-2 flex-wrap">
            <?php if ((int) $thread['is_pinned'] === 1): ?>
              <i class="fas fa-thumbtack text-sm" style="color: var(--warning-color)" title="<?= $e(__('In evidenza')) ?>"></i>
            <?php endif; ?>
            <?php if ((int) $thread['is_locked'] === 1): ?>
              <i class="fas fa-lock text-sm bc-muted" title="<?= $e(__('Bloccata')) ?>"></i>
            <?php endif; ?>
            <a class="font-semibold no-underline" style="color: var(--primary-color)"
               href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/discussions/' . (int) $thread['id'])) ?>"><?= $e($thread['title']) ?></a>
            <span class="<?= $e($kindBadges[$thread['kind']] ?? 'bc-badge bc-badge-closed') ?>">
              <?= $e($kindLabels[$thread['kind']] ?? $thread['kind']) ?>
            </span>
          </div>
          <div class="bc-muted mt-1">
            <?php if (!empty($thread['book_title'])): ?>
              <i class="fas fa-book mr-1"></i><?= $e($thread['book_title']) ?>
              <?php if (!empty($thread['section_title'])): ?> · <?= $e($thread['section_title']) ?><?php endif; ?>
              ·
            <?php endif; ?>
            <?php if (!empty($thread['creator_nome'])): ?>
              <?= $e(__('aperta da')) ?> <?= $e(trim($thread['creator_nome'] . ' ' . $thread['creator_cognome'])) ?> ·
            <?php endif; ?>
            <?= $e(__('ultima attività')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $thread['last_activity']))) ?>
          </div>
        </div>
        <div class="bc-muted text-right whitespace-nowrap ml-3">
          <?= (int) $thread['post_count'] ?> <?= $e(__n('messaggio', 'messaggi', (int) $thread['post_count'])) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>
