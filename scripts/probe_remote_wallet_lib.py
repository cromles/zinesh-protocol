#!/usr/bin/env python3
import os
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    cmds = [
        "grep -n tlHavale /www/wwwroot/zinesh.com/api/wallet_lib.php | head -5",
        "grep -n tl_havale /www/wwwroot/zinesh.com/api/config.php | head -5",
        "grep -n \"'enabled'\" /www/wwwroot/zinesh.com/api/config.local.php 2>/dev/null | head -10 || echo 'no local'",
        "curl -s -m 10 'https://www.zinesh.com/api/payment.php?action=status'",
        "curl -s -m 5 -o /dev/null -w 'main=%{http_code}\\n' https://www.zinesh.com/assets/main-CeFhJI-V.js",
        "curl -s https://www.zinesh.com/ | grep -oE 'main-[A-Za-z0-9_-]+\\.js' | head -1",
    ]
    for cmd in cmds:
        print(f"--- {cmd} ---")
        _, stdout, stderr = ssh.exec_command(cmd)
        print(stdout.read().decode())
        err = stderr.read().decode().strip()
        if err:
            print("ERR:", err)
    ssh.close()

if __name__ == "__main__":
    main()
