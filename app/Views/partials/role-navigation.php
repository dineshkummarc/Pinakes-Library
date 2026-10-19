<?php
use App\Support\Permissions;
use App\Support\HtmlHelper;
$links = [
    'backend.access' => ['/admin/dashboard', __('Il tuo spazio di lavoro')],
    'catalog.manage' => ['/admin/books', __('Catalogazione')],
    'loans.manage' => ['/admin/loans', __('Prestiti')],
    'users.manage' => ['/admin/users', __('Utenti')],
    'settings.manage' => ['/admin/settings', __('Impostazioni')],
];
foreach ($links as $permission => [$path, $label]):
    if (!Permissions::can($permission)) { continue; }
?>
<a class="nav-link group flex items-center px-4 py-3 rounded-lg text-gray-700" href="<?= HtmlHelper::e(url($path)) ?>"><?= HtmlHelper::e($label) ?></a>
<?php endforeach; ?>
