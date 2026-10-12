<?php
/**
 * Same Choices.js chips and Enter-to-add interaction as the author field.
 * Included twice by form.php, which sets $creditKind before each include.
 *
 * @var string $creditKind                    'publisher' or 'director'
 * @var array<string, mixed> $values
 * @var array<int, string> $editori           id => nome
 * @var callable(mixed): string $e
 * @var callable(string): string $val
 * @var callable(string): ?string $err
 */
$isPublisher = $creditKind === 'publisher';
$creditId = $isPublisher ? 'periodical-publishers' : 'periodical-directors';
$creditLabel = $isPublisher ? __('Editore') : __('Direttore responsabile');
$creditError = $isPublisher ? $err('editori_ids') ?? $err('editore_id') : $err('direttore_responsabile');
$creditInitial = [];
if ($isPublisher) {
    $selected = $values['editori_ids'] ?? (!empty($values['editore_id']) ? [(int)$values['editore_id']] : []);
    foreach (is_array($selected) ? $selected : [] as $item) {
        if (!is_scalar($item)) { continue; }
        $item = (string)$item;
        $name = str_starts_with($item, 'new:') ? substr($item, 4) : ($editori[(int)$item] ?? '');
        if ($name !== '') { $creditInitial[] = ['value'=>$item, 'label'=>$name]; }
    }
} else {
    foreach (array_filter(array_map('trim', explode(';', (string)($values['direttore_responsabile'] ?? '')))) as $name) {
        $creditInitial[] = ['value'=>$name, 'label'=>$name];
    }
}
$creditFits = count($creditInitial) <= 20;
foreach ($creditInitial as $item) { if (mb_strlen($item['label']) > 255) { $creditFits = false; } }
?>
<div id="<?= $creditId ?>" data-periodical-credit="<?= $isPublisher ? 'publisher' : 'director' ?>">
<label for="<?= $isPublisher ? $creditId.'-select' : 'direttore_responsabile' ?>" class="form-label"><?= $e($creditLabel) ?></label>
<?php if ($isPublisher): ?>
<input type="hidden" name="editori_present" value="1">
<select name="editori_ids[]" id="<?= $creditId ?>-select" multiple class="form-input <?= $creditError ? 'border-red-500' : '' ?>">
<?php // Selected options must appear in credit order, not registry alphabetical order. ?>
<?php foreach ($creditInitial as $item): ?>
<option value="<?= $e($item['value']) ?>" selected><?= $e($item['label']) ?></option>
<?php endforeach; ?>
<?php foreach ($editori as $eid=>$name): if (in_array((string)$eid,array_column($creditInitial,'value'),true)) { continue; } ?>
<option value="<?= (int)$eid ?>"><?= $e($name) ?></option>
<?php endforeach; ?>
</select>
<?php else: ?>
<div data-credit-picker hidden><select id="<?= $creditId ?>-select" multiple>
<?php foreach ($creditInitial as $item): ?>
<option value="<?= $e($item['value']) ?>" selected><?= $e($item['label']) ?></option>
<?php endforeach; ?>
</select></div>
<div data-credit-fallback><input type="text" name="direttore_responsabile" id="direttore_responsabile" value="<?= $val('direttore_responsabile') ?>" maxlength="65535" class="form-input">
<p class="text-xs text-gray-500 mt-1"><?= __('Separa più nomi con un punto e virgola.') ?></p></div>
<?php endif; ?>
<p class="text-xs text-gray-500 mt-1" data-credit-help hidden><?= $isPublisher ? __('Cerca editori esistenti o aggiungine di nuovi...') : __('Cerca un nome o scrivilo e premi Invio per aggiungerlo.') ?></p>
<p class="text-xs text-red-600 mt-1" data-credit-search-error role="alert" hidden><?= __('Ricerca non disponibile. Riprova.') ?></p>
<?php if ($creditError): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= $e($creditError) ?></p><?php endif; ?>
<script type="application/json" data-credit-config><?= json_encode([
    'fits'=>$creditFits,
    'searchUrl'=>url($isPublisher ? '/api/search/editori' : '/api/search/autori'),
    'placeholder'=>$isPublisher ? __('Cerca editori esistenti o aggiungine di nuovi...') : __('Cerca un nome o scrivilo e premi Invio per aggiungerlo.'),
    'noChoices'=>$isPublisher ? __('Nessun editore trovato, premi Invio per aggiungerne uno nuovo') : __('Scrivi il nome e premi Invio per aggiungerlo.'),
    'select'=>__('Clicca per selezionare'),
    'limit'=>$isPublisher ? __('Puoi selezionare al massimo 20 editori.') : __('Puoi inserire al massimo 20 direttori responsabili.'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
