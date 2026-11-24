<?php

declare(strict_types=1);

/*
 * End-to-end HTTP test against a running LOCAL server, through every role:
 * customer posts a task, helper onboards, admin approves, helper does the task, money is checked.
 * Uses the DEVELOPMENT database (creates accounts with random 015 numbers) and reads one-time
 * codes from storage/logs/sms.log, so SMS_DRIVER must be "log".
 *   php -S 127.0.0.1:8090 -t public public/index.php      (another terminal)
 *   ADMIN_PHONE=01611111111 ADMIN_PASSWORD=... php tests/http/smoke.php [http://127.0.0.1:8090]
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8090', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    fwrite(STDERR, "Refusing to run against $base (local servers only).\n");
    exit(1);
}
$smsLog = dirname(__DIR__, 2) . '/storage/logs/sms.log';

final class Client
{
    private string $jar;

    public function __construct(private readonly string $base)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'hg');
    }

    /** @return array{0:int, 1:string, 2:string} status, body, redirect path(+query) */
    public function request(string $method, string $path, array $fields = [], bool $json = false): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $json ? ['Accept: application/json'] : [],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $to = (string) parse_url($location, PHP_URL_PATH) . (parse_url($location, PHP_URL_QUERY) ? '?' . parse_url($location, PHP_URL_QUERY) : '');
        return [$status, $body, $to];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function token(string $path): string
    {
        [, $body] = $this->get($path);
        return preg_match('/name="_token" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : '';
    }

    public function post(string $path, array $fields, ?string $tokenFrom = null): array
    {
        return $this->request('POST', $path, ['_token' => $this->token($tokenFrom ?? $path)] + $fields);
    }

    /** Page text with tags removed (for content checks). */
    public function text(string $path): string
    {
        return html_entity_decode(strip_tags($this->get($path)[1]));
    }
}

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $detail === '' ? '' : "  [$detail]") . PHP_EOL;
}
function phone(): string
{
    return '015' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
}
function otp(string $phone): string
{
    global $smsLog;
    $lines = file($smsLog, FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, 'to=88' . $phone) && preg_match('/code is (\d{6})/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}
function png(string $name): CURLFile
{
    $path = sys_get_temp_dir() . '/hg-smoke-' . $name . '.png';
    $img = imagecreatetruecolor(60, 40);
    imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 120, 90));
    imagepng($img, $path);
    return new CURLFile($path, 'image/png', $name . '.png');
}
function register(Client $c, string $role, string $phone, string $name): void
{
    $c->post('/register', ['role' => $role, 'name' => $name, 'phone' => $phone, 'password' => 'smoke-pass', 'password_confirmation' => 'smoke-pass', 'agree' => '1']);
    $c->post('/verify-phone', ['code' => otp($phone)], '/verify-phone');
}

// ================================================================ public
$guest = new Client($base);
foreach (['/', '/login', '/register', '/forgot-password', '/terms', '/privacy', '/refund-policy', '/helper-agreement', '/safety', '/offline', '/sw.js', '/manifest.webmanifest'] as $path) {
    check("guest GET $path → 200", $guest->get($path)[0] === 200);
}
foreach (['/app', '/worker', '/admin', '/notifications', '/files/task/1', '/chat/HG-000000-0000'] as $path) {
    [$s, , $to] = $guest->get($path);
    check("guest GET $path → login", $s === 303 && $to === '/login', "$s $to");
}
check('POST without CSRF → 419', $guest->request('POST', '/register', ['name' => 'x'])[0] === 419);
$guest->post('/language', ['locale' => 'bn'], '/');
check('language switch → Bangla landing page', str_contains($guest->text('/'), 'কাজ পোস্ট করুন'));
$guest->post('/language', ['locale' => 'en'], '/');

// ================================================================ customer posts a task
$customer = new Client($base);
$cPhone = phone();
$customer->post('/register', ['role' => 'customer', 'name' => 'Smoke Customer', 'phone' => $cPhone, 'password' => 'smoke-pass', 'password_confirmation' => 'smoke-pass', 'agree' => '1']);
[$s, , $to] = $customer->get('/app/tasks/new/grocery');
check('unverified customer is sent to verify', $s === 303 && $to === '/verify-phone', "$s $to");
[$s, , $to] = $customer->post('/verify-phone', ['code' => '000000'], '/verify-phone');
check('wrong code refused', $to === '/verify-phone');
[$s, , $to] = $customer->post('/verify-phone', ['code' => otp($cPhone)], '/verify-phone');
check('right code → customer home', $s === 303 && $to === '/app', "$s $to");
check('customer account shows welcome credit', str_contains($customer->text('/app/account'), '৳50'));

$customer->post('/app/addresses', ['back' => '/app/tasks/new/grocery', 'label' => 'Parents', 'zone_id' => '1',
    'address_line' => 'House 12, Road 4, Block C', 'contact_name' => 'Abbu', 'contact_phone' => '01812345670'], '/app/addresses/new');
