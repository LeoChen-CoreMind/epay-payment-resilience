<?php

class AlipayCodeRecoveryStore
{
    private $db;
    private $key;

    public function __construct($db, $channelId)
    {
        $this->db = $db;
        $this->key = 'alipaycode_retry_'.(int)$channelId;
    }

    public function load()
    {
        $statement = $this->db->query('SELECT v FROM pre_cache WHERE k=:key LIMIT 1', [':key' => $this->key]);
        if ($statement === false) {
            throw new RuntimeException('读取支付宝补偿状态失败：'.$this->db->error());
        }

        $raw = $statement->fetchColumn();
        if ($raw === false || $raw === '') {
            return [];
        }

        $state = json_decode($raw, true);
        if (!is_array($state) || !isset($state['version'], $state['windows']) ||
            $state['version'] !== 1 || !is_array($state['windows'])) {
            throw new RuntimeException('支付宝补偿状态格式异常');
        }

        $windows = [];
        foreach ($state['windows'] as $window) {
            if (!is_array($window) || !isset($window['start_timestamp'], $window['end_timestamp'], $window['pending']) ||
                !is_int($window['start_timestamp']) || !is_int($window['end_timestamp']) ||
                $window['start_timestamp'] <= 0 || $window['end_timestamp'] <= $window['start_timestamp'] ||
                $window['end_timestamp'] - $window['start_timestamp'] !== 240 ||
                !is_array($window['pending'])) {
                throw new RuntimeException('支付宝补偿窗口格式异常');
            }

            $pending = [];
            foreach ($window['pending'] as $tradeNo => $amount) {
                if ((!is_string($tradeNo) && !is_int($tradeNo)) || (string)$tradeNo === '' || !is_string($amount)) {
                    throw new RuntimeException('支付宝补偿订单格式异常');
                }
                $pending[(string)$tradeNo] = $amount;
            }

            $windows[] = [
                'start_timestamp' => $window['start_timestamp'],
                'end_timestamp' => $window['end_timestamp'],
                'pending' => $pending,
            ];
        }

        return $windows;
    }

    public function save(array $windows)
    {
        $payload = json_encode([
            'version' => 1,
            'windows' => $windows,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('编码支付宝补偿状态失败');
        }

        $result = $this->db->exec(
            'REPLACE INTO pre_cache (k,v,expire) VALUES (:key,:value,0)',
            [':key' => $this->key, ':value' => $payload]
        );
        if ($result === false) {
            throw new RuntimeException('保存支付宝补偿状态失败：'.$this->db->error());
        }
    }

    public function clear()
    {
        if ($this->db->exec('DELETE FROM pre_cache WHERE k=:key', [':key' => $this->key]) === false) {
            throw new RuntimeException('清除支付宝补偿状态失败：'.$this->db->error());
        }
    }
}
