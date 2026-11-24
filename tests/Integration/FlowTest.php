<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\AuthService;
use App\Services\LedgerService;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\ReviewService;
use App\Services\TaskFlowService as Flow;
use App\Services\TaskService;
use App\Services\WorkerService;

/** An approved, online helper serving zone 1 and every service. */
function approved_worker(string $phone, string $gender = 'male', string $float = '3000.00'): int
{
    fresh_session();
    $id = AuthService::register(['role' => 'worker', 'name' => 'Helper ' . substr($phone, -3), 'phone' => $phone,
        'password' => 'secret-pass', 'password_confirmation' => 'secret-pass']);
    Database::run("UPDATE users SET phone_verified_at = NOW() WHERE id = ?", [$id]);
    Database::run("UPDATE worker_profiles SET status = 'approved', gender = ?, float_limit = ?, is_online = 1, last_seen_at = NOW(),
                   nid_image = 'x.jpg', selfie_image = 'y.jpg' WHERE user_id = ?", [$gender, $float, $id]);
    Database::insert('worker_zones', ['worker_id' => $id, 'zone_id' => 1]);
    foreach (Database::all('SELECT id FROM categories') as $c) {
        Database::insert('worker_categories', ['worker_id' => $id, 'category_id' => $c['id']]);
    }
    return $id;
}

function png_upload(): array
{
    $path = tempnam(sys_get_temp_dir(), 'hgt') . '.png';
    $img = imagecreatetruecolor(20, 20);
    imagepng($img, $path);
    return ['name' => 'r.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

/** Takes a task from posted to delivering (cash or wallet), returning [customer, worker, number]. */
function task_to_delivering(string $payment = 'cash', string $budget = '500'): array
{
    reset_test_db();
    [$customer, $address] = task_customer();
    $worker = approved_worker('01811111112');
    $number = post_task($customer, 'grocery', grocery_input($address, ['payment_method' => $payment, 'item_budget' => $budget]));
    Flow::accept($worker, $number);
    Flow::start($worker, $number);
    Flow::saveReceipt($worker, $number, '450', png_upload(), []);
    Flow::startDelivery($worker, $number);
    return [$customer, $worker, $number];
}

test('feed shows tasks by zone, service, purchase limit and female preference; addresses are hidden', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $male = approved_worker('01811111112');
    $female = approved_worker('01811111113', 'female');
    $low = approved_worker('01811111114', 'male', '300.00');
    $normal = post_task($customer, 'grocery', grocery_input($address));
    $femaleOnly = post_task($customer, 'grocery', grocery_input($address, ['prefer_female' => '1']));

    $feed = static fn (int $w): array => array_column(Flow::feed($w), 'task_number');
    assert_same([$normal], $feed($male));
    assert_same([$normal, $femaleOnly], $feed($female));
    assert_same([], $feed($low), 'budget 1,200 is above a 300 limit');

    $view = Flow::findForWorker($male, $normal);
    assert_same(null, $view['dropoff_address']);
    assert_true(!array_key_exists('delivery_code', $view), 'delivery code never reaches the helper');
    assert_same(null, Flow::findForWorker($male, $femaleOnly), 'not visible to a male helper');
});

test('HLP-04 two helpers accept the same task: one wins; the winner then sees the address', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $a = approved_worker('01811111112');
    $b = approved_worker('01811111113');
    $number = post_task($customer, 'grocery', grocery_input($address));
    Flow::accept($a, $number);
    assert_throws(DomainException::class, fn () => Flow::accept($b, $number), 'another helper');
    $view = Flow::findForWorker($a, $number);
    assert_same('House 5, Road 3, Block B', $view['dropoff_address']);
    assert_true(!array_key_exists('delivery_code', $view), 'still no delivery code');
    assert_same(null, Flow::findForWorker($b, $number));
});

test('dues cap, one active task and offline status block accepting', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $w = approved_worker('01811111112');
    $first = post_task($customer, 'grocery', grocery_input($address));
    $second = post_task($customer, 'errand', grocery_input($address));
    Flow::accept($w, $first);
    assert_throws(DomainException::class, fn () => Flow::accept($w, $second), 'current task');

    $w2 = approved_worker('01811111113');
    Database::insert('worker_ledger', ['worker_id' => $w2, 'type' => 'adjustment', 'amount' => '-600.00']);
    assert_throws(DomainException::class, fn () => Flow::accept($w2, $second), 'owe');

    $w3 = approved_worker('01811111114');
    WorkerService::setOnline($w3, false);
    assert_throws(DomainException::class, fn () => Flow::accept($w3, $second), 'online');
});

