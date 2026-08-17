<?php

class AlipayCodeReconciler
{
    public static function normalizeAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    public static function buildPendingMap(array $orders)
    {
        $pending = [];
        foreach ($orders as $order) {
            if (!isset($order['trade_no'], $order['realmoney'])) {
                continue;
            }

            $tradeNo = (string)$order['trade_no'];
            $pending[$tradeNo] = self::normalizeAmount($order['realmoney']);
        }

        return $pending;
    }

    public static function matchDetails(array &$pendingByTradeNo, array $details)
    {
        $matches = [];
        foreach ($details as $detail) {
            if (!isset($detail['trans_memo'], $detail['trans_amount'])) {
                continue;
            }

            $tradeNo = trim(str_replace('请勿添加备注-', '', (string)$detail['trans_memo']));
            if (!isset($pendingByTradeNo[$tradeNo])) {
                continue;
            }
            if ($pendingByTradeNo[$tradeNo] !== self::normalizeAmount($detail['trans_amount'])) {
                continue;
            }

            $matches[] = ['trade_no' => $tradeNo, 'detail' => $detail];
            unset($pendingByTradeNo[$tradeNo]);
        }

        return $matches;
    }
}
