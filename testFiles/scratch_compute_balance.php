<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT SUM(amount_fc) FROM collection_slip_lines WHERE account_id = 5");
$coll_fc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_bc) FROM collection_slip_lines WHERE account_id = 5");
$coll_bc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_fc) FROM payment_slip_lines WHERE account_id = 5");
$pay_fc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_bc) FROM payment_slip_lines WHERE account_id = 5");
$pay_bc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_from) FROM account_transfers WHERE from_account_id = 5");
$trans_out_fc = $stmt->fetchColumn() ?: 0;
// Note: amount_from is the FC amount deducted from the sender.

$stmt = $db->query("SELECT SUM(amount_bc) FROM account_transfers WHERE from_account_id = 5");
$trans_out_bc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_to) FROM account_transfers WHERE to_account_id = 5");
$trans_in_fc = $stmt->fetchColumn() ?: 0;

$stmt = $db->query("SELECT SUM(amount_bc) FROM account_transfers WHERE to_account_id = 5");
$trans_in_bc = $stmt->fetchColumn() ?: 0;

echo "Collections FC: $coll_fc, BC: $coll_bc\n";
echo "Payments FC: $pay_fc, BC: $pay_bc\n";
echo "Transfers OUT FC: $trans_out_fc, BC: $trans_out_bc\n";
echo "Transfers IN FC: $trans_in_fc, BC: $trans_in_bc\n";

$net_fc = $coll_fc - $pay_fc + $trans_in_fc - $trans_out_fc;
$net_bc = $coll_bc - $pay_bc + $trans_in_bc - $trans_out_bc;

echo "Expected Balance FC: $net_fc\n";
echo "Expected Balance BC: $net_bc\n";

$stmt = $db->query("SELECT balance_fc, balance FROM business_accounts WHERE id = 5");
$actual = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Actual Balance FC: " . $actual['balance_fc'] . "\n";
echo "Actual Balance BC: " . $actual['balance'] . "\n";
