<?php

class AlipayCodeWindowQueue
{
    const BUCKET_SECONDS = 60;
    const LOOKBACK_SECONDS = 180;
    const FUTURE_SECONDS = 60;

    public static function enqueueCurrent(array $windows, array $pending, $timestamp)
    {
        if (empty($pending)) {
            return $windows;
        }

        $timestamp = (int)$timestamp;
        $bucketTimestamp = $timestamp - ($timestamp % self::BUCKET_SECONDS);
        $startTimestamp = $bucketTimestamp - self::LOOKBACK_SECONDS;
        $endTimestamp = $bucketTimestamp + self::FUTURE_SECONDS;

        foreach ($windows as &$window) {
            if ($window['start_timestamp'] === $startTimestamp && $window['end_timestamp'] === $endTimestamp) {
                foreach ($pending as $tradeNo => $amount) {
                    $window['pending'][$tradeNo] = $amount;
                }
                unset($window);
                return $windows;
            }
        }
        unset($window);

        $windows[] = [
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp,
            'pending' => $pending,
        ];
        return $windows;
    }

    public static function completeActive(array $windows, array $matchedTradeNos, array $retryPending)
    {
        if (empty($windows)) {
            throw new InvalidArgumentException('Cannot complete an empty recovery queue');
        }

        $activeWindow = array_shift($windows);
        foreach ($matchedTradeNos as $tradeNo) {
            foreach ($windows as &$window) {
                unset($window['pending'][$tradeNo]);
            }
            unset($window);
        }

        $windows = array_values(array_filter($windows, function ($window) {
            return !empty($window['pending']);
        }));

        if (!empty($retryPending)) {
            $windows[] = [
                'start_timestamp' => $activeWindow['start_timestamp'],
                'end_timestamp' => $activeWindow['end_timestamp'],
                'pending' => $retryPending,
            ];
        }

        return $windows;
    }
}
