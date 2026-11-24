<?php

declare(strict_types=1);

namespace App\Controllers\Worker;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\ReviewService;
use App\Services\TaskFlowService;
use DomainException;

/** Helper task actions (HLP-03 … HLP-08, HLP-10). Every rule is enforced in TaskFlowService. */
final class TaskController extends Controller
{
    public function index(Request $request): void
    {
        $past = $request->query('show') === 'past';
        $this->render('worker/tasks/index', [
            'title' => t('My tasks'),
            'past'  => $past,
            'tasks' => TaskFlowService::workerTasks((int) AuthService::id(), $past),
        ]);
    }

    public function show(Request $request, string $number): void
    {
        $id = (int) AuthService::id();
        $task = TaskFlowService::findForWorker($id, $number) ?? $this->notFound();
        $this->render('worker/tasks/show', [
            'title'  => $task['title'],
            'task'   => $task,
            'block'  => $task['is_mine'] ? null : TaskFlowService::workerBlock($id),
            'review' => ReviewService::mine((int) $task['id'], $id),
        ]);
    }

    public function status(Request $request, string $number): void
    {
        $task = TaskFlowService::findForWorker((int) AuthService::id(), $number) ?? $this->notFound();
        header('Cache-Control: no-store');
        $pending = count(array_filter($task['approvals'], static fn ($a) => $a['status'] === 'pending'));
        $this->json(['version' => $task['status'] . '.' . count($task['approvals']) . '.' . $pending . '.' . ($task['advance_sent'] ? 1 : 0) . '.' . ($task['is_mine'] ? 1 : 0)]);
    }

    public function accept(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number): string {
            TaskFlowService::accept((int) AuthService::id(), $number);
            return t('Task accepted. The address is now shown below.');
        });
    }

    public function offer(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::sendOffer((int) AuthService::id(), $number, $request->str('amount'), $request->str('note'));
            return t('Offer sent. You will be told if the customer chooses you.');
        });
    }

    public function start(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number): string {
            TaskFlowService::start((int) AuthService::id(), $number);
            return t('Started. Keep the customer updated in the chat.');
        });
    }

    public function approval(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::requestApproval((int) AuthService::id(), $number, $request->str('type'), $request->str('description'), $request->str('new_budget'));
            return t('Request sent. Wait for the customer to answer before buying.');
        });
    }

    public function receipt(Request $request, string $number): void
    {
        $statuses = is_array($request->input('item_status')) ? $request->input('item_status') : [];
        $this->attempt($number, static function () use ($number, $request, $statuses): string {
            TaskFlowService::saveReceipt((int) AuthService::id(), $number, $request->str('item_cost'), $request->file('receipt'), $statuses);
            return t('Receipt saved.');
        });
    }

    public function deliver(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number): string {
            TaskFlowService::startDelivery((int) AuthService::id(), $number);
            return t('The customer knows you are on the way.');
        });
    }

    public function complete(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::completeWithCode((int) AuthService::id(), $number, $request->str('code'));
            return t('Task completed. Well done!');
        });
    }

    public function noCode(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            TaskFlowService::deliverWithoutCode((int) AuthService::id(), $number, $request->file('photo'), $request->str('note'));
            return t('Saved. The customer has :h hours to confirm or report a problem.', ['h' => setting('auto_complete_hours')]);
        });
    }

    public function drop(Request $request, string $number): void
    {
        try {
            TaskFlowService::dropByWorker((int) AuthService::id(), $number, $request->str('reason'));
            Session::flash('info', t('Task released. Dropping tasks counts against your record.'));
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
            $this->redirect('/worker/tasks/' . rawurlencode($number));
        }
        $this->redirect('/worker');
    }

    public function rate(Request $request, string $number): void
    {
        $this->attempt($number, static function () use ($number, $request): string {
            ReviewService::rate((array) AuthService::user(), $number, $request->int('rating'), $request->str('comment'));
            return t('Thanks for your rating.');
        });
    }

    private function attempt(string $number, callable $action): never
    {
        try {
            Session::flash('success', $action());
        } catch (DomainException $e) {
            Session::flash('error', t($e->getMessage()));
        }
        $this->redirect('/worker/tasks/' . rawurlencode($number));
    }
}
