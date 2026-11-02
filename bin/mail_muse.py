#!/usr/bin/env python3
"""Mail helper for the assistant's own mailbox you@your-domain.example (Sakura Internet).

Two modes:

- Bridge mode (default): POST JSON to the muse-mail-bridge adapter (a
  Cloudflare Worker in ../worker/; a PHP variant for accounts with web
  space is in ../bridge/). The request carries the registered connection
  custom.sakura-mail's credential as a Bearer surrogate; the real bridge
  token is swapped in on egress to the registered host, and the bridge
  performs the IMAP/SMTP work on its side with the mailbox password from
  its own secret config. No password is needed from the user in this mode.
  The bridge URL can be overridden with the MUSE_MAIL_BRIDGE_URL env var.

- Direct mode (--direct): talk IMAP/SMTP to the mail server from here.
  The mailbox password is supplied per task on stdin only (first line),
  never via argv, env or files. Note: sending in direct mode is refused
  by Sakura's foreign-IP filter unless that filter is disabled, because
  this environment's egress IP is treated as non-Japanese.

Usage:
  mail_muse.py [--direct] check
  mail_muse.py [--direct] list [--limit N]
  mail_muse.py [--direct] read --uid UID
  mail_muse.py [--direct] send --to ADDR --subject S --body-file PATH

Every network connection goes through the Muse "Direct network protocols"
approval (email_mailbox / email_smtp must be set to Ask by the user); the
bridge call is an ordinary HTTPS request to a registered connector host.
"""
import argparse
import email
import email.policy
import imaplib
import json
import os
import smtplib
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from email.message import EmailMessage

sys.path.insert(0, "/opt/muse/skills/custom-connector-setup/bin")
from dynamic_credentials import add_surrogate_to_request, read_json_response

HOST = "your-initial-domain.sakura.ne.jp"
IMAP_PORT = 993
SMTP_PORT = 587
ADDRESS = "you@your-domain.example"
USERNAME = "you@your-domain.example"
CREDENTIAL = "custom.sakura-mail"
BRIDGE_URL = os.environ.get(
    "MUSE_MAIL_BRIDGE_URL", "https://your-initial-domain.sakura.ne.jp/muse-mail-bridge.php"
)
BRIDGE_HOSTS = ["your-initial-domain.sakura.ne.jp", "www0000.sakura.ne.jp"]


# --------------------------------------------------------------------------
# Bridge mode
# --------------------------------------------------------------------------

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        raise RuntimeError("redirect refused, verify the endpoint")


def bridge_call(payload: dict) -> dict:
    data = json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        BRIDGE_URL,
        data=data,
        headers={
            "Content-Type": "application/json",
            "User-Agent": "muse-mail-client/1.0",
        },
        method="POST",
    )
    hosts = {h for h in BRIDGE_HOSTS if h}
    url_host = urllib.parse.urlparse(BRIDGE_URL).hostname
    if url_host:
        hosts.add(url_host)
    add_surrogate_to_request(request, CREDENTIAL, allowed_hosts=sorted(hosts))
    # Mirror the surrogate into a custom header as well: some PHP SAPIs do
    # not expose the Authorization header to scripts, and the bridge
    # accepts X-Bridge-Token too. Both carry only the surrogate.
    auth = request.get_header("Authorization")
    if auth and auth.startswith("Bearer "):
        request.add_header("X-Bridge-Token", auth[len("Bearer "):])
    opener = urllib.request.build_opener(NoRedirect())
    try:
        with opener.open(request, timeout=60) as response:
            return read_json_response(response)
    except urllib.error.HTTPError as err:
        # The bridge answers errors as JSON too (401 unauthorized, 500
        # config problems); surface that instead of a bare traceback.
        try:
            return read_json_response(err)
        except Exception:
            return {"ok": False, "error": f"bridge HTTP {err.code}"}


# --------------------------------------------------------------------------
# Direct mode (IMAP/SMTP from here, password on stdin)
# --------------------------------------------------------------------------

def read_password() -> str:
    pw = sys.stdin.readline().strip()
    if not pw:
        raise SystemExit("no password on stdin")
    return pw


def imap_connect(password: str) -> imaplib.IMAP4_SSL:
    conn = imaplib.IMAP4_SSL(HOST, IMAP_PORT, ssl_context=ssl.create_default_context())
    conn.login(USERNAME, password)
    return conn


def header_text(msg, name: str) -> str:
    value = msg.get(name, "")
    return str(email.header.make_header(email.header.decode_header(value)))


