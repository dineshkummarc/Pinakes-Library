<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\CustomRoleRepository;
use App\Support\Permissions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RolesController
{
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        if (!$this->isAdministrator($db)) { return $response->withStatus(403); }
        $roles = (new CustomRoleRepository($db))->all();
        $labels = Permissions::labels();
        ob_start();
        require __DIR__ . '/../Views/utenti/ruoli.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response;
    }

    public function save(Request $request, Response $response, \mysqli $db): Response
    {
        if (!$this->isAdministrator($db)) { return $response->withStatus(403); }
        $data = (array) $request->getParsedBody();
        try {
            if (!is_array($data['roles'] ?? []) || !is_array($data['new_role'] ?? [])) {
                throw new \InvalidArgumentException(__('Ruolo non valido.'));
            }
            (new CustomRoleRepository($db))->save($data['roles'] ?? [], $data['new_role'] ?? []);
            $_SESSION['success_message'] = __('Ruoli e permessi aggiornati.');
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\mysqli_sql_exception $e) {
            $_SESSION['error_message'] = match ((int) $e->getCode()) {
                1062 => __('Esiste già un ruolo con questo nome.'),
                1451 => __('Non puoi eliminare un ruolo assegnato a utenti. Assegna prima un altro ruolo.'),
                default => __('Impossibile salvare i ruoli. Riprova.'),
            };
            \App\Support\SecureLogger::error('[Roles] save failed', ['errno' => $e->getCode()]);
        } catch (\Throwable $e) {
            // The repository has rolled the batch back; say so instead of a bare 500.
            $_SESSION['error_message'] = __('Impossibile salvare i ruoli. Riprova.');
            \App\Support\SecureLogger::error('[Roles] save failed', ['error' => $e->getMessage()]);
        }
        return $response->withHeader('Location', url('/admin/roles'))->withStatus(302);
    }

    private function isAdministrator(\mysqli $db): bool
    {
        return \App\Support\SessionRoleRevalidator::holdsActiveRole(['admin'], $db);
    }
}