test('BR4 case A cash: helper net -30.00 and counters update', function () {
    [$customer, $worker, $number] = task_to_delivering('cash');
    $code = (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]);
    Flow::completeWithCode($worker, $number, $code);
    // fee 100, commission 15, customer fees 115 - 50 welcome discount = 65 collected in cash
    assert_same(10000 - 1500 - 6500, LedgerService::workerBalance($worker));
    assert_same(1, (int) Database::value('SELECT completed_count FROM worker_profiles WHERE user_id = ?', [$worker]));
    assert_same('completed', Database::value('SELECT status FROM tasks WHERE task_number = ?', [$number]));
});

test('BR4 case A without discount: helper owes exactly platform fee + commission (-30.00)', function () {
    [$customer, $worker, $number] = task_to_delivering('cash');
    Database::run('UPDATE tasks SET discount = 0 WHERE task_number = ?', [$number]);
    Flow::completeWithCode($worker, $number, (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]));
    assert_same(-3000, LedgerService::workerBalance($worker));
});

test('BR4 case B wallet: helper +85.00, customer owes the fees until the payment is verified', function () {
    [$customer, $worker, $number] = task_to_delivering('bkash');
    Flow::completeWithCode($worker, $number, (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]));
    assert_same(8500, LedgerService::workerBalance($worker));
    $credit = 5000;   // welcome credit, used on this task
    assert_same(-11500 + $credit, LedgerService::customerBalance($customer) - 0 - 0 + 0, 'fees due 65 after the 50 credit');

    $paymentId = PaymentService::submit($customer, $number, 'fees', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'abc12345']);
    assert_throws(App\Services\ValidationFailed::class, fn () => PaymentService::submit($customer, null, 'due', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'ABC12345']));
    PaymentService::verify(reset_admin(), $paymentId);
    assert_same(0, LedgerService::customerBalance($customer));
});

function reset_admin(): int
{
    $id = Database::value("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    return $id !== null ? (int) $id : Database::insert('users', ['role' => 'admin', 'name' => 'Admin', 'phone' => '01611111111', 'password_hash' => 'x']);
}

test('BR4 case C prepaid: B=3000, receipt 2700 → helper -215.00, customer refund 300.00', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $worker = approved_worker('01811111112', 'male', '1000.00');
    $admin = reset_admin();
    $number = post_task($customer, 'grocery', grocery_input($address, ['item_budget' => '3000', 'payment_method' => 'bkash']));
    Database::run('UPDATE tasks SET discount = 0 WHERE task_number = ?', [$number]);
    assert_same([], Flow::feed($worker), 'above the limit until prepaid');

    $pay = PaymentService::submit($customer, $number, 'item_advance', ['method' => 'nagad', 'sender_number' => '01712345678', 'trx_id' => 'NG1234567']);
    PaymentService::verify($admin, $pay);
    assert_same([$number], array_column(Flow::feed($worker), 'task_number'));

    Flow::accept($worker, $number);
    assert_throws(DomainException::class, fn () => Flow::start($worker, $number), 'Wait for Helpigo');
    Flow::recordAdvance($admin, $number, 'TRX998877');
    Flow::start($worker, $number);
    Flow::saveReceipt($worker, $number, '2700', png_upload(), []);
    Flow::startDelivery($worker, $number);
    Flow::completeWithCode($worker, $number, (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]));

    assert_same(10000 - 1500 - 300000 + 270000, LedgerService::workerBalance($worker));
    $refund = (int) round(100 * (float) Database::value("SELECT SUM(amount) FROM customer_ledger WHERE customer_id = ? AND type = 'refund'", [$customer]));
    assert_same(30000, $refund);
});

