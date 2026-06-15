<?php
$pdo = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '');
$r = $pdo->query('SHOW CREATE TABLE user_roles')->fetch();
echo $r['Create Table'] . "\n\n";

echo "--- user_roles data ---\n";
$rows = $pdo->query('SELECT * FROM user_roles')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo json_encode($row) . "\n";
}
