<?php
declare(strict_types=1);
putenv('ZINESH_SIM_DATA_DIR=/tmp/zinesh_reset_bug');
putenv('ZINESH_DEMO_MODE=1');
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/demo_lib.php';
zinesh_ensure_core_data_files();
zinesh_json_write('users.json', []);
ob_start();
zinesh_demo_reset_escrow_state();
$out = ob_get_clean();
echo $out === '' ? "reset_completed_no_output\n" : $out;
