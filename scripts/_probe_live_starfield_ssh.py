"""Probe live www HTML/CSS from the VPS (bypasses Cloudflare bot wall)."""
from __future__ import annotations

import re
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()


def run(ssh: paramiko.SSHClient, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return (out or err).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        html = run(
            ssh,
            "curl -s -H 'Host: www.zinesh.com' http://127.0.0.1/ | head -c 20000",
        )
        css_paths = re.findall(r"/assets/index-[^\"']+\.css", html)
        js_paths = re.findall(r"/assets/main-[^\"']+\.js", html)
        print("css:", css_paths)
        print("js:", js_paths)

        keys = ["home-starfield", "homeStarTwinkle", "homeShootingStar", "home-star-dot"]
        for path in css_paths[:1] + js_paths[:1]:
            body = run(
                ssh,
                f"curl -s -H 'Host: www.zinesh.com' http://127.0.0.1{path} | head -c 500000",
            )
            print(path, {k: (k in body) for k in keys})

        # quick animation snippet check
        if css_paths:
            snippet = run(
                ssh,
                f"curl -s -H 'Host: www.zinesh.com' http://127.0.0.1{css_paths[0]} | tr '{{' '\\n' | grep -E 'homeStarTwinkle|homeShootingStar' | head -5",
            )
            print("animation rules:", snippet or "(none)")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
