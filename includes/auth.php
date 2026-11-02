<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Session Configuration
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| Require Login
|--------------------------------------------------------------------------
*/

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {

        $currentPath = $_SERVER['PHP_SELF'] ?? '';

        $isSubFolder =
            strpos($currentPath, '/patient/') !== false ||
            strpos($currentPath, '/doctor/') !== false ||
            strpos($currentPath, '/admin/') !== false;

        $loginPath = $isSubFolder
            ? '../login.php'
            : 'login.php';

        header('Location: ' . $loginPath);

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Require Specific Role
|--------------------------------------------------------------------------
*/

function require_role(string $role): void
{
    require_login();

    $currentRole = (string)(
        $_SESSION['role'] ?? ''
    );

    if ($currentRole !== $role) {

        http_response_code(403);

        exit('403 - Access denied');
    }
}


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $userLoaded = false;
    static $user = null;

    if ($userLoaded) {
        return $user;
    }

    $userLoaded = true;

    try {
        require_once dirname(__DIR__) . '/includes/mongodb_helpers.php';

        $document = mongoCollection('users')->findOne(
            ['user_id' => (int) $_SESSION['user_id']],
            [
                'projection' => [
                    '_id' => 0,
                    'user_id' => 1,
                    'name' => 1,
                    'email' => 1,
                    'role' => 1,
                    'status' => 1,
                    'created_at' => 1,
                ],
            ]
        );

        $user = $document === null
            ? null
            : $document->getArrayCopy();

    } catch (Throwable $e) {
        error_log(
            'Current User MongoDB Error: ' . $e->getMessage()
        );

        $user = null;
    }

    return $user;
}


/*
|--------------------------------------------------------------------------
| Current User ID
|--------------------------------------------------------------------------
*/

function current_user_id(): ?int
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $userId = (int)$_SESSION['user_id'];

    return $userId > 0
        ? $userId
        : null;
}


/*
|--------------------------------------------------------------------------
| Current User Role
|--------------------------------------------------------------------------
*/

function current_user_role(): ?string
{
    if (empty($_SESSION['role'])) {
        return null;
    }

    return (string)$_SESSION['role'];
}


/*
|--------------------------------------------------------------------------
| Check Authentication
|--------------------------------------------------------------------------
*/

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}


/*
|--------------------------------------------------------------------------
| Check Role
|--------------------------------------------------------------------------
*/

function has_role(string $role): bool
{
    return is_logged_in()
        && (
            (string)($_SESSION['role'] ?? '') === $role
        );
}