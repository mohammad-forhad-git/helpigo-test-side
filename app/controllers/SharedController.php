<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\ComplaintService;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Services\PrivateFileService;
use App\Services\TaskStatus;
use App\Services\WorkerService;
use DomainException;

/** Features every logged-in role uses: notifications, chat, SOS, settings, helper photos. */
final class SharedController extends Controller
{
    public function notifications(Request $request): void
    {
        $userId = (int) AuthService::id();
        $items = NotificationService::latest($userId);
        NotificationService::markAllRead($userId);
        $layout = AuthService::user()['role'] === 'admin' ? 'layouts/admin' : 'layouts/app';
        $this->render('shared/notifications', ['title' => t('Notifications'), 'items' => $items], $layout);
    }

    public function unread(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->json(['unread' => NotificationService::unreadCount((int) AuthService::id())]);
    }

    public function chat(Request $request, string $number): void
    {
        try {
            $data = MessageService::list((array) AuthService::user(), $number, max(0, (int) $request->query('after', '0')));
        } catch (DomainException) {
            throw new HttpException(404);
        }
        $me = (int) AuthService::id();
        header('Cache-Control: no-store');
        $this->json([
            'can_write' => $data['can_write'],
            'messages'  => array_map(static fn (array $m): array => [
                'id' => (int) $m['id'], 'mine' => (int) $m['sender_id'] === $me, 'name' => $m['sender_name'],
                'body' => $m['body'], 'time' => date('g:i A', (int) strtotime((string) $m['created_at'])),
            ], $data['messages']),
        ]);
    }

    public function sendChat(Request $request, string $number): void
    {
        try {
            MessageService::send((array) AuthService::user(), $number, $request->str('body'));
            $ok = true;
            $message = '';
        } catch (DomainException $e) {
            $ok = false;
            $message = t($e->getMessage());
        }
        if ($request->wantsJson()) {
            $this->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
            return;
        }
        if (!$ok) {
            Session::flash('error', $message);
        }
        $this->redirect($this->taskPath($number) . '#chat');
    }

    public function sos(Request $request, string $number): void
    {
        try {
            ComplaintService::sos((array) AuthService::user(), $number, $request->str('description'));
            Session::flash('success', t('Helpigo support has been alerted and will call you. In an emergency, call 999.'));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect($this->taskPath($number));
    }

    /** Helper's selfie as their profile photo: the helper, admins, and customers whose task they are on. */
    public function helperPhoto(Request $request, string $id): void
    {
        $user = (array) AuthService::user();
        $workerId = (int) $id;
        $allowed = $user['role'] === 'admin' || (int) $user['id'] === $workerId
            || ($user['role'] === 'customer' && Database::value(
                'SELECT 1 FROM tasks WHERE customer_id = ? AND worker_id = ? AND status IN (' . Database::placeholders(count(TaskStatus::ACTIVE) + 1) . ')',
                array_merge([(int) $user['id'], $workerId], TaskStatus::ACTIVE, [TaskStatus::COMPLETED])
            ));
        $file = $allowed ? Database::value('SELECT selfie_image FROM worker_profiles WHERE user_id = ?', [$workerId]) : null;
        if (!is_string($file)) {
            throw new HttpException(404);
        }
        PrivateFileService::stream(WorkerService::docsDir(), $file);
    }

    public function settings(Request $request): void
    {
        $layout = AuthService::user()['role'] === 'admin' ? 'layouts/admin' : 'layouts/app';
        $this->render('shared/settings', ['title' => t('Settings')], $layout);
    }

    public function saveSettings(Request $request): void
    {
        try {
            $message = AuthService::updateAccount((int) AuthService::id(), $request->str('locale'),
                (string) $request->input('current_password', ''), (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
            Session::flash('success', t($message));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/settings');
    }

    /** Language switch for visitors (logged-in users save it in settings). */
    public function language(Request $request): void
    {
        $locale = $request->str('locale') === 'bn' ? 'bn' : 'en';
        Session::set('locale', $locale);
        if (AuthService::id() !== null) {
            Database::update('users', ['locale' => $locale], 'id = :w_id', ['w_id' => AuthService::id()]);
            AuthService::forgetCache();
        }
        $back = (string) parse_url($request->referer(), PHP_URL_PATH);
        $this->redirect($back !== '' && str_starts_with($back, '/') ? $back : '/');
    }

    private function taskPath(string $number): string
    {
        return (AuthService::user()['role'] === 'worker' ? '/worker/tasks/' : (AuthService::user()['role'] === 'admin' ? '/admin/tasks/' : '/app/tasks/')) . rawurlencode($number);
    }
}
