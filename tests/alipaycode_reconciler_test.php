<?php
require __DIR__.'/../plugins/alipaycode/inc/AlipayCodeReconciler.php';
require __DIR__.'/../plugins/alipaycode/inc/AlipayCodePaginator.php';
require __DIR__.'/../plugins/alipaycode/inc/AlipayCodeRecoveryStore.php';
require __DIR__.'/../plugins/alipaycode/inc/AlipayCodeWindowQueue.php';
require __DIR__.'/../install/AlipayCodeIndexPlanner.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message.PHP_EOL);
        fwrite(STDERR, 'Expected: '.var_export($expected, true).PHP_EOL);
        fwrite(STDERR, 'Actual: '.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}

$empty = AlipayCodeReconciler::buildPendingMap([]);
assertSameValue([], $empty, 'Empty pending list should create an empty map');

$pending = AlipayCodeReconciler::buildPendingMap([
    ['trade_no' => '2026081712000012345', 'realmoney' => '1'],
    ['trade_no' => '2026081712000067890', 'realmoney' => '2.50'],
]);

$firstPage = AlipayCodeReconciler::matchDetails($pending, [
    ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.00', 'alipay_order_no' => 'A1'],
    ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.0', 'alipay_order_no' => 'A1-DUP'],
    ['trans_memo' => '请勿添加备注-2026081712000067890', 'trans_amount' => '2.49', 'alipay_order_no' => 'BAD-AMOUNT'],
    ['trans_memo' => 'other', 'trans_amount' => '2.50', 'alipay_order_no' => 'OTHER'],
]);
assertSameValue(['2026081712000012345'], array_column($firstPage, 'trade_no'), 'First page should contain one deduplicated match');

$secondPage = AlipayCodeReconciler::matchDetails($pending, [
    ['trans_memo' => '请勿添加备注-2026081712000067890', 'trans_amount' => '2.500', 'alipay_order_no' => 'A2'],
]);
assertSameValue(['2026081712000067890'], array_column($secondPage, 'trade_no'), 'Second page should match the remaining order');
assertSameValue([], $pending, 'Matched orders should be removed from the pending map');

$paginationCalls = 0;
$paginationPending = AlipayCodeReconciler::buildPendingMap([
    ['trade_no' => '2026081712000012345', 'realmoney' => '1.00'],
]);
$paginated = AlipayCodePaginator::collect(function ($pageNo, $pageSize) use (&$paginationCalls) {
    $paginationCalls++;
    if ($pageNo === 1) {
        return [
            'page_no' => '1',
            'page_size' => (string)$pageSize,
            'total_size' => '3',
            'detail_list' => [
                ['trans_memo' => 'other-1', 'trans_amount' => '1.00'],
                ['trans_memo' => 'other-2', 'trans_amount' => '1.00'],
            ],
        ];
    }
    return [
        'page_no' => '2',
        'page_size' => (string)$pageSize,
        'total_size' => '3',
        'detail_list' => [
            ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.00'],
        ],
    ];
}, $paginationPending, 2, 3);
assertSameValue(2, $paginationCalls, 'Paginator should fetch the second page');
assertSameValue(3, $paginated['detail_count'], 'Paginator should count details across pages');
assertSameValue(['2026081712000012345'], array_column($paginated['matches'], 'trade_no'), 'Paginator should return cross-page matches');

$failedPageRaised = false;
try {
    AlipayCodePaginator::collect(function ($pageNo) {
        if ($pageNo === 2) {
            throw new RuntimeException('simulated page failure');
        }
        return ['total_size' => '3', 'detail_list' => [['trans_memo' => 'other'], ['trans_memo' => 'other']]];
    }, ['2026081712000012345' => '1.00'], 2, 3);
} catch (RuntimeException $e) {
    $failedPageRaised = true;
}
assertSameValue(true, $failedPageRaised, 'A failed page should fail the entire pagination operation');

$inconsistentTotalRaised = false;
try {
    AlipayCodePaginator::collect(function () {
        return ['total_size' => '3', 'detail_list' => [['trans_memo' => 'other']]];
    }, ['2026081712000012345' => '1.00'], 2, 3);
} catch (RuntimeException $e) {
    $inconsistentTotalRaised = true;
}
assertSameValue(true, $inconsistentTotalRaised, 'A short page with an inconsistent total should fail closed');

$matchedButInconsistentRaised = false;
try {
    AlipayCodePaginator::collect(function () {
        return [
            'total_size' => '3',
            'detail_list' => [
                ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.00'],
            ],
        ];
    }, ['2026081712000012345' => '1.00'], 2, 3);
} catch (RuntimeException $e) {
    $matchedButInconsistentRaised = true;
}
assertSameValue(true, $matchedButInconsistentRaised, 'A matched order must not bypass contradictory page totals');