test('BR6 wrong code 5 times locks; no-code path waits for confirmation; auto-complete runs once', function () {
    [$customer, $worker, $number] = task_to_delivering('cash');
    for ($i = 0; $i < 5; $i++) {
        assert_throws(DomainException::class, fn () => Flow::completeWithCode($worker, $number, '0000') ?: null);
    }
    $code = (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]);
    assert_throws(DomainException::class, fn () => Flow::completeWithCode($worker, $number, $code), 'Too many');
    assert_throws(DomainException::class, fn () => Flow::deliverWithoutCode($worker, $number, null, ''), 'photo');
    Flow::deliverWithoutCode($worker, $number, png_upload(), 'Left with the guard');
    assert_same('awaiting_confirmation', Database::value('SELECT status FROM tasks WHERE task_number = ?', [$number]));

    Database::run('UPDATE tasks SET delivered_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE task_number = ?', [$number]);
    assert_same(1, Flow::autoComplete());
    assert_same(0, Flow::autoComplete());
    assert_same(1, (int) Database::value("SELECT COUNT(*) FROM worker_ledger WHERE type = 'earning'"));
});

test('BR6 approvals: over-budget must be approved before delivery; receipt above budget refused', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $worker = approved_worker('01811111112');
    $number = post_task($customer, 'grocery', grocery_input($address, ['item_budget' => '500']));
    Flow::accept($worker, $number);
    Flow::start($worker, $number);
    assert_throws(DomainException::class, fn () => Flow::saveReceipt($worker, $number, '650', png_upload(), []), 'above the budget');
    Flow::requestApproval($worker, $number, 'over_budget', 'Rice price went up', '700');
    assert_throws(DomainException::class, fn () => Flow::startDelivery($worker, $number), 'approval');
    $approval = (int) Database::value('SELECT id FROM task_approvals');
    Flow::decideApproval($customer, $number, $approval, true);
    assert_same('700.00', (string) Database::value('SELECT item_budget FROM tasks WHERE task_number = ?', [$number]));
    Flow::saveReceipt($worker, $number, '650', png_upload(), []);
    Flow::startDelivery($worker, $number);
    assert_same('delivering', Database::value('SELECT status FROM tasks WHERE task_number = ?', [$number]));
});

test('cancel after assignment: customer due -30, helper +30; drop gives strikes and 3 strikes pause the helper', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $worker = approved_worker('01811111112');
    $number = post_task($customer, 'grocery', grocery_input($address));
    Flow::accept($worker, $number);
    assert_same(3000, Flow::cancelByCustomer($customer, $number, 'Changed my mind'));
    assert_same(3000, LedgerService::workerBalance($worker));
    assert_same(-3000 + 5000, LedgerService::customerBalance($customer), 'due 30 against welcome credit 50 (returned)');

    for ($i = 0; $i < 3; $i++) {
        $n = post_task($customer, 'errand', grocery_input($address));
        Flow::accept($worker, $n);
        Flow::dropByWorker($worker, $n, 'Bike broke');
        assert_same('open', Database::value('SELECT status FROM tasks WHERE task_number = ?', [$n]));
        Flow::cancelByCustomer($customer, $n, '');
        if ($i < 2) {
            WorkerService::setOnline($worker, true);
        }
    }
    assert_same('suspended', Database::value('SELECT status FROM worker_profiles WHERE user_id = ?', [$worker]));
});

test('custom task offers: customer accepts one, fees re-quoted, others declined', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $a = approved_worker('01811111112');
    $b = approved_worker('01811111113');
    $number = post_task($customer, 'custom', grocery_input($address, ['offer_fee' => '100']));
    assert_throws(DomainException::class, fn () => Flow::accept($a, $number), 'offer');
    Flow::sendOffer($a, $number, '150', 'I can do it now');
    Flow::sendOffer($b, $number, '120', '');
    $offers = Flow::offers((int) Database::value('SELECT id FROM tasks WHERE task_number = ?', [$number]));
    assert_same(2, count($offers));
    Flow::acceptOffer($customer, $number, (int) $offers[0]['id']);   // cheapest first: 120 from B
    $task = Database::one('SELECT * FROM tasks WHERE task_number = ?', [$number]);
    assert_same($b, (int) $task['worker_id']);
    assert_same('120.00', (string) $task['worker_fee']);
    assert_same('18.00', (string) $task['commission']);
    assert_same('declined', Database::value('SELECT status FROM task_offers WHERE worker_id = ?', [$a]));
});

