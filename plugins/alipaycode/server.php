<?php
if (substr(php_sapi_name(), 0, 3) != 'cli') {
    die("This Programe can only be run in CLI mode");
}
@chdir(dirname(__FILE__));
$nosession = true;
include("../../includes/common.php");
require_once __DIR__.'/inc/AlipayCodeReconciler.php';
require_once __DIR__.'/inc/AlipayCodePaginator.php';
require_once __DIR__.'/inc/AlipayCodeRecoveryStore.php';
require_once __DIR__.'/inc/AlipayCodeWindowQueue.php';

function alipayCodeWorkerLog($message, $stderr = false){
    $line = '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL;
    if($stderr){
        fwrite(STDERR, $line);
    }else{
        echo $line;
    }
}

if(!isset($argv[1])){
    alipayCodeWorkerLog('支付通道ID不能为空', true);
    exit(1);
}
$channelid = intval($argv[1]);
$channel = \lib\Channel::get($channelid);
if(!$channel || $channel['plugin'] != 'alipaycode'){
    alipayCodeWorkerLog('支付通道不存在', true);
    exit(1);
}
$sql = "";
if(substr($channel['apptoken'], 0, 1) == '['){
    $channel = \lib\Channel::getSub($channelid);
    if(!$channel || $channel['plugin'] != 'alipaycode'){
        alipayCodeWorkerLog('子通道不存在', true);
        exit(1);
    }
    $sql = " AND subchannel='{$channel['subid']}'";
}
$alipay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
$aop = new \Alipay\AlipayBillService($alipay_config);
$recoveryStore = new AlipayCodeRecoveryStore($DB, $channelid);

