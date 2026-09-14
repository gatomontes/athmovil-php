"""Exercise the checkout through independent HTTP requests on the local PHP server."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parent.parent


def assert_true(value, message):
    if not value:
        raise AssertionError(message)


def csrf(page):
    match = re.search(r'name="csrf" value="([a-f0-9]+)"', page)
    assert_true(match, "Missing CSRF token")
    return match.group(1)


def payment_id(page):
    match = re.search(r'id="payment-id"[^>]*>([^<]+)</code>', page)
    assert_true(match, "Missing payment ID")
    return match.group(1)


def state(page, status):
    assert_true(re.search(r'id="payment-status"[^>]*>' + status + r'</span>', page), "Expected status " + status)


with tempfile.TemporaryDirectory(prefix="athmovil-checkout-") as directory:
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    env = dict(os.environ, ATHMOVIL_DEMO_STORAGE=directory)
    with tempfile.TemporaryFile() as server_log:
        process = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "examples/checkout/public"], cwd=root, env=env, stdout=server_log, stderr=server_log)
        try:
            browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            other = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def get(path="/", agent=browser):
                return agent.open(base + path, timeout=10).read().decode()

            def post(page, action, fields=None, agent=browser):
                payload = {"csrf": csrf(page), "action": action, **(fields or {})}
                return agent.open(base + "/", data=urllib.parse.urlencode(payload).encode(), timeout=10).read().decode()

            def expect_error(code, request, agent=browser):
                try:
                    agent.open(request, timeout=10)
                except urllib.error.HTTPError as error:
                    assert_true(error.code == code, f"Expected HTTP {code}, received {error.code}")
                    return
                raise AssertionError(f"Expected HTTP {code}")

            for _ in range(100):
                try:
                    page = get()
                    break
                except urllib.error.URLError:
                    if process.poll() is not None:
                        raise RuntimeError("PHP server exited before startup")
                    time.sleep(0.05)
            else:
                raise RuntimeError("PHP server did not start")

            assert_true("From cart to receipt." in page and "LOCAL SIMULATION" in page, "Missing checkout UI")
            assert_true(".layout" in get("/style.css"), "Missing stylesheet")
            expect_error(403, urllib.request.Request(base + "/", headers={"Host": "untrusted.example"}))
            expect_error(403, urllib.request.Request(base + "/", data=b"action=reset&csrf=wrong"))
            print("PASS: checkout starts locally, serves CSS, rejects foreign hosts and invalid CSRF")

            metadata = '<script>alert("order")</script>'
            fields = {"phone": "9395550199", "metadata1": metadata, "metadata2": "STORE-TEST", "total": "25.00", "subtotal": "25.00", "tax": "0.00", "timeout": "600"}
            for i, row in enumerate([
                {"name": "Coffee", "description": "Local coffee", "quantity": "2", "price": "8.00", "tax": "", "metadata": "SKU-COFFEE"},
                {"name": "Mug", "description": "Ceramic mug", "quantity": "1", "price": "9.00", "tax": "0.00", "metadata": "SKU-MUG"},
            ]):
                for key, value in row.items():
                    fields[f"items[{i}][{key}]"] = value
            page = post(page, "create", fields)
            ticket = payment_id(page)
            state(page, "OPEN")
            assert_true('<script>' not in page and '&lt;script&gt;' in page, "Unescaped metadata in HTML")
            state(get(), "OPEN")
            expect_error(404, base + "/?id=" + ticket, other)
            other_page = get(agent=other)
            other_page = post(other_page, "confirm", {"id": ticket}, other)
            assert_true("does not belong" in other_page, "Foreign session could control a payment")
            page = post(page, "phone", {"id": ticket, "phone": "7875550102"})
            state(page, "OPEN")
            print("PASS: separate requests preserve cart and metadata; HTML is escaped; sessions are isolated")

            page = post(page, "confirm", {"id": ticket})
            state(page, "CONFIRM")
            page = post(page, "authorize", {"id": ticket, "scenario": "timeout_after"})
            state(page, "COMPLETED")
            assert_true("Simulated timeout" in page, "Lost authorization response was hidden")
            assert_true("Receipt matched to the stored demo order." in page, "Missing order reconciliation")
            assert_true("Test Payer" in page, "Missing fictional customer")
            assert_true("SKU-COFFEE" in page and "SKU-MUG" in page, "Submitted item metadata lost")
            print("PASS: confirmation and lost authorization response reconcile to a completed receipt")

            old_csrf = csrf(page)
            page = post(page, "refund", {"id": ticket, "amount": "5.00", "message": "Partial", "scenario": "success"})
            assert_true('id="refunded-amount">$5.00' in page, "Partial refund missing")
            assert_true("test.payer@example.com" in page and "(787) 555-0100" in page, "Fictional refund identity missing")
            replay = urllib.request.Request(base + "/", data=urllib.parse.urlencode({"csrf": old_csrf, "action": "refund", "id": ticket, "amount": "5.00"}).encode())
            expect_error(403, replay)
            page = post(page, "refund", {"id": ticket, "amount": "30.00", "message": "Too much", "scenario": "success"})
            assert_true("Simulator rejected" in page and 'id="refunded-amount">$5.00' in page, "Excess refund changed the balance")
            page = post(page, "refund", {"id": ticket, "amount": "20.00", "message": "Remaining", "scenario": "timeout_after"})
            assert_true("Simulated timeout" in page and 'id="refunded-amount">$25.00' in page, "Lost refund response not reconciled")
            assert_true("$20.00 refunded" in page, "Lost-response refund receipt missing")
            print("PASS: partial refunds, replay rejection, balance limits, and lost refund responses")

            capture = json.loads(get("/?export=1"))
            assert_true(capture["simulation"], "Export lacks simulation marker")
            exported = json.dumps(capture)
            assert_true("sim-auth-" not in exported and "sim-private-" not in exported and "sim-public-" not in exported, "Credentials leaked in export")
            creates = [x for x in capture["history"] if x.get("operation") == "create"]
            assert_true(creates[0]["request"]["body"]["metadata1"] == metadata, "Metadata was mutated")
            assert_true(creates[0]["request"]["body"]["items"][0]["metadata"] == "SKU-COFFEE", "Item metadata missing in capture")
            assert_true(json.loads(get("/?export=1", other))["history"] == [], "Foreign session can see captures")
            print("PASS: captures preserve actual submitted data and redact credentials")

            # Restart PHP while retaining its data directory and browser cookie.
            process.terminate()
            process.wait(timeout=10)
            process = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "examples/checkout/public"], cwd=root, env=env, stdout=server_log, stderr=server_log)
            for _ in range(100):
                try:
                    page = get()
                    break
                except urllib.error.URLError:
                    time.sleep(0.05)
            else:
                raise RuntimeError("PHP server did not restart")
            state(page, "COMPLETED")
            assert_true('id="refunded-amount">$25.00' in page, "Refund state lost across restart")
            print("PASS: payments, session, and refunds survive PHP server restart")

            page = post(page, "create", fields)
            cancel_id = payment_id(page)
            page = post(page, "cancel", {"id": cancel_id})
            state(page, "CANCEL")
            page = post(page, "create", fields)
            expire_id = payment_id(page)
            page = post(page, "expire", {"id": expire_id})
            state(page, "CANCEL")
            invalid = dict(fields, total="not-money")
            page = post(page, "create", invalid)
            assert_true("unsigned decimal" in page, "Invalid input not handled")
            page = post(page, "reset")
            assert_true("Your first payment starts here." in page, "Reset did not clear orders")
            assert_true(json.loads(get("/?export=1"))["history"] == [], "Reset did not clear captures")
            print("PASS: merchant cancellation, explicit expiry, validation errors, and session reset")
            print("Checkout HTTP flow passed; no provider requests were made.")
        finally:
            process.terminate()
            process.wait(timeout=10)
            server_log.seek(0)
            logs = server_log.read().decode(errors="replace")
            if re.search(r"PHP (Fatal error|Warning|Parse error|Deprecated)", logs):
                raise RuntimeError(logs)
