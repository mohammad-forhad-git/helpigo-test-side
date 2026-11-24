<?php
$target = static function (?float $value, float $goal, bool $higherIsBetter = true): string {
    if ($value === null) {
        return '';
    }
    $ok = $higherIsBetter ? $value >= $goal : $value <= $goal;
    return $ok ? 'ok-text' : 'danger-text';
};
$maxCompleted = max(1, max(array_column($daily, 'completed') ?: [0]));
?>
<h1 class="page__title">Reports</h1>
<form method="get" action="<?= e(url('/admin/reports')) ?>" class="inline-form filter-bar">
    <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn--sm" type="submit">Show</button>
    <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/reports/daily.csv', ['from' => $from, 'to' => $to])) ?>">Download CSV</a>
</form>

<h2>Pilot targets (proposal §17)</h2>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Measure</th><th class="num">Result</th><th class="num">Target</th></tr></thead>
    <tbody>
        <tr><td>Completed tasks per day</td><td class="num <?= $target((float) $kpis['completed_per_day'], 65) ?>"><?= e((string) $kpis['completed_per_day']) ?></td><td class="num">65+</td></tr>
        <tr><td>Median minutes to a helper accepting</td><td class="num <?= $target($kpis['median_wait'] !== null ? (float) $kpis['median_wait'] : null, 10, false) ?>"><?= $kpis['median_wait'] ?? '–' ?></td><td class="num">≤ 10</td></tr>
        <tr><td>Completed out of posted (%)</td><td class="num <?= $target($kpis['completion_rate'], 90) ?>"><?= $kpis['completion_rate'] ?? '–' ?></td><td class="num">90+</td></tr>
        <tr><td>Customers with 2+ completed tasks (%)</td><td class="num <?= $target($kpis['repeat_rate'], 35) ?>"><?= $kpis['repeat_rate'] ?? '–' ?></td><td class="num">35+</td></tr>
        <tr><td>Average helper rating</td><td class="num <?= $target($kpis['avg_rating'], 4.5) ?>"><?= $kpis['avg_rating'] ?? '–' ?></td><td class="num">4.5+</td></tr>
        <tr><td>Tasks with an upheld complaint (%)</td><td class="num <?= $target($kpis['complaint_rate'], 3, false) ?>"><?= $kpis['complaint_rate'] ?? '–' ?></td><td class="num">&lt; 3</td></tr>
    </tbody>
</table>
</div>

<div class="stat-grid">
    <div class="stat"><span class="stat__num"><?= (int) $kpis['created'] ?></span><span class="stat__label">Tasks posted</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $kpis['completed'] ?></span><span class="stat__label">Completed</span></div>
    <div class="stat"><span class="stat__num"><?= e(money($kpis['revenue'], false)) ?></span><span class="stat__label">Helpigo revenue</span></div>
    <div class="stat"><span class="stat__num"><?= e(money($kpis['revenue_per_task'], false)) ?></span><span class="stat__label">Revenue per task</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $kpis['customers'] ?></span><span class="stat__label">Customers (all time)</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $kpis['helpers_approved'] ?></span><span class="stat__label">Approved helpers</span></div>
</div>

<h2>Completed tasks per day</h2>
<div class="bars" role="img" aria-label="Completed tasks per day">
    <?php foreach ($daily as $d): ?>
        <div class="bars__col" title="<?= e($d['date']) ?>: <?= (int) $d['completed'] ?> completed, <?= (int) $d['created'] ?> posted, <?= e(money($d['revenue'])) ?>">
            <span class="bars__val"><?= (int) $d['completed'] ?></span>
            <span class="bars__bar" style="height: <?= round(100 * $d['completed'] / $maxCompleted) ?>%"></span>
            <span class="bars__label"><?= e(date('j M', strtotime($d['date']))) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<p class="small muted">Revenue = platform fee + commission − discounts on completed tasks. Payment and SMS costs are not deducted.</p>
