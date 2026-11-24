<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\AddressService;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\PricingService;
use App\Services\TaskService;

/** A registered customer with one saved address. Returns [customerId, addressId]. */
function task_customer(string $phone = '01712345678'): array
{
    fresh_session();
    $id = AuthService::register([
        'role' => 'customer', 'name' => 'Nusrat', 'phone' => $phone,
        'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
    ]);
    Database::run('UPDATE users SET phone_verified_at = NOW() WHERE id = ?', [$id]);
    [$clean, $errors] = AddressService::validate([
        'label' => 'Parents', 'zone_id' => '1', 'address_line' => 'House 5, Road 3, Block B',
        'landmark' => 'Near the mosque', 'contact_name' => 'Abbu', 'contact_phone' => '01811111111',
    ]);
    assert_same([], $errors);
    return [$id, AddressService::create($id, $clean)];
}

function grocery_input(int $addressId, array $override = []): array
{
    return $override + [
        'title' => 'Weekly groceries', 'description' => 'Fresh bread please', 'pickup_location' => 'Shwapno, Mohammadpur',
        'address_id' => (string) $addressId, 'when' => 'asap', 'payment_method' => 'cash', 'item_budget' => '1,200',
        'item_name' => ['Rice', 'Lentils', ''], 'item_qty' => ['5 kg', '1 kg', ''], 'item_note' => ['Miniket', '', ''],
    ];
}

function post_task(int $customerId, string $code, array $input, array $files = []): string
{
    $category = CatalogService::category($code);
    [$clean, $errors] = TaskService::validate($customerId, $category, $input);
    if ($errors !== []) {
        throw new DomainException('validation: ' . json_encode($errors));
    }
    return TaskService::create($customerId, $category, $clean, $files);
}

test('CUS-02/03 a grocery task stores the quoted fees, items, address copy and an open event', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $number = post_task($customer, 'grocery', grocery_input($address));

    assert_true((bool) preg_match('/^HG-\d{6}-\d{4}$/', $number), "task number format: $number");
    $task = TaskService::findForCustomer($customer, $number);
    $quote = PricingService::current(CatalogService::category('grocery'), 2);
    assert_same('open', $task['status']);
    assert_same('100.00', (string) $task['worker_fee']);
    assert_same(sprintf('%.2f', $quote['platform_fee'] / 100), (string) $task['platform_fee']);
    assert_same('15.00', (string) $task['commission']);
    assert_same('1200.00', (string) $task['item_budget']);
    assert_same('House 5, Road 3, Block B', $task['dropoff_address']);
    assert_same('Abbu', $task['recipient_name']);
    assert_same(2, count($task['items']), 'empty item rows are skipped');
    assert_same('Miniket', $task['items'][0]['note']);
    assert_same(['open'], array_column($task['events'], 'to_status'));
    assert_true((bool) preg_match('/^\d{4}$/', $task['delivery_code']), 'delivery code is 4 digits');
    assert_true(strtotime($task['expires_at']) > time() + 3500, 'expires about 60 minutes from now');
});

test('validation: missing title, address of another customer, budget above tier 3, bad schedule', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    [, $otherAddress] = task_customer('01922222222');
    $category = CatalogService::category('grocery');

    [, $errors] = TaskService::validate($customer, $category, grocery_input($otherAddress, ['title' => '', 'item_budget' => '6000', 'when' => 'later', 'preferred_at' => '2020-01-01T10:00']));
    foreach (['title', 'address_id', 'item_budget', 'preferred_at'] as $field) {
        assert_true(isset($errors[$field]), "expected an error on $field");
    }
});

test('purchase tasks need items or a list photo; prescription box needs a prescription photo', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    assert_throws(DomainException::class, fn () => post_task($customer, 'grocery', grocery_input($address, ['item_name' => ['', ''], 'item_qty' => [], 'item_note' => []])), 'list what to buy');
    assert_throws(DomainException::class, fn () => post_task($customer, 'medicine', grocery_input($address, ['has_prescription' => '1'])), 'prescription');
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM tasks'));
});