$pageLimitRaised = false;
try {
    AlipayCodePaginator::collect(function () {
        return ['total_size' => '5', 'detail_list' => [['trans_memo' => 'other'], ['trans_memo' => 'other']]];
    }, ['2026081712000012345' => '1.00'], 2, 2);
} catch (RuntimeException $e) {
    $pageLimitRaised = true;
}
assertSameValue(true, $pageLimitRaised, 'A response beyond the hard page limit should fail closed');

$earlyStopCalls = 0;
$earlyStop = AlipayCodePaginator::collect(function () use (&$earlyStopCalls) {
    $earlyStopCalls++;
    return [
        'total_size' => '5',
        'detail_list' => [
            ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.00'],
            ['trans_memo' => 'other', 'trans_amount' => '1.00'],
        ],
    ];
}, ['2026081712000012345' => '1.00'], 2, 3);
assertSameValue(1, $earlyStopCalls, 'Paginator should stop when all pending orders have matched');
assertSameValue(1, count($earlyStop['matches']), 'Early stop should retain the match');

class FakeRecoveryStatement
{
    private $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function fetchColumn()
    {
        return $this->value;
    }
}

class FakeRecoveryDatabase
{
    public $value = false;
    public $failQuery = false;
    public $failExec = false;

    public function query($sql, $params)
    {
        if ($this->failQuery) {
            return false;
        }
        return new FakeRecoveryStatement($this->value);
    }

    public function exec($sql, $params)
    {
        if ($this->failExec) {
            return false;
        }
        if (strpos($sql, 'REPLACE INTO') === 0) {
            $this->value = $params[':value'];
        } else {
            $this->value = false;
        }
        return 1;
    }

    public function error()
    {
        return 'fake database error';
    }
}

$fakeDb = new FakeRecoveryDatabase();
$store = new AlipayCodeRecoveryStore($fakeDb, 12);
assertSameValue([], $store->load(), 'Missing recovery state should return an empty queue');
$storedWindows = [[
    'start_timestamp' => 1786900000,
    'end_timestamp' => 1786900240,
    'pending' => ['2026081712000012345' => '1.00'],
]];
$store->save($storedWindows);
assertSameValue(
    $storedWindows,
    $store->load(),
    'Recovery window queue should survive a save and load cycle'
);
$store->clear();
assertSameValue([], $store->load(), 'Cleared recovery state should be absent');

$fakeDb->value = '{invalid';
$invalidStateRaised = false;
try {
    $store->load();
} catch (RuntimeException $e) {
    $invalidStateRaised = true;
}
assertSameValue(true, $invalidStateRaised, 'Invalid recovery state should fail closed');

$minuteBase = 1786900000 - (1786900000 % 60);
$windowQueue = AlipayCodeWindowQueue::enqueueCurrent([], ['2026081712000012345' => '1.00'], $minuteBase + 10);
assertSameValue(240, $windowQueue[0]['end_timestamp'] - $windowQueue[0]['start_timestamp'], 'Recovery windows must stay fixed at 240 seconds');
$sameMinuteQueue = AlipayCodeWindowQueue::enqueueCurrent($windowQueue, ['2026081712000067890' => '2.50'], $minuteBase + 40);
assertSameValue(1, count($sameMinuteQueue), 'Orders from the same minute should merge into one window');
assertSameValue(2, count($sameMinuteQueue[0]['pending']), 'Same-minute merge should retain both pending orders');
$nextMinuteQueue = AlipayCodeWindowQueue::enqueueCurrent($sameMinuteQueue, [
    '2026081712000012345' => '1.00',
    '2026081712000099999' => '3.00',
], $minuteBase + 70);
assertSameValue(2, count($nextMinuteQueue), 'A later minute should create a bounded follow-up window');

$unchangedOnFailure = $nextMinuteQueue;
assertSameValue($nextMinuteQueue, $unchangedOnFailure, 'An API failure path should leave the persisted queue unchanged');

$completedQueue = AlipayCodeWindowQueue::completeActive(
    $nextMinuteQueue,
    ['2026081712000012345', '2026081712000067890'],
    ['2026081712000067890' => '2.50']
);
assertSameValue(2, count($completedQueue), 'A retry window should be rotated behind the newer bounded window');
assertSameValue(false, isset($completedQueue[0]['pending']['2026081712000012345']), 'Successful orders should be removed from overlapping windows');
assertSameValue(
    $nextMinuteQueue[0]['start_timestamp'],
    $completedQueue[1]['start_timestamp'],
    'A retried order should keep the exact original window start'
);
assertSameValue(
    $nextMinuteQueue[0]['end_timestamp'],
    $completedQueue[1]['end_timestamp'],
    'A retried order should keep the exact original window end'
);

