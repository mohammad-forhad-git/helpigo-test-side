<?php

declare(strict_types=1);

namespace App\Controllers\Worker;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\ValidationFailed;
use App\Services\WorkerService;
use DomainException;

/** HLP-01: helper profile and verification documents. */
final class ProfileController extends Controller
{
    public function edit(Request $request): void
    {
        $id = (int) AuthService::id();
        if (!AuthService::phoneVerified($id)) {
            Session::flash('info', t('Please verify your mobile number first.'));
            $this->redirect('/verify-phone');
        }
        $this->render('worker/profile', [
            'title'      => t('Helper profile'),
            'profile'    => WorkerService::profile($id),
            'zones'      => CatalogService::zones(),
            'categories' => CatalogService::categories(),
        ]);
    }

    public function update(Request $request): void
    {
        try {
            WorkerService::saveProfile((int) AuthService::id(), $request->all(), $request->file('nid_image'), $request->file('selfie_image'));
        } catch (ValidationFailed $e) {
            $this->backWithErrors('/worker/profile', $e->errors, $request->all(), t($e->getMessage()) . ' ' . t('Photos need to be added again.'));
        } catch (DomainException $e) {
            $this->backWithErrors('/worker/profile', [], $request->all(), t($e->getMessage()));
        }
        $status = WorkerService::profile((int) AuthService::id())['status'];
        Session::flash('success', $status === 'pending'
            ? t('Profile sent for review. We usually reply within 1 working day.')
            : t('Profile saved.'));
        $this->redirect('/worker');
    }
}
