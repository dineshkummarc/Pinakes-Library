<?php
// Privilege assignment is deliberately reserved to full administrators.
if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') { return; }
$account = $utente ?? [];
$roleChoices = $roleChoices ?? [];
?>
<div class="space-y-3">
  <label for="ruolo_id" class="block text-sm font-medium text-gray-700"><?= __('Ruolo personalizzato') ?></label>
  <select id="ruolo_id" name="ruolo_id" class="form-input">
    <option value=""><?= __('Nessun ruolo personalizzato') ?></option>
    <?php foreach ($roleChoices as $customRole): ?>
    <option value="<?= (int) $customRole['id'] ?>" <?= (int) ($account['ruolo_id'] ?? 0) === (int) $customRole['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $customRole['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
  </select>
  <p class="text-sm text-gray-500"><?= __('Il ruolo personalizzato si applica agli account Standard o Premium. Amministratore e Staff conservano tutti i propri permessi.') ?> <a href="<?= htmlspecialchars(url('/admin/roles'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Ruoli e permessi') ?></a></p>
  <input type="hidden" name="permissions_present" value="1">
  <label class="flex items-start gap-3"><input type="checkbox" name="accesso_digitale" value="1" <?= !empty($account['accesso_digitale']) ? 'checked' : '' ?>><span><?= __('Consenti di aprire gli allegati digitali riservati') ?><small class="block text-gray-500"><?= __('Vale anche senza accesso al backend. Non permette di caricare o modificare file.') ?></small></span></label>
</div>
