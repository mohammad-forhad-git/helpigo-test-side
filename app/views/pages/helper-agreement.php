<section class="container page page--narrow prose">
    <h1 class="page__title">Helper Agreement</h1>
    <?= partial('pages/_nav', ['pages' => $pages]) ?>
    <h2>Your status</h2>
    <p>You are an independent service provider, not a Helpigo employee. You choose when you are online, your areas, and which tasks to accept.</p>
    <h2>Earnings</h2>
    <ul>
        <li>You see the helper fee before accepting. Helpigo keeps a <?= e(App\Services\Money::rateLabel((string) setting('commission_rate'))) ?> commission from it.</li>
        <li>For cash tasks you collect the fees and owe Helpigo its share; for wallet tasks Helpigo owes you your earnings. Your balance is in the Earnings tab.</li>
        <li>Positive balances are paid every week to your bKash/Nagad/Rocket. If you owe more than <?= e(money_db(setting('max_worker_dues'), false)) ?>, you cannot take tasks until you pay.</li>
    </ul>
    <h2>Rules</h2>
    <ul>
        <li>Buy only within the budget and your purchase limit; ask the customer in the app before spending more or substituting.</li>
        <li>Always upload a clear receipt photo. Hand over at the door; do not enter homes.</li>
        <li>Finish with the customer's delivery code. Never ask for it before hand-over.</li>
        <li>Do not contact customers outside a task or keep their details.</li>
        <li>Dropping <?= (int) setting('strike_limit') ?> accepted tasks in 30 days pauses your account for review.</li>
        <li>Prohibited items: see <a href="<?= e(url('/safety')) ?>">Safety and Prohibited Items</a>.</li>
    </ul>
</section>
