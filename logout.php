
<?php
require_once __DIR__ . "/includes/auth.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

$token = $_POST["csrf_token"] ?? "";
$expected = $_SESSION["logout_csrf"] ?? "";

if (
    !is_string($token) ||
    $expected === "" ||
    !hash_equals($expected, $token)
) {
    http_response_code(403);
    exit("Invalid request. Return to the dashboard and try again.");
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(session_name(), "", [
        "expires" => time() - 42000,
        "path" => $params["path"],
        "domain" => $params["domain"],
        "secure" => $params["secure"],
        "httponly" => $params["httponly"],
        "samesite" => $params["samesite"] ?? "Lax"
    ]);
}

session_destroy();

header("Location: login.php");
exit;
