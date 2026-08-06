#!/usr/bin/env python3
"""HTTP test escrow_room via localhost on VPS (bypass Cloudflare)."""
import json
import os
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
SSH_USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
$sessions = zinesh_json_read('sessions.json');
$users = zinesh_load_users();
$byUid = [];
foreach ($users as $u) { $byUid[$u['uid'] ?? ''] = $u; }
$found = [];
foreach ($sessions as $key => $sess) {
    if (!is_array($sess)) continue;
    $uid = (string)($sess['uid'] ?? '');
    $token = (string)($sess['token'] ?? $key);
    $exp = (int)($sess['expires'] ?? $sess['expiresAt'] ?? 0);
    if ($uid === '' || $token === '') continue;
    if ($exp > 0 && $exp < time()) continue;
    $u = $byUid[$uid] ?? null;
    if (!$u || empty($u['ticketNumber'])) continue;
    $found[] = ['uid'=>$uid,'email'=>$u['email']??'','token'=>$token,'ticket'=>$u['ticketNumber']];
    if (count($found) >= 2) break;
}
if (count($found) < 2) { echo "NO_SESSIONS\n"; exit(1); }
file_put_contents('/tmp/escrow_http_pair.json', json_encode($found));
echo "OK\n";
"""

CURL = r"""#!/bin/bash
pair=$(cat /tmp/escrow_http_pair.json)
A_UID=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[0]["uid"];')
A_EMAIL=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[0]["email"];')
A_TOKEN=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[0]["token"];')
B_UID=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[1]["uid"];')
B_EMAIL=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[1]["email"];')
B_TOKEN=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[1]["token"];')
B_TICKET=$(echo "$pair" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j[1]["ticket"];')

API="http://127.0.0.1/api/escrow_room.php"
HDR='Content-Type: application/json'

connect=$(curl -sS -X POST "$API" -H "$HDR" -d "{\"action\":\"connect\",\"sessionToken\":\"$A_TOKEN\",\"uid\":\"$A_UID\",\"email\":\"$A_EMAIL\",\"peerTicket\":\"$B_TICKET\",\"myRole\":\"employer\"}")
echo "connect=$connect"
ROOM=$(echo "$connect" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j["room"]["id"]??"";')
echo "room=$ROOM"

send=$(curl -sS -X POST "$API" -H "$HDR" -d "{\"action\":\"send_message\",\"sessionToken\":\"$A_TOKEN\",\"uid\":\"$A_UID\",\"email\":\"$A_EMAIL\",\"roomId\":\"$ROOM\",\"body\":\"localhost http test\"}")
echo "send=$send"

detail=$(curl -sS -X POST "$API" -H "$HDR" -d "{\"action\":\"detail\",\"sessionToken\":\"$B_TOKEN\",\"uid\":\"$B_UID\",\"email\":\"$B_EMAIL\",\"roomId\":\"$ROOM\"}")
echo "detail_B=$(echo "$detail" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo count($j["messages"]??[]);') msgs"
"""


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=SSH_USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_pair.php", "w") as f:
        f.write(PHP)
    with sftp.file("/tmp/escrow_curl.sh", "w") as f:
        f.write(CURL)
    sftp.close()
    ssh.exec_command("chmod +x /tmp/escrow_curl.sh")
    _, o1, e1 = ssh.exec_command("php /tmp/z_pair.php")
    print(o1.read().decode())
    print(e1.read().decode())
    _, o2, e2 = ssh.exec_command("bash /tmp/escrow_curl.sh 2>&1")
    print(o2.read().decode())
    print(e2.read().decode())
    ssh.close()


if __name__ == "__main__":
    main()
