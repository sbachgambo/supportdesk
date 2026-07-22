<?php
declare(strict_types=1);

/**
 * Phase 6 test (§17.1) — Customers, uploads, categories.
 * Exit criteria: IDOR suite passes; shell.php→photo.jpg rejected; polyglot JPEG
 * neutralized; direct storage access blocked (structural); category delete blocked
 * by child / ticket / rule references; two-level nesting enforced.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Dispatch;
use App\Core\Request;
use App\Core\Session;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use App\Security\Rbac;
use App\Security\Upload;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 6');
    exit(T::summary());
}
Config::load($envTesting);

try {
    $pdo = Db::connect();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec((string) file_get_contents("$root/database/schema.sql"));
    $pdo->exec((string) file_get_contents("$root/database/seed.sql"));
} catch (Throwable $e) {
    T::ok(false, 'schema/seed load: ' . $e->getMessage());
    exit(T::summary());
}

// ── fixtures ─────────────────────────────────────────────────────────────────
// Use a project-local scratch dir, not %TEMP%. On Windows, freshly GD-written files
// in the system temp can be briefly locked by AV real-time scanning, making fopen/
// file_get_contents fail with EINVAL even though the file exists. storage/cache is safe.
$fix = str_replace('\\', '/', P3A_ROOT) . '/storage/cache/p3a_fix_' . bin2hex(random_bytes(4));
mkdir($fix);
$made = [];
// A BLANK truecolor image (no fill) — still a valid JPEG/PNG for MIME/re-encode/polyglot
// testing. On Windows, AV real-time scanning locks GD-written JPEGs that contain real
// image DATA (fopen → EINVAL); a blank image sidesteps that entirely. (Linux is unaffected.)
$mkImage = static function (string $path, string $type) {
    $img = imagecreatetruecolor(16, 16);
    match ($type) { 'jpg' => imagejpeg($img, $path, 90), 'png' => imagepng($img, $path), default => null };
    imagedestroy($img);
};
$validJpg = "$fix/photo.jpg";   $mkImage($validJpg, 'jpg');
$validPng = "$fix/pic.png";     $mkImage($validPng, 'png');
$shellAsJpg = "$fix/evil.jpg";  file_put_contents($shellAsJpg, "<?php system(\$_GET['c']); ?>\n");
$polyglot = "$fix/poly.jpg";    file_put_contents($polyglot, (string) file_get_contents($validJpg) . "<?php echo 'PWNED'; ?>");
$svg = "$fix/vector.svg";       file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

$cleanup = [];

// ── Upload security (§10.7) ──────────────────────────────────────────────────
T::suite('Phase 6: upload validation (§10.7)');
$r = Upload::process('photo.jpg', $validJpg);
T::ok($r['ok'] === true, 'valid JPEG accepted');
if ($r['ok']) {
    $cleanup[] = Upload::storageDir() . '/' . $r['stored_name'];
    T::eq('image/jpeg', $r['mime_type'], 'MIME detected from content (finfo), not $_FILES');
    T::ok(strlen((string) $r['stored_name']) === 32 && !str_contains((string) $r['stored_name'], '.'), 'stored name is 32 hex, no extension on disk');
}

$rp = Upload::process('pic.png', $validPng);
T::ok($rp['ok'] === true, 'valid PNG accepted');
if ($rp['ok']) { $cleanup[] = Upload::storageDir() . '/' . $rp['stored_name']; }

// shell.php renamed photo.jpg → content sniff disagrees with extension → rejected
$rs = Upload::process('photo.jpg', $shellAsJpg);
T::ok($rs['ok'] === false, 'shell.php renamed photo.jpg is REJECTED (content ≠ extension)');

// .php extension outright not allowed
$rphp = Upload::process('evil.php', $shellAsJpg);
T::ok($rphp['ok'] === false, '.php extension rejected by the allowlist');

// .svg excluded (XML that can carry script)
$rsvg = Upload::process('vector.svg', $svg);
T::ok($rsvg['ok'] === false, 'SVG rejected (excluded from allowlist)');

// polyglot JPEG+PHP → accepted as image, but GD re-encode STRIPS the payload
$rpoly = Upload::process('poly.jpg', $polyglot);
T::ok($rpoly['ok'] === true, 'polyglot JPEG passes the MIME check (valid JPEG header)');
if ($rpoly['ok']) {
    $stored = Upload::storageDir() . '/' . $rpoly['stored_name'];
    $cleanup[] = $stored;
    $content = (string) file_get_contents($stored);
    T::ok(!str_contains($content, '<?php') && !str_contains($content, 'PWNED'), 'polyglot NEUTRALIZED: re-encoded file contains no PHP payload');
}

// path traversal in the filename has nowhere to land
T::eq('passwd', Upload::sanitizeName('../../../etc/passwd'), 'filename sanitised to basename (no path traversal)');
T::eq('a b.txt', Upload::sanitizeName("a b.txt\r\n"), 'CR/LF stripped from filename');

// storage is structurally outside the webroot
$pub = realpath("$root/public");
$store = realpath(Upload::storageDir());
T::ok($store !== false && $pub !== false && !str_starts_with($store, $pub), 'storage/uploads is OUTSIDE public/ (no URL maps to it)');

// ── IDOR: customer ownership (D2) ────────────────────────────────────────────
T::suite('Phase 6: IDOR / ownership (D2)');
$custA = (int) Db::insert('users', ['public_id' => 'CU-9101', 'name' => 'Cust A', 'email' => 'a@example.com', 'password_hash' => 'x', 'role' => 'customer', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
$custB = (int) Db::insert('users', ['public_id' => 'CU-9102', 'name' => 'Cust B', 'email' => 'b@example.com', 'password_hash' => 'x', 'role' => 'customer', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
$agent = ['name' => 'Agent One', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];

$ta = TicketService::create(['subject' => 'A ticket', 'description' => 'body', 'customer_email' => 'a@example.com', 'customer_user_id' => $custA, 'priority' => 'normal'], 'agent', $agent)['ticket']['ticket_id'];
$tb = TicketService::create(['subject' => 'B ticket', 'description' => 'body', 'customer_email' => 'b@example.com', 'customer_user_id' => $custB, 'priority' => 'normal'], 'agent', $agent)['ticket']['ticket_id'];

// As customer B
Session::start($custB, 'b@example.com', 'customer', '203.0.113.40', 'ua', true);
T::ok(Rbac::ownsTicket((string) $tb), 'customer B owns their own ticket');
T::ok(!Rbac::ownsTicket((string) $ta), 'customer B does NOT own customer A\'s ticket');

$server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'REMOTE_ADDR' => '203.0.113.40', 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};
[$st] = $call('getMyTicket', ['ticket_id' => $ta]);
T::eq(403, $st, 'customer B reading A\'s ticket via gateway → 403 (owner gate)');
[$st] = $call('getMyTicket', ['ticket_id' => $tb]);
T::eq(200, $st, 'customer B reading their own ticket → 200');
$denied = (int) Db::scalar("SELECT COUNT(*) FROM audit_log WHERE action='authz_denied'");
T::ok($denied >= 1, 'the IDOR attempt is audit-logged');

// attachment IDOR: A's attachment cannot be downloaded by B
$att = Upload::process('photo.jpg', $validJpg);
$cleanup[] = Upload::storageDir() . '/' . $att['stored_name'];
$attId = Attachment::create(['ticket_id' => $ta, 'original_name' => 'photo.jpg', 'stored_name' => $att['stored_name'], 'mime_type' => 'image/jpeg', 'size_bytes' => $att['size_bytes'], 'sha256' => $att['sha256'], 'is_internal' => false, 'uploaded_by' => 'a@example.com']);
$uploader = new App\Controllers\UploadController();
$dlReq = new Request(server: ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.40']);
$resp = $uploader->download($dlReq, (string) $attId); // still customer B's session
T::eq(404, $resp->status(), 'customer B downloading A\'s attachment → 404 (no existence disclosure)');

Session::destroy();
Session::start($custA, 'a@example.com', 'customer', '203.0.113.41', 'ua', true);
$resp = $uploader->download($dlReq, (string) $attId);
T::eq(200, $resp->status(), 'customer A downloading their own attachment → 200');
T::ok(($resp->headers()['Content-Type'] ?? '') === 'image/jpeg', 'image served with its type');
T::ok(str_contains($resp->headers()['Content-Disposition'] ?? '', 'attachment;'), 'served as attachment (Content-Disposition)');
T::eq('nosniff', $resp->headers()['X-Content-Type-Options'] ?? '', 'X-Content-Type-Options: nosniff on download');

// internal attachment hidden from the owning customer
$attInt = Attachment::create(['ticket_id' => $ta, 'original_name' => 'note.jpg', 'stored_name' => $att['stored_name'], 'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'sha256' => 'x', 'is_internal' => true, 'uploaded_by' => 'agent1@p3a-support.com.ng']);
$resp = $uploader->download($dlReq, (string) $attInt);
T::eq(404, $resp->status(), 'internal attachment hidden from the owning customer → 404');

// ── Category CRUD + referential integrity (§3) ───────────────────────────────
T::suite('Phase 6: category integrity (§3)');
Session::destroy();
$admin = User::findByEmail('admin@p3a-support.com.ng');
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.42', 'ua', true);
$catCall = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};

// two-level enforcement: child under a child is rejected
[$st, $body] = $catCall('createCategory', ['name' => 'Parent', 'color' => '#123456']);
$parentId = $body['data']['category_id'] ?? '';
[$st, $body] = $catCall('createCategory', ['name' => 'Child', 'color' => '#123456', 'parent_id' => $parentId]);
$childId = $body['data']['category_id'] ?? '';
T::ok($childId !== '', 'child category created under a top-level parent');
[$st, $body] = $catCall('createCategory', ['name' => 'Grandchild', 'color' => '#123456', 'parent_id' => $childId]);
T::eq(422, $st, 'nesting beyond two levels rejected (422)');

// delete blocked by child reference
[$st, $body] = $catCall('deleteCategory', ['category_id' => $parentId]);
T::eq(422, $st, 'delete blocked while a sub-category exists');
T::ok(str_contains($body['error'] ?? '', 'sub-categories'), 'error explains the child block');

// delete blocked by ticket reference
[$st, $body] = $catCall('createCategory', ['name' => 'Used', 'color' => '#123456']);
$usedId = $body['data']['category_id'];
Db::update('tickets', ['category_id' => $usedId], 'ticket_id = :t', [':t' => $ta]);
[$st, $body] = $catCall('deleteCategory', ['category_id' => $usedId]);
T::eq(422, $st, 'delete blocked while tickets reference the category');

// delete blocked by routing-rule reference
[$st, $body] = $catCall('createCategory', ['name' => 'Ruled', 'color' => '#123456']);
$ruledId = $body['data']['category_id'];
Db::insert('routing_rules', ['rule_id' => 'RULE-1', 'name' => 'r', 'enabled' => 1, 'conditions' => json_encode([['field' => 'category', 'operator' => 'is', 'value' => $ruledId]]), 'actions' => json_encode([]), 'sort_order' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
[$st, $body] = $catCall('deleteCategory', ['category_id' => $ruledId]);
T::eq(422, $st, 'delete blocked while a routing rule references the category');

// delete a category with NO references → success
[$st, $body] = $catCall('createCategory', ['name' => 'Orphan', 'color' => '#123456']);
$orphanId = $body['data']['category_id'];
[$st, $body] = $catCall('deleteCategory', ['category_id' => $orphanId]);
T::eq(200, $st, 'unreferenced category deletes successfully');
T::ok(Category::find($orphanId) === null, 'category is gone after delete');

// reparent (sub-category) support via updateCategory, with the two-level guard
[$st, $body] = $catCall('createCategory', ['name' => 'TopA', 'color' => '#222222']);
$topA = $body['data']['category_id'];
[$st, $body] = $catCall('createCategory', ['name' => 'Movable', 'color' => '#333333']);
$movable = $body['data']['category_id'];
[$st] = $catCall('updateCategory', ['category_id' => $movable, 'parent_id' => $topA]);
T::eq(200, $st, 'a top-level category can be reparented as a sub-category');
T::eq($topA, (string) Category::find($movable)['parent_id'], 'parent_id persisted');
[$st] = $catCall('updateCategory', ['category_id' => $movable, 'parent_id' => $movable]);
T::eq(422, $st, 'a category cannot be its own parent');
[$st, $body] = $catCall('createCategory', ['name' => 'Deep', 'color' => '#444444', 'parent_id' => $topA]);
$deep = $body['data']['category_id'];
[$st] = $catCall('updateCategory', ['category_id' => $topA, 'parent_id' => $movable]);
T::eq(422, $st, 'moving a category that has sub-categories under a parent is rejected (max two levels)');
[$st] = $catCall('updateCategory', ['category_id' => $movable, 'parent_id' => '']);
T::eq(200, $st, 'a sub-category can be promoted back to top level');
T::ok(Category::find($movable)['parent_id'] === null, 'parent cleared on promotion');

// ── organizations: admin CRUD (§3) ───────────────────────────────────────────
T::suite('Phase 6: organizations admin');
[$st, $body] = $catCall('createOrganization', ['name' => 'Acme Institute']);
T::eq(200, $st, 'createOrganization succeeds');
$orgId = $body['data']['organization_id'] ?? '';
T::ok($orgId !== '', 'organization id returned');
[$st, $body] = $catCall('createOrganization', ['name' => 'Acme Institute']);
T::eq(422, $st, 'duplicate organization name rejected');
[$st, $body] = $catCall('updateOrganization', ['organization_id' => $orgId, 'name' => 'Acme Institute Ltd']);
T::eq(200, $st, 'rename organization succeeds');
T::eq('Acme Institute Ltd', (string) Organization::find($orgId)['name'], 'organization name persisted');
[$st, $body] = $catCall('updateOrganization', ['organization_id' => $orgId, 'active' => 0]);
T::eq(0, (int) Organization::find($orgId)['active'], 'organization deactivated');

// ── multi-tenant isolation (D2): an agent sees only their own org's tickets ──
T::suite('Phase 6: tenant isolation (D2)');
$orgA = Organization::create(['name' => 'Org Alpha', 'active' => 1]);
$orgB = Organization::create(['name' => 'Org Beta', 'active' => 1]);
$agentA = User::findByEmail('agent1@p3a-support.com.ng');
User::update((int) $agentA['id'], ['organization_id' => $orgA]);
$actorA = ['name' => 'A', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];
$tA = (string) TicketService::create(['subject' => 'A ticket', 'description' => 'x', 'customer_email' => 'a@ex.com', 'organization_id' => $orgA], 'web_form')['ticket']['ticket_id'];
$tB = (string) TicketService::create(['subject' => 'B ticket', 'description' => 'y', 'customer_email' => 'b@ex.com', 'organization_id' => $orgB], 'web_form')['ticket']['ticket_id'];

Session::destroy();
Session::start((int) $agentA['id'], 'agent1@p3a-support.com.ng', 'agent', '203.0.113.42', 'ua', true);
[$st, $body] = $catCall('getTicketsForView', ['view' => 'all']);
$ids = array_map(static fn($r) => $r['ticket_id'], $body['data']['tickets'] ?? []);
T::ok(in_array($tA, $ids, true), 'agent sees a ticket in their own organization');
T::ok(!in_array($tB, $ids, true), 'agent does NOT see another organization\'s ticket (isolation)');
[$st, $body] = $catCall('getTicket', ['ticket_id' => $tB]);
T::eq(422, $st, 'agent cannot open a ticket outside their organization (indistinguishable from missing)');
[$st, $body] = $catCall('changeStatus', ['ticket_id' => $tB, 'status' => 'resolved']);
T::eq(422, $st, 'agent cannot mutate a ticket outside their organization');
[$st, $body] = $catCall('getTicket', ['ticket_id' => $tA]);
T::eq(200, $st, 'agent CAN open a ticket in their own organization');

// the ownership gate (uploads/downloads/'owner' actions) is org-scoped for staff too
T::ok(Rbac::ownsTicket($tA), 'staff ownership gate: agent covers a ticket in their own org');
T::ok(!Rbac::ownsTicket($tB), 'staff ownership gate: agent DENIED on another org\'s ticket (upload/download path)');

// an admin sees across organizations
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.42', 'ua', true);
[$st, $body] = $catCall('getTicketsForView', ['view' => 'all']);
$adminIds = array_map(static fn($r) => $r['ticket_id'], $body['data']['tickets'] ?? []);
T::ok(in_array($tA, $adminIds, true) && in_array($tB, $adminIds, true), 'admin sees tickets across all organizations');

// org admin is admin-only: an agent is denied
Session::destroy();
Session::start((int) $agentA['id'], 'agent1@p3a-support.com.ng', 'agent', '203.0.113.42', 'ua', true);
[$st] = $catCall('listOrganizations', []);
T::eq(403, $st, 'agent blocked from organization admin (admin-only)');

// ── cleanup ──────────────────────────────────────────────────────────────────
foreach (array_unique($cleanup) as $f) {
    if (is_file($f)) { unlink($f); }
}
array_map('unlink', glob("$fix/*") ?: []);
rmdir($fix);

exit(T::summary());
