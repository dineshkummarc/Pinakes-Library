<?php
/**
 * Book Club — public leaderboard page (members/managers): full XP ranking
 * with level and badges per member, the viewer's own position, the XP
 * formula legend and the badge catalogue.
 *
 * Data comes from bookclub_xp_snapshot, recomputed from the club's activity
 * tables at most once per hour (lazily on view + maintenance tick).
 *
 * @var array<string, mixed> $club
 * @var list<array{rank: int, user_id: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>, is_me: bool}> $ranking
 * @var array{rank: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>}|null $me
 * @var list<array<string, mixed>> $allBadges
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$xpRules = [
    [__('Libro concluso'), \App\Plugins\BookClub\GamificationRepo::XP_FINISHED_BOOK, 'fa-flag-checkered'],
    [__('Proposta di lettura accettata'), \App\Plugins\BookClub\GamificationRepo::XP_PROPOSAL, 'fa-lightbulb'],
    [__('Presenza confermata a un incontro'), \App\Plugins\BookClub\GamificationRepo::XP_RSVP_YES, 'fa-calendar-check'],
    [__('Voto espresso in una votazione'), \App\Plugins\BookClub\GamificationRepo::XP_VOTE, 'fa-vote-yea'],
    [__('Post scritto nelle discussioni'), \App\Plugins\BookClub\GamificationRepo::XP_POST, 'fa-comments'],
];
?>
<?php $bookClubStyles = true; ?>
<div class="container py-4">
  <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)) ?>" class="bc-muted no-underline">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-3 mb-4">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Classifica')) ?> — <?= $e($club['name']) ?></h1>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($me !== null): ?>
    <!-- My position -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-user"></i>
        <h2><?= $e(__('La tua posizione')) ?></h2>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center justify-center shrink-0 text-white font-bold"
             style="width:56px;height:56px;border-radius:2px;font-size:1.25rem;background: <?= $e($club['color']) ?>">
          <?= (int) $me['level'] ?>
        </div>
        <div>
          <div class="font-bold" style="font-size:1.1rem">#<?= (int) $me['rank'] ?> — <?= $e($me['name']) ?></div>
          <div class="bc-muted"><?= $e(sprintf(__('Livello %d'), (int) $me['level'])) ?> · <?= (int) $me['xp'] ?> XP</div>
        </div>
        <?php if ($me['badges'] !== []): ?>
          <div class="flex flex-wrap gap-2 ms-auto">
            <?php foreach ($me['badges'] as $badge): ?>
              <span class="bc-badge bc-badge-closed" title="<?= $e($badge['description']) ?>">
                <i class="fas <?= $e($badge['icon']) ?>" style="color:var(--warning-color)"></i><?= $e($badge['name']) ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Ranking -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-trophy"></i>
      <h2><?= $e(__('Classifica del club')) ?></h2>
    </div>
    <?php if ($ranking === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('La classifica è ancora vuota: i punti vengono calcolati dalle attività del club.')) ?></p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left align-middle mb-0">
          <tbody>
            <?php foreach ($ranking as $row): ?>
              <tr class="<?= $row['is_me'] ? 'table-active' : '' ?>">
                <td class="text-center" style="width:3rem">
                  <?php if ($row['rank'] <= 3): ?>
                    <i class="fas fa-medal" style="color:var(--warning-color)"></i>
                  <?php else: ?>
                    <span class="bc-muted font-semibold"><?= (int) $row['rank'] ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="font-medium">
                    <?= $e($row['name']) ?>
                    <?php if ($row['is_me']): ?>
                      <span class="bc-badge bc-badge-open ml-1"><?= $e(__('Tu')) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php if ($row['badges'] !== []): ?>
                    <span class="ml-2 whitespace-nowrap">
                      <?php foreach ($row['badges'] as $badge): ?>
                        <i class="fas <?= $e($badge['icon']) ?> mr-1 text-sm" style="color:var(--warning-color)" title="<?= $e($badge['name']) ?> — <?= $e($badge['description']) ?>"></i>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-center whitespace-nowrap bc-muted" style="width:6rem"><?= $e(sprintf(__('Livello %d'), (int) $row['level'])) ?></td>
                <td class="text-right whitespace-nowrap font-semibold" style="width:6rem"><?= (int) $row['xp'] ?> XP</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- XP formula -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-calculator"></i>
      <h2><?= $e(__('Come si guadagnano i punti')) ?></h2>
    </div>
    <div class="flex flex-wrap -mx-3 gap-y-2">
      <?php foreach ($xpRules as [$label, $xp, $icon]): ?>
        <div class="w-full px-3 md:w-1/2">
          <div class="flex items-center gap-3 text-sm">
            <i class="fas <?= $e($icon) ?> text-center" style="width:1.25rem;color:var(--text-muted)"></i>
            <span class="grow"><?= $e($label) ?></span>
            <span class="font-semibold whitespace-nowrap">+<?= (int) $xp ?> XP</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="bc-muted mt-3 mb-0 text-sm">
      <?= $e(__('Il livello si calcola come 1 + parte intera della radice quadrata di XP/100: livello 2 a 100 XP, livello 3 a 400 XP, livello 4 a 900 XP.')) ?>
      <?= $e(__('I punti vengono ricalcolati automaticamente al massimo una volta all\'ora.')) ?>
    </p>
  </section>

  <!-- Badge catalogue -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-award"></i>
      <h2><?= $e(__('Badge disponibili')) ?></h2>
    </div>
    <?php if ($allBadges === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun badge configurato.')) ?></p>
    <?php endif; ?>
    <div class="flex flex-wrap -mx-3 gap-y-3">
      <?php foreach ($allBadges as $badge): ?>
        <div class="w-full px-3 md:w-1/2">
          <div class="flex items-start gap-3 border p-3 h-full">
            <div class="flex items-center justify-center shrink-0"
                 style="width:2.25rem;height:2.25rem;border-radius:2px;background:var(--accent-color)">
              <i class="fas <?= $e($badge['icon']) ?>" style="color:var(--warning-color)"></i>
            </div>
            <div>
              <div class="font-semibold text-sm"><?= $e($badge['name']) ?></div>
              <div class="bc-muted text-sm"><?= $e($badge['description']) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
