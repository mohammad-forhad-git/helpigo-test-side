<section class="container page page--narrow prose">
    <h1 class="page__title">Cancellation and Refund Policy</h1>
    <?= partial('pages/_nav', ['pages' => $pages]) ?>
    <h2>Cancelling a task</h2>
    <table class="table">
        <thead><tr><th>When you cancel</th><th>Charge</th></tr></thead>
        <tbody>
            <tr><td>Before a helper accepts</td><td>Free</td></tr>
            <tr><td>After a helper accepts, before buying anything</td><td><?= e(money_db(setting('cancel_fee_assigned'), false)) ?>, paid to the helper</td></tr>
            <tr><td>After items are bought</td><td>Item cost + full fees (contact support)</td></tr>
        </tbody>
    </table>
    <p>Tasks that no helper accepts in time close automatically at no charge.</p>
    <h2>Reporting a problem</h2>
    <p>Report a problem in the app within <?= (int) setting('dispute_window_hours') ?> hours of delivery. Staff review the receipt, photos, chat and status history, reply within 24 hours and decide within 72 hours. The helper's payment for that task is held until the decision.</p>
    <h2>Refunds</h2>
    <ul>
        <li>Refunds become Helpigo credit at once and are used automatically on your next task.</li>
        <li>You can ask for a credit to be sent to your bKash/Nagad instead; we send it within <?= (int) setting('refund_hours') ?> hours.</li>
        <li>Unspent advance payments for items are refunded the same way when the task completes or closes.</li>
    </ul>
</section>