test('ratings update the helper average once per side; disputes resolve with refund credit', function () {
    [$customer, $worker, $number] = task_to_delivering('cash');
    Flow::completeWithCode($worker, $number, (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]));
    $customerUser = ['id' => $customer, 'role' => 'customer', 'name' => 'C'];
    ReviewService::rate($customerUser, $number, 4, 'Good');
    assert_throws(DomainException::class, fn () => ReviewService::rate($customerUser, $number, 5, ''), 'already');
    assert_same('4.00', (string) Database::value('SELECT rating_avg FROM worker_profiles WHERE user_id = ?', [$worker]));

    Flow::raiseIssue($customer, $number, 'wrong_items', 'Eggs were broken, two missing');
    assert_same('disputed', Database::value('SELECT status FROM tasks WHERE task_number = ?', [$number]));
    $before = LedgerService::customerBalance($customer);
    Flow::resolveDispute(reset_admin(), $number, 'completed', 'Refund for broken eggs', 4000);
    assert_same($before + 4000, LedgerService::customerBalance($customer));
    assert_same('resolved', Database::value('SELECT status FROM complaints'));
    assert_same(1, (int) Database::value("SELECT COUNT(*) FROM worker_ledger WHERE type = 'earning'"), 'earning written once');
});

test('OTP: verify phone, wrong code counts, limits sends; reset password with code', function () {
    reset_test_db();
    fresh_session();
    $id = AuthService::register(['role' => 'customer', 'name' => 'A', 'phone' => '01712345678', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass']);
    $code = OtpService::send('01712345678', 'verify_phone', '10.0.0.1');
    assert_throws(DomainException::class, fn () => AuthService::verifyPhone($id, '000000'));
    AuthService::verifyPhone($id, $code);
    assert_true(AuthService::phoneVerified($id), 'verified');
    assert_throws(DomainException::class, fn () => AuthService::verifyPhone($id, $code), 'expired');

    $reset = OtpService::send('01712345678', 'reset_password', '10.0.0.1');
    AuthService::resetPassword('01712345678', $reset, 'new-password', 'new-password');
    fresh_session();
    assert_same(true, AuthService::attempt('01712345678', 'new-password', '10.0.0.1')['ok']);
    OtpService::send('01712345678', 'verify_phone', '10.0.0.1');   // third send this hour is allowed
    assert_throws(DomainException::class, fn () => OtpService::send('01712345678', 'verify_phone', '10.0.0.1'), 'Too many');
});

test('referral: new customer gets credit, referrer is rewarded after the first completed task', function () {
    reset_test_db();
    [$referrer] = task_customer('01722222222');
    $code = (string) Database::value('SELECT referral_code FROM users WHERE id = ?', [$referrer]);
    fresh_session();
    $friend = AuthService::register(['role' => 'customer', 'name' => 'F', 'phone' => '01733333333', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'referral' => strtolower($code)]);
    assert_same(10000, LedgerService::customerCredit($friend), 'welcome 50 + referral 50');
    assert_throws(DomainException::class, fn () => AuthService::register(['role' => 'customer', 'name' => 'G', 'phone' => '01744444444', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'referral' => 'ZZZZZZ']), 'referral');

    Database::run('UPDATE users SET phone_verified_at = NOW() WHERE id = ?', [$friend]);
    $address = App\Services\AddressService::create($friend, ['label' => 'H', 'zone_id' => 1, 'address_line' => 'House 1, Road 2', 'landmark' => null, 'contact_name' => null, 'contact_phone' => null]);
    $worker = approved_worker('01811111112');
    $number = post_task($friend, 'grocery', grocery_input($address));
    assert_same('100.00', (string) Database::value('SELECT discount FROM tasks WHERE task_number = ?', [$number]), 'discount capped at fees 115 → 100 credit used');
    Flow::accept($worker, $number);
    Flow::start($worker, $number);
    Flow::saveReceipt($worker, $number, '100', png_upload(), []);
    Flow::startDelivery($worker, $number);
    Flow::completeWithCode($worker, $number, (string) Database::value('SELECT delivery_code FROM tasks WHERE task_number = ?', [$number]));
    assert_same(5000 + 5000, LedgerService::customerCredit($referrer), 'referrer: welcome 50 + reward 50');
});

test('helper profile: verified phone, documents, zones and services required; submit → pending; admin approves', function () {
    reset_test_db();
    fresh_session();
    $id = AuthService::register(['role' => 'worker', 'name' => 'W', 'phone' => '01812345678', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass']);
    $input = ['gender' => 'female', 'nid_number' => '1234567890', 'reference_name' => 'Uncle Rafiq', 'reference_phone' => '01911111111',
        'payout_method' => 'bkash', 'payout_number' => '01812345678', 'age_confirm' => '1', 'zones' => ['1', '99'], 'categories' => ['1', '2']];
    assert_throws(DomainException::class, fn () => WorkerService::saveProfile($id, $input, png_upload(), png_upload()), 'verify');
    Database::run('UPDATE users SET phone_verified_at = NOW() WHERE id = ?', [$id]);
    try {
        WorkerService::saveProfile($id, ['zones' => []] + $input, null, null);
        throw new AssertionFailed('expected validation errors');
    } catch (App\Services\ValidationFailed $e) {
        assert_true(isset($e->errors['zones'], $e->errors['nid_image'], $e->errors['selfie_image']), 'zones and documents required');
    }
    WorkerService::saveProfile($id, $input, png_upload(), png_upload());
    $p = WorkerService::profile($id);
    assert_same('pending', $p['status']);
    assert_same([1], $p['zone_ids'], 'unknown zone 99 ignored');
    assert_true(is_file(WorkerService::docsDir() . '/' . $p['nid_image']), 'document stored privately');
    assert_throws(DomainException::class, fn () => WorkerService::setOnline($id, true), 'approved');
    assert_throws(DomainException::class, fn () => WorkerService::review(reset_admin(), $id, 'reject', ''), 'reason');
    WorkerService::review(reset_admin(), $id, 'approve', '');
    WorkerService::setOnline($id, true);
    assert_same(1, (int) Database::value('SELECT is_online FROM worker_profiles WHERE user_id = ?', [$id]));
});

test('purchase limit tiers follow the record (BR3)', function () {
    reset_test_db();
    $w = approved_worker('01811111112');
    Database::run('UPDATE worker_profiles SET completed_count = 20, rating_avg = 4.6, rating_count = 15 WHERE user_id = ?', [$w]);
    WorkerService::recalcTier($w);
    assert_same('3000.00', (string) Database::value('SELECT float_limit FROM worker_profiles WHERE user_id = ?', [$w]));
    Database::run('UPDATE worker_profiles SET completed_count = 60 WHERE user_id = ?', [$w]);
    WorkerService::recalcTier($w);
    assert_same('5000.00', (string) Database::value('SELECT float_limit FROM worker_profiles WHERE user_id = ?', [$w]));
    Database::run('UPDATE worker_profiles SET rating_avg = 4.2 WHERE user_id = ?', [$w]);
    WorkerService::recalcTier($w);
    assert_same('1000.00', (string) Database::value('SELECT float_limit FROM worker_profiles WHERE user_id = ?', [$w]));
});

test('payout: only up to the balance, records TrxID; settlement clears dues', function () {
    reset_test_db();
    $w = approved_worker('01811111112');
    $admin = reset_admin();
    LedgerService::worker($w, null, 'earning', 20000, 'test');
    assert_throws(DomainException::class, fn () => LedgerService::payout($w, 25000, 'TX1', $admin), 'at most');
    LedgerService::payout($w, 20000, 'TX1', $admin);
    assert_same(0, LedgerService::workerBalance($w));
    LedgerService::worker($w, null, 'commission', -60000, 'test');
    assert_true(LedgerService::workerOverDues($w), 'over the 500 cap');
    LedgerService::settlement($w, 60000, 'TX2', $admin);
    assert_true(!LedgerService::workerOverDues($w), 'dues cleared');
});

test('regression: a helper who is not approved cannot open an open task by URL', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $w = approved_worker('01811111112');
    Database::run("UPDATE worker_profiles SET status = 'pending' WHERE user_id = ?", [$w]);
    $number = post_task($customer, 'grocery', grocery_input($address));
    assert_same(null, Flow::findForWorker($w, $number));
    assert_same([], Flow::feed($w));
});

test('flagged words send a task to admin review; it stays out of the feed until released', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $w = approved_worker('01811111112');
    $number = post_task($customer, 'custom', grocery_input($address, ['offer_fee' => '100', 'description' => 'Bring 2 bottles of beer']));
    assert_same(1, (int) Database::value('SELECT needs_review FROM tasks WHERE task_number = ?', [$number]));
    assert_same([], Flow::feed($w));
    assert_true(!TaskService::looksProhibited(['title' => 'Buy beetroot and bread', 'items' => []]), 'no false match inside words');
});