$missingIndexes = AlipayCodeIndexPlanner::missing([
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'trade_no'],
    ['Key_name' => 'functional_idx', 'Seq_in_index' => 1, 'Column_name' => null],
]);
assertSameValue(
    ['channel_status_addtime', 'channel_sub_status_addtime'],
    array_keys($missingIndexes),
    'Both performance indexes should be planned when absent'
);

$completeIndexRows = [
    ['Key_name' => 'CHANNEL_STATUS_ADDTIME', 'Seq_in_index' => 1, 'Column_name' => 'channel'],
    ['Key_name' => 'CHANNEL_STATUS_ADDTIME', 'Seq_in_index' => 2, 'Column_name' => 'status'],
    ['Key_name' => 'CHANNEL_STATUS_ADDTIME', 'Seq_in_index' => 3, 'Column_name' => 'addtime'],
    ['Key_name' => 'channel_sub_status_addtime', 'Seq_in_index' => 1, 'Column_name' => 'channel'],
    ['Key_name' => 'channel_sub_status_addtime', 'Seq_in_index' => 2, 'Column_name' => 'subchannel'],
    ['Key_name' => 'channel_sub_status_addtime', 'Seq_in_index' => 3, 'Column_name' => 'status'],
    ['Key_name' => 'channel_sub_status_addtime', 'Seq_in_index' => 4, 'Column_name' => 'addtime'],
];
foreach ($completeIndexRows as &$row) {
    $row['Non_unique'] = 1;
    $row['Sub_part'] = null;
    $row['Index_type'] = 'BTREE';
}
unset($row);
assertSameValue([], AlipayCodeIndexPlanner::missing($completeIndexRows), 'Correct indexes should make migration idempotent');

$wrongIndexRaised = false;
$completeIndexRows[1]['Column_name'] = 'addtime';
try {
    AlipayCodeIndexPlanner::missing($completeIndexRows);
} catch (RuntimeException $e) {
    $wrongIndexRaised = true;
}
assertSameValue(true, $wrongIndexRaised, 'A same-name index with wrong column order should fail closed');

$gappedSequenceRaised = false;
$gappedRows = $completeIndexRows;
$gappedRows[1]['Column_name'] = 'status';
$gappedRows[1]['Seq_in_index'] = 4;
try {
    AlipayCodeIndexPlanner::missing($gappedRows);
} catch (RuntimeException $e) {
    $gappedSequenceRaised = true;
}
assertSameValue(true, $gappedSequenceRaised, 'A target index with a sequence gap should fail closed');

$uniqueIndexRaised = false;
$uniqueRows = $completeIndexRows;
$uniqueRows[1]['Column_name'] = 'status';
foreach ($uniqueRows as &$row) {
    if (strtolower($row['Key_name']) === 'channel_status_addtime') {
        $row['Non_unique'] = 0;
    }
}
unset($row);
try {
    AlipayCodeIndexPlanner::missing($uniqueRows);
} catch (RuntimeException $e) {
    $uniqueIndexRaised = true;
}
assertSameValue(true, $uniqueIndexRaised, 'A same-name unique index should fail closed');

$missingMetadataRaised = false;
$missingMetadataRows = $completeIndexRows;
$missingMetadataRows[1]['Column_name'] = 'status';
unset($missingMetadataRows[0]['Index_type']);
try {
    AlipayCodeIndexPlanner::missing($missingMetadataRows);
} catch (RuntimeException $e) {
    $missingMetadataRaised = true;
}
assertSameValue(true, $missingMetadataRaised, 'Missing target index metadata should fail closed');

$prefixIndexRaised = false;
$prefixRows = $completeIndexRows;
$prefixRows[1]['Column_name'] = 'status';
$prefixRows[0]['Sub_part'] = 4;
try {
    AlipayCodeIndexPlanner::missing($prefixRows);
} catch (RuntimeException $e) {
    $prefixIndexRaised = true;
}
assertSameValue(true, $prefixIndexRaised, 'A target prefix index should fail closed');

$hashIndexRaised = false;
$hashRows = $completeIndexRows;
$hashRows[1]['Column_name'] = 'status';
$hashRows[0]['Index_type'] = 'HASH';
try {
    AlipayCodeIndexPlanner::missing($hashRows);
} catch (RuntimeException $e) {
    $hashIndexRaised = true;
}
assertSameValue(true, $hashIndexRaised, 'A non-BTREE target index should fail closed');

echo "alipaycode_reconciler_test: OK\n";
