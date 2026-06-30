<?php

declare(strict_types=1);

use BB\Database\DB;
use BB\Http\Response;
use BB\Support\Env;

session_start();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/admin';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function admin_layout(string $title, string $content): void
{
    $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <style>
    body{margin:0;font-family:Arial,sans-serif;background:#0d141f;color:#dce2f2}
    a{color:#f4cc76;text-decoration:none}
    .wrap{max-width:1100px;margin:0 auto;padding:24px}
    .nav{display:flex;gap:16px;align-items:center;margin-bottom:24px;border-bottom:1px solid #2b3545;padding-bottom:16px}
    .card{background:#151f2d;border:1px solid #2b3545;border-radius:12px;padding:18px;margin-bottom:16px}
    table{width:100%;border-collapse:collapse;background:#151f2d;border-radius:12px;overflow:hidden}
    th,td{padding:12px;border-bottom:1px solid #2b3545;text-align:left;vertical-align:top}
    th{color:#f4cc76;font-size:12px;text-transform:uppercase}
    input,select{background:#0d141f;color:#dce2f2;border:1px solid #2b3545;border-radius:8px;padding:10px;width:100%;box-sizing:border-box}
    button{background:#f4cc76;color:#0d141f;border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
    .muted{color:#8d98aa}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="nav">
      <strong>Borderless Bazzar Admin</strong>
      <a href="/admin">Dashboard</a>
      <a href="/admin/orders">Orders</a>
      <a href="/admin/quotes">Quotes</a>
      <a href="/admin/scraped-products">Scraped Products</a>
      <span style="flex:1"></span>
      <a href="/admin/logout">Logout</a>
    </div>
    {$content}
  </div>
</body>
</html>
HTML;
}

function admin_require(): array
{
    if (empty($_SESSION['admin_id'])) {
        Response::redirect('/admin/login');
        exit;
    }

    $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE id = ? AND role = "admin" LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        Response::redirect('/admin/login');
        exit;
    }
    return $user;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($path === '/admin/login') {
    if ($method === 'POST') {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE email = ? AND role = "admin" LIMIT 1');
        $stmt->execute([strtolower($_POST['email'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            $_SESSION['admin_id'] = $user['id'];
            Response::redirect('/admin');
            return;
        }
        $error = '<p style="color:#ffb4ab">Invalid admin credentials.</p>';
    }

    $content = ($error ?? '') . '<div class="card" style="max-width:420px"><h1>Admin Login</h1><form method="post"><p><input name="email" type="email" placeholder="Email" value="' . h(Env::get('ADMIN_EMAIL', '')) . '"></p><p><input name="password" type="password" placeholder="Password"></p><button>Login</button></form></div>';
    admin_layout('Admin Login', $content);
    return;
}

if ($path === '/admin/logout') {
    session_destroy();
    Response::redirect('/admin/login');
    return;
}

admin_require();

if ($path === '/admin') {
    $counts = [];
    foreach (['users', 'orders', 'quote_requests', 'scraped_products'] as $table) {
        $counts[$table] = DB::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    $content = '<h1>Dashboard</h1><div class="grid">';
    foreach ($counts as $label => $count) {
        $content .= '<div class="card"><div class="muted">' . h(str_replace('_', ' ', strtoupper($label))) . '</div><h2>' . h($count) . '</h2></div>';
    }
    $content .= '</div>';
    admin_layout('Dashboard', $content);
    return;
}

if ($path === '/admin/orders') {
    if ($method === 'POST') {
        DB::pdo()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$_POST['status'] ?? 'pending', (int) ($_POST['id'] ?? 0)]);
        Response::redirect('/admin/orders');
        return;
    }
    $rows = DB::pdo()->query('SELECT orders.*, users.email FROM orders JOIN users ON users.id = orders.user_id ORDER BY orders.id DESC LIMIT 100')->fetchAll();
    $content = '<h1>Orders</h1><table><tr><th>Order</th><th>User</th><th>Status</th><th>Total</th><th>Created</th><th>Update</th></tr>';
    foreach ($rows as $row) {
        $content .= '<tr><td>' . h($row['order_number']) . '</td><td>' . h($row['email']) . '</td><td>' . h($row['status']) . '</td><td>NPR ' . h(number_format((int) $row['total_npr'])) . '</td><td>' . h($row['created_at']) . '</td><td><form method="post"><input type="hidden" name="id" value="' . h($row['id']) . '"><select name="status"><option>pending</option><option>confirmed</option><option>paid</option><option>processing</option><option>shipped</option><option>delivered</option><option>cancelled</option></select><button>Save</button></form></td></tr>';
    }
    $content .= '</table>';
    admin_layout('Orders', $content);
    return;
}

if ($path === '/admin/quotes') {
    $rows = DB::pdo()->query('SELECT quote_requests.*, users.email FROM quote_requests LEFT JOIN users ON users.id = quote_requests.user_id ORDER BY quote_requests.id DESC LIMIT 100')->fetchAll();
    $content = '<h1>Quote Requests</h1><table><tr><th>Product</th><th>User</th><th>Price</th><th>Status</th><th>Created</th></tr>';
    foreach ($rows as $row) {
        $content .= '<tr><td><strong>' . h($row['product_name']) . '</strong><br><a href="' . h($row['product_url']) . '">' . h($row['product_url']) . '</a></td><td>' . h($row['email'] ?? 'Guest') . '</td><td>' . h($row['currency']) . ' ' . h($row['unit_price']) . '</td><td>' . h($row['status']) . '</td><td>' . h($row['created_at']) . '</td></tr>';
    }
    $content .= '</table>';
    admin_layout('Quotes', $content);
    return;
}

if ($path === '/admin/scraped-products') {
    $rows = DB::pdo()->query('SELECT * FROM scraped_products ORDER BY id DESC LIMIT 100')->fetchAll();
    $content = '<h1>Scraped Products</h1><table><tr><th>Product</th><th>Host</th><th>Price</th><th>Confidence</th><th>Updated</th></tr>';
    foreach ($rows as $row) {
        $content .= '<tr><td><strong>' . h($row['title']) . '</strong><br><a href="' . h($row['canonical_url'] ?: $row['url']) . '">' . h($row['canonical_url'] ?: $row['url']) . '</a></td><td>' . h($row['source_host']) . '</td><td>' . h($row['currency']) . ' ' . h($row['price']) . '</td><td>' . h($row['confidence']) . '</td><td>' . h($row['updated_at']) . '</td></tr>';
    }
    $content .= '</table>';
    admin_layout('Scraped Products', $content);
    return;
}

admin_layout('Not Found', '<h1>Not found</h1>');
