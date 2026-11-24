<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AddressService;
use App\Services\AuthService;
use App\Services\CatalogService;
use DomainException;

/** Saved drop-off addresses (CUS-01). */
final class AddressController extends Controller
{
    public function index(Request $request): void
    {
        $this->render('customer/addresses/index', [
            'title'     => t('My addresses'),
            'addresses' => AddressService::forUser((int) AuthService::id()),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('customer/addresses/form', [
            'title'   => t('Add address'),
            'address' => null,
            'zones'   => CatalogService::zones(),
            'back'    => $this->back($request->query('back')),
        ]);
    }

    public function store(Request $request): void
    {
        $back = $this->back($request->str('back'));
        [$clean, $errors] = AddressService::validate($request->all());
        if ($errors !== []) {
            $this->backWithErrors('/app/addresses/new?back=' . urlencode($back), $errors, $request->all());
        }
        try {
            AddressService::create((int) AuthService::id(), $clean);
        } catch (DomainException $e) {
            $this->backWithErrors('/app/addresses/new?back=' . urlencode($back), [], $request->all(), t($e->getMessage()));
        }
        Session::flash('success', t('Address saved.'));
        $this->redirect($back);
    }

    public function edit(Request $request, string $id): void
    {
        $address = AddressService::find((int) AuthService::id(), (int) $id);
        if ($address === null) {
            $this->notFound();
        }
        $this->render('customer/addresses/form', [
            'title'   => t('Edit address'),
            'address' => $address,
            'zones'   => CatalogService::zones(),
            'back'    => '/app/addresses',
        ]);
    }

    public function update(Request $request, string $id): void
    {
        [$clean, $errors] = AddressService::validate($request->all());
        if ($errors !== []) {
            $this->backWithErrors('/app/addresses/' . (int) $id . '/edit', $errors, $request->all());
        }
        try {
            AddressService::update((int) AuthService::id(), (int) $id, $clean);
        } catch (DomainException) {
            $this->notFound();
        }
        Session::flash('success', t('Address updated.'));
        $this->redirect('/app/addresses');
    }

    public function delete(Request $request, string $id): void
    {
        AddressService::delete((int) AuthService::id(), (int) $id);
        Session::flash('success', t('Address deleted.'));
        $this->redirect('/app/addresses');
    }

    /** Only return to pages inside the customer area (no open redirects). */
    private function back(string $value): string
    {
        return preg_match('#^/app(/[a-z0-9/_-]*)?$#', $value) ? $value : '/app/addresses';
    }
}
