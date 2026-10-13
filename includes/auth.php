
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set("session.use_strict_mode", "1");

    session_set_cookie_params([
        "httponly" => true,
        "secure" => !empty($_SERVER["HTTPS"])
            && $_SERVER["HTTPS"] !== "off",
        "samesite" => "Lax",
        "path" => "/employee-management/"
    ]);

    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION["user_id"])) {
        header("Location: /employee-management/login.php");
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();

    if (!in_array($_SESSION["role"] ?? "", $roles, true)) {
        http_response_code(403);
        exit("403 Forbidden: You are not authorized to access this page.");
    }
}