[, $form] = $customer->get('/app/tasks/new/grocery');
preg_match('/name="address_id" value="(\d+)"/', $form, $m);
[$s, , $taskPath] = $customer->post('/app/tasks/new/grocery', [
    'title' => 'Smoke groceries', 'pickup_location' => 'Agora', 'address_id' => $m[1] ?? '0', 'when' => 'asap',
    'payment_method' => 'cash', 'item_budget' => '800', 'item_name[0]' => 'Eggs', 'item_qty[0]' => '12',
    'item_name[1]' => 'Bread', 'list_photos[0]' => png('list'),
], '/app/tasks/new/grocery');
check('task posted', (bool) preg_match('#^/app/tasks/(HG-\d{6}-\d{4})$#', $taskPath, $tm), "$s $taskPath");
$number = $tm[1] ?? '';
$page = $customer->text($taskPath);
check('task page: finding a helper, fees with welcome credit (৳65.00)', str_contains($page, 'Finding a helper') && str_contains($page, '৳65.00'));

// ================================================================ helper onboards
$worker = new Client($base);
$wPhone = phone();
register($worker, 'worker', $wPhone, 'Smoke Helper');
check('helper home shows onboarding', str_contains($worker->text('/worker'), 'Finish your helper profile'));
[$s, , $to] = $worker->post('/worker/profile', [
    'gender' => 'female', 'nid_number' => '1990123456789', 'reference_name' => 'Uncle Rafiq', 'reference_phone' => '01911111119',
    'payout_method' => 'bkash', 'payout_number' => $wPhone, 'age_confirm' => '1', 'zones[0]' => '1', 'categories[0]' => '2', 'categories[1]' => '1', 'categories[2]' => '5',
    'nid_image' => png('nid'), 'selfie_image' => png('selfie'),
], '/worker/profile');
check('profile submitted for review', $s === 303 && $to === '/worker', "$s $to");
check('helper cannot see the task before approval', $worker->get('/worker/tasks/' . $number)[0] === 404);

// ================================================================ admin approves
$admin = new Client($base);
$adminPhone = getenv('ADMIN_PHONE') ?: '01611111111';
[$s, , $to] = $admin->post('/login', ['phone' => $adminPhone, 'password' => getenv('ADMIN_PASSWORD') ?: 'admin-pass-12345'], '/login');
check('admin logs in', $to === '/admin', "$s $to");
foreach (['/admin', '/admin/tasks', '/admin/tasks?view=all', '/admin/helpers', '/admin/helpers?status=pending', '/admin/payments', '/admin/money/helpers',
          '/admin/complaints', '/admin/customers', '/admin/reports', '/admin/settings', '/admin/tasks/' . $number, '/notifications', '/settings'] as $path) {
    check("admin GET $path → 200", $admin->get($path)[0] === 200);
}
[, $csv] = $admin->get('/admin/reports/daily.csv');
check('reports CSV', str_starts_with($csv, 'date,tasks_created'));
[, $list] = $admin->get('/admin/helpers?status=pending');
// Find the row of the helper this run created (by phone), not by position: other helpers may be waiting too.
preg_match('#/admin/helpers/(\d+)">(?:(?!</tr>).)*?' . $wPhone . '#s', $list, $hm);
$helperId = $hm[1] ?? '0';
[, $hp] = $admin->get('/admin/helpers/' . $helperId);
check('admin sees helper documents', str_contains($hp, '/doc/nid') && $admin->get('/admin/helpers/' . $helperId . '/doc/nid')[0] === 200);
check('customer cannot open helper documents', $customer->get('/admin/helpers/' . $helperId . '/doc/nid')[0] === 403);
$admin->post('/admin/helpers/' . $helperId . '/review', ['action' => 'approve', 'note' => ''], '/admin/helpers/' . $helperId);
check('helper approved', str_contains($admin->text('/admin/helpers/' . $helperId), 'approved'));

// ================================================================ helper does the task
$worker->post('/worker/online', ['online' => '1'], '/worker');
check('task appears in helper feed', str_contains($worker->get('/worker')[1], $number));
$view = $worker->get('/worker/tasks/' . $number)[1];
check('before accepting: no drop-off address, no delivery code', !str_contains($view, 'House 12, Road 4') && !str_contains($view, 'delivery code</p>'));
$worker->post('/worker/tasks/' . $number . '/accept', [], '/worker/tasks/' . $number);
$view = $worker->text('/worker/tasks/' . $number);
check('after accepting: address and receiver shown', str_contains($view, 'House 12, Road 4') && str_contains($view, 'Abbu'));
[$code] = [preg_match('#code-card__code">(\d{4})<#', $customer->get($taskPath)[1], $cm) ? $cm[1] : ''];
check('customer sees helper card and 4-digit code', $code !== '' && str_contains($customer->text($taskPath), 'Smoke Helper'));
check('code never in helper page', !str_contains($worker->get('/worker/tasks/' . $number)[1], 'code-card__code'));
check('customer sees helper photo', $customer->get('/files/helper/' . $helperId)[0] === 200);

