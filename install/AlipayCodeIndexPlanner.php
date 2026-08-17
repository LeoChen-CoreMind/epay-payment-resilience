<?php

class AlipayCodeIndexPlanner
{
    public static function expected()
    {
        return [
            'channel_status_addtime' => ['channel', 'status', 'addtime'],
            'channel_sub_status_addtime' => ['channel', 'subchannel', 'status', 'addtime'],
        ];
    }

    public static function missing(array $indexRows)
    {
        $expected = self::expected();
        $actual = [];
        foreach ($indexRows as $row) {
            if (!isset($row['Key_name'])) {
                throw new RuntimeException('Invalid SHOW INDEX result');
            }

            $name = strtolower((string)$row['Key_name']);
            if (!isset($expected[$name])) {
                continue;
            }
            if (!isset($row['Seq_in_index'], $row['Column_name'])) {
                throw new RuntimeException('Invalid target index metadata');
            }

            $sequence = (int)$row['Seq_in_index'];
            if ($sequence < 1) {
                throw new RuntimeException('Invalid index column sequence');
            }
            if (isset($actual[$name][$sequence])) {
                throw new RuntimeException('Duplicate index column sequence');
            }
            if (!array_key_exists('Non_unique', $row) || !array_key_exists('Sub_part', $row) || !array_key_exists('Index_type', $row)) {
                throw new RuntimeException('Incomplete target index metadata');
            }
            if ($row['Non_unique'] !== 1 && $row['Non_unique'] !== '1') {
                throw new RuntimeException('Target index must be non-unique');
            }
            if ($row['Sub_part'] !== null) {
                throw new RuntimeException('Target index must not use prefix columns');
            }
            if (!is_string($row['Index_type']) || strtoupper($row['Index_type']) !== 'BTREE') {
                throw new RuntimeException('Target index must use BTREE');
            }
            $actual[$name][$sequence] = strtolower((string)$row['Column_name']);
        }

        foreach ($actual as $name => &$columns) {
            ksort($columns);
            if (array_keys($columns) !== range(1, count($columns))) {
                throw new RuntimeException('Index '.$name.' has a non-contiguous column sequence');
            }
            $columns = array_values($columns);
        }
        unset($columns);

        $missing = [];
        foreach ($expected as $name => $columns) {
            $normalizedName = strtolower($name);
            if (!isset($actual[$normalizedName])) {
                $missing[$name] = $columns;
                continue;
            }
            if ($actual[$normalizedName] !== $columns) {
                throw new RuntimeException('Index '.$name.' exists with an unexpected definition');
            }
        }

        return $missing;
    }
}
