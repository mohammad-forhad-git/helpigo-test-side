<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use DomainException;

/** Login, registration, phone verification, password reset and logout for all roles. */
final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $this->render('auth/login', ['title' => t('Log in')]);
    }

    public function login(Request $request): void
    {
        $result = AuthService::attempt($request->str('phone'), (string) $request->input('password', ''), $request->ip());
        if (!$result['ok']) {
            $this->backWithErrors('/login', [], ['phone' => $request->str('phone')], t($result['message']));
        }
        $user = AuthService::user();
        $home = AuthService::homeFor((string) $user['role']);
        $intended = (string) Session::get('intended', '');
        Session::forget('intended');
        // Only return to a page that belongs to this user's own area.
        $this->redirect($intended !== '' && str_starts_with($intended, $home) ? $intended : $home);
    }

    public function showRegister(Request $request): void
    {
        $role = $request->query('as') === 'worker' ? 'worker' : 'customer';
        $this->render('auth/register', ['title' => t('Create account'), 'role' => $role, 'referral' => $request->query('ref')]);
    }

    public function register(Request $request): void
    {
        $input = [
            'role'                  => $request->str('role'),
            'name'                  => $request->str('name'),
            'phone'                 => $request->str('phone'),
            'password'              => (string) $request->input('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
            'referral'              => $request->str('referral'),
        ];
        $back = '/register?as=' . urlencode($input['role']);
        if ($request->input('agree') !== '1') {
            $this->backWithErrors($back, [], $input, t('Please accept the terms to continue.'));
        }
        try {
            $id = AuthService::register($input);
        } catch (DomainException $e) {
            $this->backWithErrors($back, [], $input, t($e->getMessage()));
        }
        try {
            AuthService::sendVerification($id, $request->ip());
            Session::flash('success', t('Account created. We sent a 6-digit code to your phone.'));
        } catch (DomainException $e) {
            Session::flash('info', t($e->getMessage()));
        }
        $this->redirect('/verify-phone');
    }

    // ---------------------------------------------------------------- phone verification (logged in)

    public function showVerify(Request $request): void
    {
        $user = AuthService::user() ?? $this->redirect('/login');
        if ($user['phone_verified_at'] !== null) {
            $this->redirect(AuthService::homeFor($user['role']));
        }
        $this->render('auth/verify', ['title' => t('Verify your number'), 'phone' => $user['phone']]);
    }

    public function verify(Request $request): void
    {
        $user = AuthService::user() ?? $this->redirect('/login');
        try {
            AuthService::verifyPhone((int) $user['id'], $request->str('code'));
        } catch (DomainException $e) {
            $this->backWithErrors('/verify-phone', [], [], t($e->getMessage()));
        }
        Session::flash('success', t('Your number is verified.'));
        $this->redirect($user['role'] === 'worker' ? '/worker/profile' : AuthService::homeFor($user['role']));
    }

    public function resendVerify(Request $request): void
    {
        $user = AuthService::user() ?? $this->redirect('/login');
        try {
            AuthService::sendVerification((int) $user['id'], $request->ip());
            Session::flash('success', t('A new code was sent.'));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/verify-phone');
    }

    // ---------------------------------------------------------------- forgot password

    public function showForgot(Request $request): void
    {
        $this->render('auth/forgot', ['title' => t('Forgot password')]);
    }

    public function forgot(Request $request): void
    {
        try {
            AuthService::requestReset($request->str('phone'), $request->ip());
        } catch (DomainException $e) {
            $this->backWithErrors('/forgot-password', [], ['phone' => $request->str('phone')], t($e->getMessage()));
        }
        Session::flash('success', t('If this number has an account, we sent it a 6-digit code.'));
        Session::flash('old', ['phone' => $request->str('phone')]);
        $this->redirect('/reset-password');
    }

    public function showReset(Request $request): void
    {
        $this->render('auth/reset', ['title' => t('Set a new password')]);
    }

    public function reset(Request $request): void
    {
        try {
            AuthService::resetPassword($request->str('phone'), $request->str('code'), (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        } catch (DomainException $e) {
            $this->backWithErrors('/reset-password', [], ['phone' => $request->str('phone')], t($e->getMessage()));
        }
        Session::flash('success', t('Password changed. Please log in.'));
        $this->redirect('/login');
    }

    public function logout(Request $request): void
    {
        AuthService::logout();
        Session::flash('success', t('You have been logged out.'));
        $this->redirect('/login');
    }
}
