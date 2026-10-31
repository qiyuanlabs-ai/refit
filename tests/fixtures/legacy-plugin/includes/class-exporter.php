<?php

class Legacy_Exporter
{
    public function export($rows)
    {
        while (list($key, $row) = each($rows)) {
            echo implode($row, ';') . "\n";
        }
        $text = utf8_encode('caf' . chr(233));
        return money_format('%i', 3.5) . $text;
    }
}
