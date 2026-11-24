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
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\TaskService;
use App\Services\ValidationFailed;
use DomainException;

/** Customer home (services + running tasks) and account (credit, dues, referral). */
final class HomeController extends Controller
{
    public function index(Request $request): void
    {
        $this->render('customer/home', [
            'title'      => t('Home'),
            'categories' => CatalogService::categories(),
            'tasks'      => TaskService::listForCustomer((int) AuthService::id(), 'active', 5),
            'verified'   => AuthService::phoneVerified((int) AuthService::id()),
        ]);
    }

    public function account(Request $request): void
    {
        $id = (int) AuthService::id();
        $this->render('customer/account', [
            'title'      => t('Account'),
            'addresses'  => AddressService::forUser($id),
            'balance'    => LedgerService::customerBalance($id),
            'credit'     => LedgerService::customerCredit($id),
            'rows'       => LedgerService::customerRows($id, 30),
            'pendingDue' => (bool) Database::value("SELECT 1 FROM payments WHERE payer_id = ? AND purpose = 'due' AND status = 'pending'", [$id]),
            'accounts'   => PaymentService::accounts(),
        ]);
    }

    public function payDue(Request $request): void
    {
        try {
            PaymentService::submit((int) AuthService::id(), null, 'due', $request->all());
            Session::flash('success', t('Thank you. We will confirm your payment shortly.'));
        } catch (ValidationFailed $e) {
            $this->backWithErrors('/app/account', $e->errors, $request->all(), t($e->getMessage()));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/app/account');
    }
}
