<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\AddressService;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\ReviewService;
use App\Services\TaskFlowService;
use App\Services\TaskService;
use App\Services\TaskStatus;
use App\Services\ValidationFailed;
use DomainException;

/** Customer tasks: post, follow, pay, approve, confirm, report, rate (CUS-02 … CUS-13). */
final class TaskController extends Controller
{
    public function index(Request $request): void
    {
        $filter = $request->query('show') === 'past' ? 'past' : 'active';
        $this->render('customer/tasks/index', [
            'title'  => t('My tasks'),
            'filter' => $filter,
            'tasks'  => TaskService::listForCustomer((int) AuthService::id(), $filter),
        ]);
    }

    public function choose(Request $request): void
    {
        $this->render('customer/tasks/choose', ['title' => t('New task'), 'categories' => CatalogService::categories()]);
    }

    public function create(Request $request, string $code): void
    {
        $category = CatalogService::category($code) ?? $this->notFound();
        if (!AuthService::phoneVerified((int) AuthService::id())) {
            Session::flash('info', t('Please verify your mobile number first.'));
            $this->redirect('/verify-phone');
        }
        $addresses = AddressService::forUser((int) AuthService::id());
        if ($addresses === []) {
            Session::flash('info', t('First, add the address where your task should be delivered.'));
            $this->redirect('/app/addresses/new?back=' . urlencode('/app/tasks/new/' . $category['code']));
        }
        $this->render('customer/tasks/form', [
            'title'     => $category['name'],
            'category'  => $category,
            'addresses' => $addresses,
            'credit'    => \App\Services\LedgerService::customerCredit((int) AuthService::id()),
        ]);
    }

    public function store(Request $request, string $code): void
    {
        $category = CatalogService::category($code) ?? $this->notFound();
        $customerId = (int) AuthService::id();
        $back = '/app/tasks/new/' . $category['code'];

        [$clean, $errors] = TaskService::validate($customerId, $category, $request->all());
        if ($errors !== []) {
            $this->backWithErrors($back, $errors, $request->all(), t('Please check the highlighted fields. Photos need to be added again.'));
        }
        try {
            $number = TaskService::create($customerId, $category, $clean, [
                'list'         => $request->files('list_photos'),
                'prescription' => $request->files('prescription_photos'),
            ]);
        } catch (DomainException $e) {
            $this->backWithErrors($back, [], $request->all(), t($e->getMessage()));
        }
        Session::flash('success', t('Task posted. We are finding a helper near you.'));
        $this->redirect('/app/tasks/' . $number);
    }

    public function show(Request $request, string $number): void
    {
        $customerId = (int) AuthService::id();
        $task = TaskService::findForCustomer($customerId, $number) ?? $this->notFound();
        $worker = $task['worker_id'] !== null ? Database::one(
            'SELECT u.id, u.name, u.phone, p.rating_avg, p.rating_count, p.completed_count, p.selfie_image
             FROM users u JOIN worker_profiles p ON p.user_id = u.id WHERE u.id = ?',
            [$task['worker_id']]
        ) : null;
        $this->render('customer/tasks/show', [
            'title'     => $task['title'],
            'task'      => $task,
            'version'   => self::version($task),
            'worker'    => $worker,
            'offers'    => $task['status'] === TaskStatus::OPEN ? TaskFlowService::offers((int) $task['id']) : [],
            'approvals' => Database::all('SELECT * FROM task_approvals WHERE task_id = ? ORDER BY id DESC', [$task['id']]),
            'payments'  => PaymentService::forTask((int) $task['id']),
            'feesDue'   => PaymentService::feesOutstanding($task),
            'review'    => ReviewService::mine((int) $task['id'], $customerId),
            'needsPrepay' => $task['status'] === TaskStatus::OPEN && (int) $task['item_prepaid'] === 0
                && Money::fromDecimal((string) $task['item_budget']) > Money::fromDecimal((string) setting('float_tier1')),
        ]);
    }

    /** Polled every 10 s by the task page. */
    public function status(Request $request, string $number): void
    {
        $task = TaskService::findForCustomer((int) AuthService::id(), $number) ?? $this->notFound();
        header('Cache-Control: no-store');
        $this->json(['status' => $task['status'], 'label' => t(TaskStatus::label($task['status'])), 'version' => self::version($task)]);
    }

    /** Changes whenever something the task page shows changes; the page reloads when it differs. */
    public static function version(array $task): string
    {
        $id = (int) $task['id'];
        return implode('.', [
            count($task['events']),
            (int) Database::value('SELECT COUNT(*) FROM task_offers WHERE task_id = ?', [$id]),
            (int) Database::value("SELECT COUNT(*) FROM task_approvals WHERE task_id = ? AND status <> 'pending'", [$id])
                + (int) Database::value('SELECT COUNT(*) FROM task_approvals WHERE task_id = ?', [$id]),
            (int) Database::value("SELECT COUNT(*) FROM payments WHERE task_id = ? AND status <> 'pending'", [$id]),
            (string) $task['item_cost'],
        ]);
    }

    public function cancel(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            $fee = TaskFlowService::cancelByCustomer((int) AuthService::id(), $number, $request->str('reason'));
            return $fee > 0 ? t('Task cancelled. A cancellation fee of :fee applies.', ['fee' => Money::format($fee, false)]) : t('Task cancelled.');
        });
    }

    public function acceptOffer(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::acceptOffer((int) AuthService::id(), $number, $request->int('offer_id'));
            return t('Offer accepted. Your helper has been told.');
        });
    }

    public function approval(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            $approve = $request->str('decision') === 'approve';
            TaskFlowService::decideApproval((int) AuthService::id(), $number, $request->int('approval_id'), $approve);
            return $approve ? t('Approved.') : t('Declined. Your helper has been told.');
        });
    }

    public function confirm(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number): string {
            TaskFlowService::confirmByCustomer((int) AuthService::id(), $number);
            return t('Thank you! The task is complete.');
        });
    }

    public function report(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::raiseIssue((int) AuthService::id(), $number, $request->str('type'), $request->str('description'));
            return t('Thank you. Helpigo support will review this and reply within 24 hours.');
        });
    }

    public function rate(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            ReviewService::rate((array) AuthService::user(), $number, $request->int('rating'), $request->str('comment'));
            return t('Thanks for your rating.');
        });
    }

    public function pay(Request $request, string $number): void
    {
        $purpose = $request->str('purpose') === 'item_advance' ? 'item_advance' : 'fees';
        try {
            PaymentService::submit((int) AuthService::id(), $number, $purpose, $request->all());
            Session::flash('success', t('Thank you. We will confirm your payment shortly.'));
        } catch (ValidationFailed $e) {
            $this->backWithErrors('/app/tasks/' . rawurlencode($number) . '#pay', $e->errors, $request->all(), t($e->getMessage()));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/app/tasks/' . rawurlencode($number));
    }

    /** Runs an action, flashes its message or error, returns to the task page. */
    private function attempt(string $number, callable $action): never
    {
        try {
            Session::flash('success', $action());
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/app/tasks/' . rawurlencode($number));
    }
}
