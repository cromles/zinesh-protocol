#!/usr/bin/env python3
"""HTTP test escrow_room.php like the browser."""
import json
import os
import urllib.request
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
SSH_USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")
API = "https://www.zinesh.com/api/escrow_room.php"


def ssh_php(script: str) -> str:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=SSH_USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_http_probe.php", "w") as f:
        f.write(script)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_http_probe.php 2>&1; rm -f /tmp/z_http_probe.php")
    out = stdout.read().decode("utf-8", errors="replace")
    ssh.close()
    return out


def post(payload: dict) -> tuple[int, dict]:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        API,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            return resp.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        try:
            return e.code, json.loads(body)
        except json.JSONDecodeError:
            return e.code, {"raw": body}


def main() -> None:
    # Get two users with valid sessions from server
    setup = r"""<?php
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
$sessions = zinesh_json_read('sessions.json');
$users = zinesh_load_users();
$byUid = [];
foreach ($users as $u) { $byUid[$u['uid'] ?? ''] = $u; }
$found = [];
// sessions.json may be map token=>session or list
foreach ($sessions as $key => $sess) {
    if (!is_array($sess)) continue;
    $uid = (string)($sess['uid'] ?? '');
    $token = (string)($sess['token'] ?? $key);
    $exp = (int)($sess['expires'] ?? $sess['expiresAt'] ?? 0);
    if ($uid === '' || $token === '') continue;
    if ($exp > 0 && $exp < time()) continue;
    $u = $byUid[$uid] ?? null;
    if (!$u || empty($u['ticketNumber'])) continue;
    $found[] = ['uid'=>$uid,'email'=>$u['email']??'','token'=>$token,'ticket'=>$u['ticketNumber'],'name'=>$u['name']??''];
    if (count($found) >= 2) break;
}
echo json_encode($found, JSON_UNESCAPED_UNICODE);
"""
    raw = ssh_php(setup)
    # find json array in output
    start = raw.find("[")
    if start < 0:
        print("No sessions found:", raw)
        return
    pair = json.loads(raw[start:raw.rfind("]") + 1])
    if len(pair) < 2:
        print("Need 2 active sessions, got:", len(pair), raw)
        return
    a, b = pair[0], pair[1]
    print("userA", a["email"], "ticket", a["ticket"])
    print("userB", b["email"], "ticket", b["ticket"])

    # A lists rooms
    code, data = post({"action": "list", "sessionToken": a["token"], "uid": a["uid"], "email": a["email"]})
    print("list_A", code, "rooms", len(data.get("rooms", [])))

    # A connects to B
    code, data = post({
        "action": "connect",
        "sessionToken": a["token"],
        "uid": a["uid"],
        "email": a["email"],
        "peerTicket": b["ticket"],
        "myRole": "employer",
    })
    print("connect", code, data.get("message"), "ok", data.get("ok"))
    if not data.get("ok"):
        print(json.dumps(data, ensure_ascii=False, indent=2))
        return
    room_id = data["room"]["id"]
    print("room_id", room_id)

    # B lists - should see room
    code, dataB = post({"action": "list", "sessionToken": b["token"], "uid": b["uid"], "email": b["email"]})
    print("list_B", code, "rooms", len(dataB.get("rooms", [])))

    # A sends message via HTTP
    code, send = post({
        "action": "send_message",
        "sessionToken": a["token"],
        "uid": a["uid"],
        "email": a["email"],
        "roomId": room_id,
        "body": "HTTP test mesaji",
    })
    print("send_A", code, "ok", send.get("ok"), "msgs", len(send.get("messages", [])))
    if not send.get("ok"):
        print(json.dumps(send, ensure_ascii=False, indent=2))

    # B fetches detail
    code, detail = post({
        "action": "detail",
        "sessionToken": b["token"],
        "uid": b["uid"],
        "email": b["email"],
        "roomId": room_id,
    })
    print("detail_B", code, "msgs", len(detail.get("messages", [])))
    if detail.get("messages"):
        print("last", detail["messages"][-1].get("body"))


if __name__ == "__main__":
    main()
