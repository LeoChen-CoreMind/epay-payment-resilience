<?php

class AlipayCodePaginator
{
    public static function collect(callable $fetchPage, array $pending, $pageSize = 2000, $maxPages = 100)
    {
        $pageSize = (int)$pageSize;
        $maxPages = (int)$maxPages;
        if ($pageSize < 1 || $maxPages < 1) {
            throw new InvalidArgumentException('Invalid pagination limits');
        }

        $matches = [];
        $detailCount = 0;
        $pageCount = 0;
        $totalSize = null;
        $expectedPages = null;

        for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
            $result = $fetchPage($pageNo, $pageSize);
            if (!is_array($result) || !isset($result['detail_list']) || !is_array($result['detail_list'])) {
                throw new RuntimeException('支付宝账务明细返回格式异常');
            }
            if (!array_key_exists('total_size', $result) || !self::isNonNegativeInteger($result['total_size'])) {
                throw new RuntimeException('支付宝账务明细总数格式异常');
            }

            $pageTotalSize = (int)$result['total_size'];
            if ($totalSize === null) {
                $totalSize = $pageTotalSize;
                $expectedPages = max(1, (int)ceil($totalSize / $pageSize));
            } elseif ($pageTotalSize !== $totalSize) {
                throw new RuntimeException('支付宝账务明细分页总数不一致');
            }

            if (isset($result['page_no']) && (!self::isPositiveInteger($result['page_no']) || (int)$result['page_no'] !== $pageNo)) {
                throw new RuntimeException('支付宝账务明细页号不一致');
            }
            if (isset($result['page_size']) && (!self::isPositiveInteger($result['page_size']) || (int)$result['page_size'] !== $pageSize)) {
                throw new RuntimeException('支付宝账务明细页大小不一致');
            }

            $details = $result['detail_list'];
            if (count($details) > $pageSize) {
                throw new RuntimeException('支付宝账务明细单页数量超限');
            }

            $pageCount++;
            $detailCount += count($details);
            if ($detailCount > $totalSize) {
                throw new RuntimeException('支付宝账务明细累计数量超过总数');
            }
            foreach (AlipayCodeReconciler::matchDetails($pending, $details) as $match) {
                $matches[] = $match;
            }

            $responseIsComplete = count($details) < $pageSize || $pageNo >= $expectedPages;
            if ($responseIsComplete && $detailCount !== $totalSize) {
                throw new RuntimeException('支付宝账务明细累计数量不一致');
            }
            if (empty($pending)) {
                return self::result($matches, $detailCount, $pageCount);
            }
            if ($expectedPages > $maxPages) {
                throw new RuntimeException('支付宝账务明细超过单轮分页上限');
            }
            if ($responseIsComplete) {
                return self::result($matches, $detailCount, $pageCount);
            }
        }

        throw new RuntimeException('支付宝账务明细分页未正常结束');
    }

    private static function isNonNegativeInteger($value)
    {
        return preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) === 1;
    }

    private static function isPositiveInteger($value)
    {
        return preg_match('/^[1-9][0-9]*$/D', (string)$value) === 1;
    }

    private static function result(array $matches, $detailCount, $pageCount)
    {
        return [
            'matches' => $matches,
            'detail_count' => $detailCount,
            'page_count' => $pageCount,
        ];
    }
}
