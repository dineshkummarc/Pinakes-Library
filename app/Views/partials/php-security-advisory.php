<?php
/**
 * Red notice for administrators when the server's PHP lacks a published security
 * fix (see App\Support\PhpSecurityAdvisory). Optional $phpAdvisoryClass sets the
 * outer spacing so the box fits the page that includes it.
 */
if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
    return;
}
$phpAdvisory = \App\Support\PhpSecurityAdvisory::check();
if ($phpAdvisory === null) {
    return;
}
$phpAdvisoryClass = $phpAdvisoryClass ?? 'mb-6';
?>
<div class="<?= htmlspecialchars($phpAdvisoryClass, ENT_QUOTES, 'UTF-8') ?> p-4 bg-red-50 border border-red-200 rounded-xl" role="alert" data-php-security-advisory>
    <div class="flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-red-600 mt-0.5" aria-hidden="true"></i>
        <div>
            <p class="font-medium text-red-800"><?= htmlspecialchars(sprintf(__('PHP %s ha vulnerabilità critiche: aggiornalo a %s o successivo'), $phpAdvisory['current'], $phpAdvisory['fixed']), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-sm text-red-700 mt-1"><?= htmlspecialchars(sprintf(__('Due falle di PHP (%s) permettono di eseguire codice sul server senza accedere. Non dipendono da Pinakes ma dall’ambiente: si correggono aggiornando PHP dal pannello dell’hosting o con i pacchetti del sistema, e nessun aggiornamento di Pinakes può farlo al posto tuo.'), implode(', ', \App\Support\PhpSecurityAdvisory::CVES)), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-sm text-red-700 mt-1"><?= __('Finché non puoi aggiornare, impostare session.upload_progress.enabled = 0 nella configurazione di PHP riduce il rischio senza eliminarlo. Alcune distribuzioni applicano le correzioni senza cambiare il numero di versione: in quel caso verifica con il tuo fornitore.') ?></p>
            <p class="text-sm mt-1"><a href="<?= htmlspecialchars(\App\Support\PhpSecurityAdvisory::ADVISORY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-red-800 underline hover:text-red-900"><?= __('Avviso di sicurezza di PHP') ?></a></p>
        </div>
    </div>
</div>