while(true){
    $now = time();
    $cycleStartedAt = microtime(true);
    $cycleDeadline = $cycleStartedAt + 120;
    $pendingCount = 0;
    $detailCount = 0;
    $matchCount = 0;
    $pageCount = 0;
    $billQuerySeconds = 0.0;
    $processingSeconds = 0.0;
    $processedCount = 0;
    $failedCount = 0;
    $retryCount = 0;
    $windowSeconds = 0;
    $queuedWindowCount = 0;
    $recoveryActive = false;
    $cycleResult = 'idle';

    try{
        try{
            $recoveryWindows = $recoveryStore->load();
        }catch(\Throwable $e){
            alipayCodeWorkerLog($e->getMessage(), true);
            exit(1);
        }

        $list = $DB->getAll("SELECT trade_no,realmoney FROM pre_order WHERE channel='{$channel['id']}'{$sql} AND status=0 AND addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE)");
        if($list === false){
            alipayCodeWorkerLog('数据库查询失败：'.$DB->error(), true);
            exit(1);
        }
        $currentPending = AlipayCodeReconciler::buildPendingMap($list);
        $recoveryActive = !empty($recoveryWindows);
        $recoveryWindows = AlipayCodeWindowQueue::enqueueCurrent($recoveryWindows, $currentPending, time());

        $queuedWindowCount = count($recoveryWindows);

        if(empty($recoveryWindows)){
            alipayCodeWorkerLog('暂无未支付订单...');
        }else{
            try{
                $recoveryStore->save($recoveryWindows);
            }catch(\Throwable $e){
                alipayCodeWorkerLog($e->getMessage(), true);
                exit(1);
            }

            $activeWindow = $recoveryWindows[0];
            $startTimestamp = $activeWindow['start_timestamp'];
            $endTimestamp = $activeWindow['end_timestamp'];
            $pendingMap = $activeWindow['pending'];
            $pendingCount = count($pendingMap);
            $windowSeconds = $endTimestamp - $startTimestamp;
            $start_time = date('Y-m-d H:i:s', $startTimestamp);
            $end_time = date('Y-m-d H:i:s', $endTimestamp);

            $matches = [];
            $pageSize = 2000;
            $maxPages = 100;
            $paginationComplete = false;

            try{
                $pageResult = AlipayCodePaginator::collect(function($pageNo, $requestedPageSize) use($aop, $start_time, $end_time, $cycleDeadline, &$billQuerySeconds){
                    if(microtime(true) >= $cycleDeadline){
                        throw new \RuntimeException('本轮账务查询达到时间预算');
                    }
                    $queryStartedAt = microtime(true);
                    try{
                        return $aop->accountlogQuery($start_time, $end_time, $pageNo, $requestedPageSize);
                    }finally{
                        $billQuerySeconds += microtime(true) - $queryStartedAt;
                    }
                }, $pendingMap, $pageSize, $maxPages);
                $matches = $pageResult['matches'];
                $detailCount = $pageResult['detail_count'];
                $pageCount = $pageResult['page_count'];
                $paginationComplete = true;
            }catch(\Throwable $e){
                alipayCodeWorkerLog('查询账务明细失败：'.$e->getMessage(), true);
                $matches = [];
                $cycleResult = 'api_error';
            }

            if($paginationComplete){
                alipayCodeWorkerLog('共查询到'.$detailCount.'条账务明细');
                $matchCount = count($matches);
                $retryPending = [];
                $processingStartedAt = microtime(true);
                for($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++){
                    if(microtime(true) >= $cycleDeadline){
                        $remainingCount = $matchCount - $matchIndex;
                        for($remainingIndex = $matchIndex; $remainingIndex < $matchCount; $remainingIndex++){
                            $remainingTradeNo = $matches[$remainingIndex]['trade_no'];
                            $retryPending[$remainingTradeNo] = $pendingMap[$remainingTradeNo];
                        }
                        $retryCount += $remainingCount;
                        $failedCount++;
                        alipayCodeWorkerLog('本轮订单处理达到时间预算，剩余'.$remainingCount.'笔将在后续轮次处理', true);
                        break;
                    }

                    $match = $matches[$matchIndex];
                    $trade_no = $match['trade_no'];
                    $item = $match['detail'];

                    try{
                        $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A left join pre_type B on A.type=B.id WHERE trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
                        if($order === false){
                            alipayCodeWorkerLog('读取订单失败：'.$DB->error(), true);
                            exit(1);
                        }

                        $order['plugin'] = $channel['plugin'];
                        $buyer = empty($order['buyer']) ? ($item['other_account'] ?? null) : null;
                        $notifyError = null;
                        try{
                            processNotify($order, $item['alipay_order_no'] ?? null, $buyer);
                        }catch(\Throwable $e){
                            $notifyError = $e;
                            $failedCount++;
                            alipayCodeWorkerLog('订单'.$trade_no.'后续处理异常：'.$e->getMessage(), true);
                        }

                        $status = $DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
                        if($status === false){
                            alipayCodeWorkerLog('校验订单状态失败：'.$DB->error(), true);
                            exit(1);
                        }
                        if((int)$status < 1){
                            $retryCount++;
                            $retryPending[$trade_no] = $pendingMap[$trade_no];
                            alipayCodeWorkerLog('订单'.$trade_no.'状态未更新，将在下一轮重试', true);
                            continue;
                        }

                        $processedCount++;

                        if($notifyError !== null){
                            alipayCodeWorkerLog('订单'.$trade_no.'已确认支付，但后续处理需要检查错误日志', true);
                        }
                        alipayCodeWorkerLog('订单'.$trade_no.'('.$item['trans_amount'].'元)支付成功');
                    }catch(\Throwable $e){
                        $failedCount++;
                        $retryCount++;
                        $retryPending[$trade_no] = $pendingMap[$trade_no];
                        alipayCodeWorkerLog('订单'.$trade_no.'处理异常：'.$e->getMessage(), true);
                    }
                }
                $processingSeconds = microtime(true) - $processingStartedAt;

                try{
                    $recoveryWindows = AlipayCodeWindowQueue::completeActive(
                        $recoveryWindows,
                        array_column($matches, 'trade_no'),
                        $retryPending
                    );

                    if(empty($recoveryWindows)){
                        $recoveryStore->clear();
                    }else{
                        $recoveryStore->save($recoveryWindows);
                    }
                    $queuedWindowCount = count($recoveryWindows);
                }catch(\Throwable $e){
                    alipayCodeWorkerLog($e->getMessage(), true);
                    exit(1);
                }

                $cycleResult = ($failedCount > 0 || $retryCount > 0) ? 'partial_error' : 'ok';
            }
        }
    }catch(\Throwable $e){
        $cycleResult = 'error';
        alipayCodeWorkerLog('本轮处理异常：'.$e->getMessage(), true);
    }

    if($pendingCount > 0){
        alipayCodeWorkerLog(sprintf(
            '本轮统计 result=%s recovery=%d queued_windows=%d window=%ds pending=%d details=%d matched=%d processed=%d failed=%d retry=%d pages=%d bill_query=%.3fs processing=%.3fs total=%.3fs peak_memory=%.2fMiB',
            $cycleResult,
            $recoveryActive ? 1 : 0,
            $queuedWindowCount,
            $windowSeconds,
            $pendingCount,
            $detailCount,
            $matchCount,
            $processedCount,
            $failedCount,
            $retryCount,
            $pageCount,
            $billQuerySeconds,
            $processingSeconds,
            microtime(true) - $cycleStartedAt,
            memory_get_peak_usage(true) / 1048576
        ));
    }

    $time = time()-$now;
    if($time < 3){
        sleep(3-$time);
    }
}
echo 'stop!';