def cmd_check_direct(password: str) -> dict:
    conn = imap_connect(password)
    try:
        typ, folders = conn.list()
        names = []
        for line in folders or []:
            text = line.decode(errors="replace")
            names.append(text.rsplit(" ", 1)[-1].strip('"'))
        conn.select("INBOX", readonly=True)
        typ, data = conn.search(None, "ALL")
        total = len(data[0].split()) if data and data[0] else 0
        typ, data = conn.search(None, "UNSEEN")
        unseen = len(data[0].split()) if data and data[0] else 0
        return {"ok": True, "user": USERNAME, "folders": names,
                "inbox_total": total, "inbox_unseen": unseen}
    finally:
        conn.logout()


def cmd_list_direct(password: str, limit: int) -> dict:
    conn = imap_connect(password)
    try:
        conn.select("INBOX", readonly=True)
        typ, data = conn.search(None, "ALL")
        ids = data[0].split() if data and data[0] else []
        ids = ids[-limit:]
        out = []
        for num in reversed(ids):
            typ, fetched = conn.fetch(num, "(UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])")
            uid = None
            raw = b""
            for part in fetched:
                if isinstance(part, tuple):
                    meta = part[0].decode(errors="replace")
                    if "UID " in meta:
                        uid = meta.split("UID ")[1].split(" ")[0].rstrip(")")
                    raw = part[1]
            msg = email.message_from_bytes(raw, policy=email.policy.default)
            out.append({
                "num": num.decode(),
                "uid": uid,
                "date": header_text(msg, "Date"),
                "from": header_text(msg, "From"),
                "subject": header_text(msg, "Subject"),
            })
        return {"ok": True, "messages": out}
    finally:
        conn.logout()


def cmd_read_direct(password: str, uid: str) -> dict:
    conn = imap_connect(password)
    try:
        conn.select("INBOX", readonly=True)
        typ, fetched = conn.uid("FETCH", uid, "(RFC822)")
        raw = None
        for part in fetched:
            if isinstance(part, tuple):
                raw = part[1]
        if raw is None:
            return {"ok": False, "error": f"uid {uid} not found"}
        msg = email.message_from_bytes(raw, policy=email.policy.default)
        body = ""
        if msg.is_multipart():
            for part in msg.walk():
                if part.get_content_type() == "text/plain":
                    body = part.get_content()
                    break
        else:
            body = msg.get_content() if msg.get_content_type() == "text/plain" else ""
        return {"ok": True, "uid": uid,
                "date": header_text(msg, "Date"),
                "from": header_text(msg, "From"),
                "to": header_text(msg, "To"),
                "subject": header_text(msg, "Subject"),
                "body": body}
    finally:
        conn.logout()


def cmd_send_direct(password: str, to: str, subject: str, body_file: str) -> dict:
    with open(body_file, encoding="utf-8") as fh:
        body = fh.read()
    msg = EmailMessage()
    msg["From"] = ADDRESS
    msg["To"] = to
    msg["Subject"] = subject
    msg.set_content(body)
    ctx = ssl.create_default_context()
    with smtplib.SMTP(HOST, SMTP_PORT, timeout=30) as smtp:
        smtp.starttls(context=ctx)
        smtp.login(USERNAME, password)
        smtp.send_message(msg)
    return {"ok": True, "from": ADDRESS, "to": to, "subject": subject}


# --------------------------------------------------------------------------
# Entry point
# --------------------------------------------------------------------------

def main() -> None:
    global USERNAME
    parser = argparse.ArgumentParser()
    parser.add_argument("--user", default=USERNAME,
                        help="direct mode: login user id form (default: full address)")
    parser.add_argument("--direct", action="store_true",
                        help="talk IMAP/SMTP directly with a stdin password "
                             "instead of using the bridge")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check")
    p_list = sub.add_parser("list")
    p_list.add_argument("--limit", type=int, default=20)
    p_read = sub.add_parser("read")
    p_read.add_argument("--uid", required=True)
    p_send = sub.add_parser("send")
    p_send.add_argument("--to", required=True)
    p_send.add_argument("--subject", required=True)
    p_send.add_argument("--body-file", required=True)
    args = parser.parse_args()
    USERNAME = args.user

    if args.direct:
        password = read_password()
        if args.command == "check":
            result = cmd_check_direct(password)
        elif args.command == "list":
            result = cmd_list_direct(password, args.limit)
        elif args.command == "read":
            result = cmd_read_direct(password, args.uid)
        elif args.command == "send":
            result = cmd_send_direct(password, args.to, args.subject, args.body_file)
        else:
            raise SystemExit(f"unknown command {args.command}")
    else:
        if args.command == "check":
            result = bridge_call({"action": "check"})
        elif args.command == "list":
            result = bridge_call({"action": "list", "limit": args.limit})
        elif args.command == "read":
            result = bridge_call({"action": "read", "uid": args.uid})
        elif args.command == "send":
            with open(args.body_file, encoding="utf-8") as fh:
                body = fh.read()
            result = bridge_call({"action": "send", "to": args.to,
                                  "subject": args.subject, "body": body})
        else:
            raise SystemExit(f"unknown command {args.command}")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
