<?php

declare(strict_types=1);

namespace App\Controllers\Worker;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\LedgerService;
use App\Services\TaskFlowService;
use App\Services\WorkerService;
use DomainException;

/** Helper home: status, online switch, current task, and the feed of open tasks. */
final class HomeController extends Controller
{
    public function index(Request $request): void
    {
        $id = (int) AuthService::id();
        $profile = WorkerService::profile($id);
        $activeId = TaskFlowService::activeTaskId($id);
        $this->render('worker/home', [
            'title'    => t('Helper home'),
            'profile'  => $profile,
            'verified' => AuthService::phoneVerified($id),
            'active'   => $activeId !== null ? Database::one('SELECT task_number, title, status FROM tasks WHERE id = ?', [$activeId]) : null,
            'feed'     => $profile['status'] === 'approved' ? TaskFlowService::feed($id) : [],
            'block'    => TaskFlowService::workerBlock($id),
            'balance'  => LedgerService::workerBalance($id),
        ]);
    }

    /** Polled every 10 s on the home page: reload when the feed or the current task changes. */
    public function feed(Request $request): void
    {
        $id = (int) AuthService::id();
        $numbers = array_column(TaskFlowService::feed($id), 'task_number');
        $active = TaskFlowService::activeTaskId($id);
        header('Cache-Control: no-store');
        $this->json(['version' => md5(implode(',', $numbers) . '|' . $active), 'count' => count($numbers)]);
    }

    public function online(Request $request): void
    {
        $online = $request->str('online') === '1';
        try {
            WorkerService::setOnline((int) AuthService::id(), $online);
            Session::flash('success', $online ? t('You are online. New tasks near you will appear here.') : t('You are offline.'));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/worker');
    }
}
