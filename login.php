
<?php
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

if (!empty($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

if (empty($_SESSION["login_csrf"])) {
    $_SESSION["login_csrf"] = bin2hex(random_bytes(32));
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) ||
        !hash_equals($_SESSION["login_csrf"], $token)) {
        $error = "Invalid form submission. Refresh and try again.";
    } else {
        $email = trim($_POST["email"] ?? "");
        $password = $_POST["password"] ?? "";

        $stmt = $pdo->prepare(
            "SELECT id, name, email, password, role, status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (
            $user &&
            $user["status"] === "active" &&
            password_verify($password, $user["password"])
        ) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = (int) $user["id"];
            $_SESSION["user_name"] = $user["name"];
            $_SESSION["user_email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            unset($_SESSION["login_csrf"]);

            header("Location: dashboard.php");
            exit;
        }

        $error = "Invalid email or password, or the account is inactive.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Employee Management</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <h1>Employee Management System</h1>
    <h2>Sign in</h2>

    <?php if ($error !== ""): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["login_csrf"]) ?>"
        >

        <label for="email">Email address</label>
        <input id="email" type="email" name="email"
               autocomplete="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password"
               autocomplete="current-password" required>

        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
