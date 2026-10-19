<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Permissions;

final class CustomRoleRepository
{
    public function __construct(private \mysqli $db) {}

    public function all(): array
    {
        $result = $this->db->query('SELECT r.id, r.nome, r.permessi, COUNT(u.id) AS utenti
            FROM ruoli r LEFT JOIN utenti u ON u.ruolo_id = r.id GROUP BY r.id, r.nome, r.permessi ORDER BY r.id');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            try {
                $row['permissions'] = Permissions::normalize(json_decode((string) $row['permessi'], true, 32, JSON_THROW_ON_ERROR));
            } catch (\JsonException | \InvalidArgumentException) {
                // A damaged row (hand-edited, or a capability a newer version added) grants
                // nothing, as in Permissions::hydrate(), instead of taking the page down.
                $row['permissions'] = [];
            }
            $row['display_name'] = \App\Support\RolePresets::displayName($row['nome']);
            $row['description'] = \App\Support\RolePresets::description($row['nome']);
            $rows[] = $row;
        }
        return $rows;
    }

    /** A batch either changes the entire matrix or changes nothing. */
    public function save(array $rows, array $new): void
    {
        if (count($rows) > 100) { throw new \InvalidArgumentException(__('Troppi ruoli.')); }
        $this->db->begin_transaction();
        try {
            foreach ($rows as $id => $row) {
                if (!ctype_digit((string) $id) || (int) $id <= 0 || !is_array($row)) {
                    throw new \InvalidArgumentException(__('Ruolo non valido.'));
                }
                $roleId = (int) $id;
                $lock = $this->db->prepare('SELECT nome FROM ruoli WHERE id = ? FOR UPDATE');
                $lock->bind_param('i', $roleId);
                $lock->execute();
                $original = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!is_array($original)) { throw new \InvalidArgumentException(__('Ruolo non valido.')); }
                if (($row['delete'] ?? '') === '1') {
                    // FK RESTRICT also protects against a concurrent assignment.
                    $stmt = $this->db->prepare('DELETE FROM ruoli WHERE id = ?');
                    $stmt->bind_param('i', $roleId);
                } else {
                    [$name, $json] = $this->validated($row);
                    $name = \App\Support\RolePresets::storedName($name, $original['nome']);
                    $stmt = $this->db->prepare('UPDATE ruoli SET nome = ?, permessi = ? WHERE id = ?');
                    $stmt->bind_param('ssi', $name, $json, $roleId);
                }
                $stmt->execute();
                $stmt->close();
            }
            if (($new['nome'] ?? '') === '' && !empty($new['permissions'])) {
                throw new \InvalidArgumentException(__('Nome del ruolo non valido.'));
            }
            if (($new['nome'] ?? '') !== '') {
                [$name, $json] = $this->validated($new);
                $stmt = $this->db->prepare('INSERT INTO ruoli (nome, permessi) VALUES (?, ?)');
                $stmt->bind_param('ss', $name, $json);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function validated(array $row): array
    {
        if (!is_string($row['nome'] ?? null)) { throw new \InvalidArgumentException(__('Nome del ruolo non valido.')); }
        $name = trim($row['nome']);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \InvalidArgumentException(__('Nome del ruolo non valido.'));
        }
        $permissions = Permissions::normalize($row['permissions'] ?? []);
        return [$name, json_encode($permissions, JSON_THROW_ON_ERROR)];
    }
}
