<?php
require __DIR__ . '/_auth.php'; admin_required();
$o = load_order('id = ?', [(int) ($_GET['id'] ?? 0)], false);
if (!$o) { http_response_code(404); exit('Order not found'); }
$label = ($_GET['type'] ?? '') === 'label';
$qty = array_sum(array_column($o['items'], 'qty'));
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title><?= $label ? 'Label' : 'Packing slip' ?> – <?= h($o['order_number']) ?></title>
<style>
*{box-sizing:border-box}body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#111;margin:0;padding:24px;background:#eee}
.sheet{background:#fff;max-width:<?= $label ? '420px' : '780px' ?>;margin:0 auto;padding:28px;border:1px solid #ccc}
h1{margin:0;font-size:22px;letter-spacing:.04em}.sub{color:#666;font-size:12px}
.row{display:flex;justify-content:space-between;gap:20px;margin:16px 0}
table{width:100%;border-collapse:collapse;margin-top:14px}th,td{border-bottom:1px solid #ddd;padding:8px 6px;text-align:left}th{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#555}
td.q{text-align:center;width:70px;font-weight:700;font-size:16px}.box{border:2px solid #111;padding:14px;margin:12px 0}.big{font-size:20px;font-weight:700}
.bar{font:700 28px/1 monospace;letter-spacing:6px;text-align:center;margin:10px 0;border:1px dashed #999;padding:10px}
.btn{display:inline-block;margin:0 auto 16px;padding:10px 20px;background:#111;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:14px}
@media print{body{background:#fff;padding:0}.sheet{border:0;max-width:none}.no-print{display:none}}
</style></head><body>
<div class="no-print" style="text-align:center"><button class="btn" onclick="window.print()">Print</button></div>
<div class="sheet">
<?php if ($label): ?>
 <div class="sub">FROM: <?= h(cfg('store_name')) ?> · <?= h(cfg('store_address')) ?> · <?= h(cfg('store_phone')) ?></div>
 <div class="box"><div class="sub">DELIVER TO</div><div class="big"><?= h($o['customer_name']) ?></div>
  <div><?= h($o['address']) ?><br><?= h($o['area'] ? $o['area'] . ', ' : '') . h($o['city']) . ' ' . h($o['postal_code']) ?></div><div class="big" style="margin-top:8px">☎ <?= h($o['phone']) ?></div></div>
 <div class="row"><div><b>Order</b><br><?= h($o['order_number']) ?></div><div><b>Pieces</b><br><?= (int) $qty ?></div>
  <div><b>COD amount</b><br><?= $o['payment_method'] === 'cod' && $o['payment_status'] !== 'paid' ? h(money($o['total'])) : 'PAID / none' ?></div></div>
 <?php if ($o['tracking_no']): ?><div class="bar"><?= h($o['tracking_no']) ?></div><?php endif; ?>
 <?php if ($o['notes']): ?><div class="sub">Note: <?= h($o['notes']) ?></div><?php endif; ?>
<?php else: ?>
 <div class="row" style="margin-top:0"><div><h1><?= h(strtoupper(cfg('store_name'))) ?></h1><div class="sub"><?= h(cfg('store_address')) ?> · <?= h(cfg('store_phone')) ?></div></div>
  <div style="text-align:right"><b style="font-size:18px">PACKING SLIP</b><br><?= h($o['order_number']) ?><br><span class="sub"><?= h(dt($o['created_at'])) ?></span></div></div>
 <div class="row"><div><b>Deliver to</b><br><?= h($o['customer_name']) ?><br><?= h($o['address']) ?><br><?= h($o['area'] ? $o['area'] . ', ' : '') . h($o['city']) . ' ' . h($o['postal_code']) ?><br><?= h($o['phone']) ?></div>
  <div style="text-align:right"><b>Payment</b><br><?= h($o['payment_label']) ?><br><?= h(ucfirst($o['payment_status'])) ?><?= $o['payment_method'] === 'cod' && $o['payment_status'] !== 'paid' ? '<br><b>Collect ' . h(money($o['total'])) . '</b>' : '' ?></div></div>
 <table><tr><th>☐</th><th>Item</th><th>Brand</th><th style="text-align:center">Qty</th></tr>
 <?php foreach ($o['items'] as $it): ?><tr><td>☐</td><td><?= h($it['name']) ?></td><td><?= h($it['brand']) ?></td><td class="q"><?= (int) $it['qty'] ?></td></tr><?php endforeach; ?></table>
 <?php if ($o['notes']): ?><p><b>Customer note:</b> <?= h($o['notes']) ?></p><?php endif; ?>
 <p class="sub" style="margin-top:30px">Checked by: ____________________ &nbsp; Packed on: ____________ &nbsp; Customer signature on delivery: ____________________</p>
<?php endif; ?>
</div></body></html>
