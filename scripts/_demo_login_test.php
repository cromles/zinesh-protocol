<?php
declare(strict_types=1);
putenv('ZINESH_SIM_DATA_DIR=/tmp/zinesh_login_test');
putenv('ZINESH_DEMO_MODE=1');
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/demo_lib.php';
zinesh_ensure_core_data_files();
zinesh_json_write('users.json', []);
zinesh_demo_ensure_users();
$r1 = zinesh_demo_login('employer');
$r2 = zinesh_demo_login('worker');
echo json_encode(['emp' => $r1, 'wrk' => $r2], JSON_UNESCAPED_UNICODE);
