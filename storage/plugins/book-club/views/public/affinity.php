<?php
/**
 * Book Club — affinity module page (members/managers): privacy opt-in
 * toggle, the member's ranked affinity list (opted-in members only) and
 * the club suggestions (unread catalog books by top finished genres +
 * authors to rediscover).
 *
 * @var array<string, mixed> $club
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $optedIn
 * @var int $optedInCount
 * @var list<array{name: string, score: int|null, computed_at: string}> $myAffinities
 * @var list<array{id: int, nome: string, n: int}> $topGenres
 * @var list<array<string, mixed>> $suggestedBooks
 * @var list<array{id: int, nome: string, unread_count: int}> $similarAuthors
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$heroTitle = __('Affinità e suggerimenti');
$heroSubtitle = '';
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Club di lettura'), 'href' => url(\App\Support\RouteTranslator::route('book_club'))],
    ['label' => (string) $club['name'], 'href' => url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)],
    ['label' => $heroTitle],
];
include $corePartials . '/catalog-hero.php';
?>
<?php $bookClubStyles = true; ?>
<div class="container py-4">
  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Section 1: reader affinity (opt-in) -->
  <section class="bc-card">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="bc-section-header mb-0">
        <i class="fas fa-people-arrows"></i>
        <h2><?= $e(__('Affinità tra lettori')) ?></h2>
      </div>
      <span class="bc-muted"><?= $e(sprintf(__('Membri con condivisione attiva: %d'), (int) $optedInCount)) ?></span>
    </div>

    <p class="bc-muted mt-3 mb-3">
      <i class="fas fa-lock mr-1"></i>
      <?= $e(__('Le affinità sono calcolate solo tra i membri che hanno attivato la condivisione: chi non aderisce non compare mai negli elenchi degli altri.')) ?>
    </p>

    <?php if ($isMember): ?>
      <form method="post" action="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/affinity/optin')) ?>" class="flex flex-wrap items-center gap-3 mb-4 border px-3 py-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <span class="text-sm font-semibold" style="color: <?= $optedIn ? 'var(--success-color)' : 'var(--text-light)' ?>">
          <i class="fas <?= $optedIn ? 'fa-toggle-on' : 'fa-toggle-off' ?> mr-1"></i>
          <?= $e($optedIn ? __('Condivisione attiva') : __('Condivisione disattivata')) ?>
        </span>
        <button type="submit" class="bc-btn bc-btn-sm<?= $optedIn ? ' bc-btn-outline' : '' ?>">
          <?= $e($optedIn ? __('Disattiva condivisione') : __('Attiva condivisione')) ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if ($optedIn): ?>
      <?php if ($myAffinities === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun altro membro ha attivato la condivisione, per ora.')) ?></p>
      <?php else: ?>
        <?php foreach ($myAffinities as $row): ?>
          <div class="flex flex-wrap -mx-3 items-center gap-y-2 mb-2">
            <div class="w-full px-3 md:w-1/3 text-sm truncate">
              <?= $e(sprintf(__('Affinità con %s'), (string) $row['name'])) ?>
            </div>
            <div class="flex-1 px-3">
              <div class="bc-progress">
                <?php if ($row['score'] !== null): ?>
                  <span style="width: <?= (int) $row['score'] ?>%; background: <?= $e($club['color']) ?>"></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="w-1/4 px-3 text-right text-sm md:w-1/6 <?= $row['score'] !== null ? 'font-semibold' : 'bc-muted' ?>">
              <?= $row['score'] !== null ? (int) $row['score'] . '%' : $e(__('dati insufficienti')) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php elseif ($isMember): ?>
      <p class="bc-muted mb-0"><?= $e(__('Attiva la condivisione per scoprire la tua affinità di lettura con gli altri membri.')) ?></p>
    <?php endif; ?>
  </section>

  <!-- Section 2: club suggestions -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-lightbulb"></i>
      <h2><?= $e(__('Suggerimenti per il club')) ?></h2>
    </div>

    <?php if ($topGenres === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun suggerimento disponibile: il club non ha ancora concluso letture con un genere assegnato.')) ?></p>
    <?php else: ?>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <span class="bc-muted uppercase"><?= $e(__('Generi più letti dal club')) ?>:</span>
        <?php foreach ($topGenres as $genre): ?>
          <span class="bc-badge" style="background: <?= $e($club['color']) ?>; color: #fff">
            <?= $e($genre['nome']) ?> · <?= (int) $genre['n'] ?>
          </span>
        <?php endforeach; ?>
      </div>

      <?php if ($suggestedBooks === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Il club ha già letto tutti i libri in catalogo per i suoi generi preferiti.')) ?></p>
      <?php else: ?>
        <h3 class="mb-2 text-base font-bold uppercase"><?= $e(__('Libri dal catalogo che il club non ha ancora letto')) ?></h3>
        <div class="mb-3">
          <?php foreach ($suggestedBooks as $book): ?>
            <div class="bc-list-item">
              <?php if (!empty($book['copertina_url'])): ?>
                <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover shrink-0" loading="lazy">
              <?php else: ?>
                <div class="bc-cover shrink-0 bg-gray-100 flex items-center justify-center"><i class="fas fa-book bc-muted"></i></div>
              <?php endif; ?>
              <div class="grow">
                <p class="mb-0 font-semibold"><?= $e($book['titolo']) ?></p>
                <p class="mb-0 bc-muted">
                  <?= $e((string) ($book['autori'] ?? '')) ?>
                  <?php if (!empty($book['anno_pubblicazione'])): ?> · <?= (int) $book['anno_pubblicazione'] ?><?php endif; ?>
                  · <?= $e($book['genere']) ?>
                </p>
              </div>
              <div class="bc-muted whitespace-nowrap shrink-0 pt-1">
                <?php if ($book['rating'] !== null): ?>
                  <i class="fas fa-star" style="color: var(--warning-color)"></i> <?= (int) $book['rating'] ?>/5
                <?php else: ?>
                  —
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="bc-muted mb-0">
          <i class="fas fa-hand-point-right mr-1"></i><?= $e(__('Ti piace un titolo? Proponilo al club dalla pagina principale.')) ?>
          <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)) ?>" class="font-semibold"><?= $e(__('Vai alla pagina del club')) ?></a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Authors to rediscover -->
  <?php if ($similarAuthors !== []): ?>
    <section class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-feather-alt"></i>
        <h2><?= $e(__('Autori da riscoprire')) ?></h2>
      </div>
      <p class="bc-muted mb-3"><?= $e(__('Autori dei libri conclusi con altri titoli in catalogo non ancora letti dal club.')) ?></p>
      <div>
        <?php foreach ($similarAuthors as $author): ?>
          <div class="bc-list-item items-center">
            <span class="text-sm truncate"><?= $e($author['nome']) ?></span>
            <span class="bc-muted whitespace-nowrap shrink-0">
              <?= $e(sprintf(__('%d libri non ancora letti dal club'), (int) $author['unread_count'])) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