// chat both ways
$s = $customer->request('POST', '/chat/' . $number, ['_token' => $customer->token($taskPath), 'body' => 'Please get brown bread'], true)[0];
check('customer sends chat', $s === 200);
$chat = json_decode($worker->request('GET', '/chat/' . $number, [], true)[1], true);
check('helper reads chat', ($chat['messages'][0]['body'] ?? '') === 'Please get brown bread');
check('outsider cannot read chat', (new Client($base))->get('/chat/' . $number)[0] === 303 && $guest->get('/chat/' . $number)[0] === 303);

$worker->post('/worker/tasks/' . $number . '/start', [], '/worker/tasks/' . $number);
[, $wpage] = $worker->get('/worker/tasks/' . $number);
preg_match_all('#name="item_status\[(\d+)\]"#', $wpage, $im);
$fields = ['item_cost' => '420', 'receipt' => png('receipt')];
foreach ($im[1] as $itemId) {
    $fields["item_status[$itemId]"] = 'bought';
}
$worker->post('/worker/tasks/' . $number . '/receipt', $fields, '/worker/tasks/' . $number);
$worker->post('/worker/tasks/' . $number . '/deliver', [], '/worker/tasks/' . $number);
check('customer sees "On the way" and receipt total', str_contains($customer->text($taskPath), 'On the way') && str_contains($customer->text($taskPath), '৳420.00'));
$worker->post('/worker/tasks/' . $number . '/complete', ['code' => $code === '0000' ? '1111' : '0000'], '/worker/tasks/' . $number);
check('wrong code refused', str_contains($worker->text('/worker/tasks/' . $number), 'not correct'));
$worker->post('/worker/tasks/' . $number . '/complete', ['code' => $code], '/worker/tasks/' . $number);
check('right code completes the task', str_contains($worker->text('/worker/tasks/' . $number), 'Completed'));
// cash task, fee 100 (2 items), commission 15, customer paid 115 - 50 credit = 65 in cash → helper +100 -15 -65 = +20
check('helper earnings: ৳20.00 balance', str_contains($worker->text('/worker/earnings'), '৳20.00'));
$customer->post($taskPath . '/rate', ['rating' => '5', 'comment' => 'Great'], $taskPath);
check('customer rated', str_contains($customer->text($taskPath), 'You rated this task'));
check('notifications listed', str_contains($customer->text('/notifications'), 'accepted your task'));

// ================================================================ privacy and roles
$other = new Client($base);
register($other, 'customer', phone(), 'Other');
check('other customer: task 404', $other->get($taskPath)[0] === 404);
check('other customer: helper photo 404', $other->get('/files/helper/' . $helperId)[0] === 404);
check('customer → /worker 403, helper → /app 403, helper → /admin 403',
    $customer->get('/worker')[0] === 403 && $worker->get('/app')[0] === 403 && $worker->get('/admin')[0] === 403);

// ================================================================ wallet payment + admin verify
[, , $tp2] = $customer->post('/app/tasks/new/errand', [
    'title' => 'Collect a parcel', 'pickup_location' => 'Courier office', 'address_id' => $m[1] ?? '0', 'when' => 'asap',
    'payment_method' => 'bkash', 'item_budget' => '200', 'item_name[0]' => 'Parcel',
], '/app/tasks/new/errand');
preg_match('#(HG-\d{6}-\d{4})#', $tp2, $n2);
$worker->post('/worker/tasks/' . $n2[1] . '/accept', [], '/worker/tasks/' . $n2[1]);
check('wallet task shows pay form after accept', str_contains($customer->get($tp2)[1], 'name="trx_id"'));
$trx = 'SMK' . random_int(100000, 999999);
$customer->post($tp2 . '/pay', ['purpose' => 'fees', 'method' => 'bkash', 'sender_number' => $cPhone, 'trx_id' => $trx], $tp2);
check('payment waiting for verification', str_contains($customer->text($tp2), 'being checked'));
[, $pay] = $admin->get('/admin/payments');
preg_match('#/admin/payments/(\d+)/verify"[^>]*>(?:(?!</form>).)*?#s', $pay, $pm);
preg_match_all('#/admin/payments/(\d+)/verify#', $pay, $pms);
$admin->post('/admin/payments/' . end($pms[1]) . '/verify', [], '/admin/payments');
check('admin verified payment', str_contains($admin->text('/admin/payments?status=verified'), $trx));
$customer->post($tp2 . '/cancel', ['reason' => 'smoke'], $tp2);
check('cancel after accept charges ৳30', str_contains($customer->text($tp2), 'Cancellation fee'));

foreach (['/app', '/app/tasks', '/app/tasks?show=past', '/app/account', '/app/addresses', '/settings', '/worker', '/worker/tasks', '/worker/earnings', '/worker/profile'] as $path) {
    $client = str_starts_with($path, '/worker') ? $worker : $customer;
    check("GET $path → 200", $client->get($path)[0] === 200);
}

printf(PHP_EOL . "%d passed, %d failed" . PHP_EOL, $passed, $failed);
exit($failed === 0 ? 0 : 1);
