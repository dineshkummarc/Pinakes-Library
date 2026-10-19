<?php
declare(strict_types=1);

namespace App\Support;

final class UserRoleAssignment
{
    /** Return values to bind in the SAME user INSERT/UPDATE, after validation. */
    public static function fromSubmission(array $data, ?array $original, \mysqli $db): array
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin' || !isset($data['permissions_present'])) {
            return [$original['ruolo_id'] ?? null, (int) ($original['accesso_digitale'] ?? 0)];
        }
        $value = $data['ruolo_id'] ?? '';
        if (!is_scalar($value) || ($value !== '' && !ctype_digit((string) $value))) {
            throw new \InvalidArgumentException(__('Ruolo non valido.'));
        }
        $id = (int) $value;
        if ($id > 0) {
            $stmt = $db->prepare('SELECT id FROM ruoli WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$exists) { throw new \InvalidArgumentException(__('Ruolo non valido.')); }
        }
        return [$id > 0 ? $id : null, ($data['accesso_digitale'] ?? '') === '1' ? 1 : 0];
    }

    /** A user manager cannot reset operator passwords or edit their own privileges. */
    public static function canEdit(array $target): bool
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') === 'admin') { return true; }
        if (($_SESSION['user']['tipo_utente'] ?? '') === 'staff') {
            return (int) ($target['id'] ?? 0) === (int) ($_SESSION['user']['id'] ?? 0);
        }
        return Permissions::can('users.manage')
            && in_array($target['tipo_utente'] ?? '', ['standard', 'premium'], true)
            && empty($target['ruolo_id']) && empty($target['accesso_digitale']);
    }
}
