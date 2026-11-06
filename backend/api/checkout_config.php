<?php
require __DIR__ . '/_bootstrap.php';
$pm = [];
foreach (cfg('payment_methods') as $k => $v) $pm[] = ['key' => $k, 'label' => $v['label'], 'note' => $v['note']];
json_out(['ok' => true, 'payment_methods' => $pm, 'delivery_fee' => (int) cfg('delivery_fee'),
          'free_delivery_over' => (int) cfg('free_delivery_over'), 'max_qty' => (int) cfg('max_qty_per_item'),
          'store' => ['name' => cfg('store_name'), 'phone' => cfg('store_phone'), 'email' => cfg('store_email'), 'address' => cfg('store_address')]]);