test('custom task uses the customer offer as the helper fee and refuses one below the minimum', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $number = post_task($customer, 'custom', grocery_input($address, ['offer_fee' => '120']));
    assert_same('120.00', (string) TaskService::findForCustomer($customer, $number)['worker_fee']);
    assert_throws(DomainException::class, fn () => post_task($customer, 'custom', grocery_input($address, ['offer_fee' => '50'])), 'offer_fee');
});

test('BR8 new customers can run at most 2 tasks; dues block new tasks', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    post_task($customer, 'grocery', grocery_input($address));
    post_task($customer, 'grocery', grocery_input($address));
    assert_throws(DomainException::class, fn () => post_task($customer, 'grocery', grocery_input($address)), 'up to 2');

    [$second, $address2] = task_customer('01933333333');
    // Welcome credit is +50, so a due of 80 leaves 30 to pay.
    Database::insert('customer_ledger', ['customer_id' => $second, 'type' => 'cancellation_due', 'amount' => '-80.00']);
    assert_throws(DomainException::class, fn () => post_task($second, 'grocery', grocery_input($address2)), '৳30.00');
});

test('customers see only their own tasks; cancel works only while open and is recorded', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    [$other] = task_customer('01944444444');
    $number = post_task($customer, 'grocery', grocery_input($address));

    assert_same(null, TaskService::findForCustomer($other, $number));

    assert_throws(DomainException::class, fn () => App\Services\TaskFlowService::cancelByCustomer($other, $number, ''), 'not found');
    $fee = App\Services\TaskFlowService::cancelByCustomer($customer, $number, 'Bought it myself');
    assert_same(0, $fee, 'cancelling an open task is free');
    $task = TaskService::findForCustomer($customer, $number);
    assert_same('cancelled', $task['status']);
    assert_same('Bought it myself', $task['cancel_reason']);
    assert_same(['open', 'cancelled'], array_column($task['events'], 'to_status'));
    assert_same(5000, App\Services\LedgerService::customerCredit($customer), 'welcome credit returned on cancel');
    assert_throws(DomainException::class, fn () => App\Services\TaskFlowService::cancelByCustomer($customer, $number, ''));
    assert_same(1, count(TaskService::listForCustomer($customer, 'past')));
    assert_same(0, count(TaskService::listForCustomer($customer, 'active')));
});

test('SYS-01 open tasks past expiry are expired once, even when the job runs twice', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    $number = post_task($customer, 'grocery', grocery_input($address));
    Database::run("UPDATE tasks SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE task_number = ?", [$number]);
    assert_same(1, TaskService::expireDue());
    assert_same(0, TaskService::expireDue());
    assert_same('expired', TaskService::findForCustomer($customer, $number)['status']);
});

test('CUS-01 addresses: zone must be active, contact needs a phone, max 10, owner only', function () {
    reset_test_db();
    [$customer, $address] = task_customer();
    Database::run('UPDATE zones SET is_active = 0 WHERE id = 2');
    [, $errors] = AddressService::validate(['label' => 'Office', 'zone_id' => '2', 'address_line' => 'Road 27, House 9', 'contact_name' => 'Boss']);
    assert_true(isset($errors['zone_id']) && isset($errors['contact_phone']), 'zone and contact phone errors');

    [$other] = task_customer('01955555555');
    assert_same(null, AddressService::find($other, $address));
    AddressService::delete($other, $address);
    assert_true(AddressService::find($customer, $address) !== null, 'another customer cannot delete it');

    [$clean] = AddressService::validate(['label' => 'X', 'zone_id' => '1', 'address_line' => 'House 1, Road 1']);
    for ($i = 1; $i < AddressService::MAX_PER_USER; $i++) {
        AddressService::create($customer, $clean);
    }
    assert_throws(DomainException::class, fn () => AddressService::create($customer, $clean), 'up to 10');
});
