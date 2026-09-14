"""Loopback-only HTTPS smoke tests for the real cURL adapter; no provider calls."""
import http.server
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading

root = Path(__file__).resolve().parent.parent
requests = []


class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        requests.append(self.path)
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        if self.path == "/redirect":
            self.send_response(302)
            self.send_header("Location", "/target")
        elif self.path == "/error":
            self.send_response(503)
        else:
            self.send_response(200)
        self.end_headers()
        self.wfile.write(b'{"status":"success","data":{}}')

    def log_message(self, *args):
        pass


with tempfile.TemporaryDirectory(prefix="athmovil-tls-") as directory:
    cert = Path(directory) / "certificate.pem"
    key = Path(directory) / "key.pem"
    subprocess.run([
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
        "-keyout", str(key), "-out", str(cert), "-subj", "/CN=127.0.0.1",
        "-addext", "subjectAltName=IP:127.0.0.1",
    ], check=True, capture_output=True)
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        env = dict(os.environ, ATHMOVIL_LOOPBACK_PORT=str(server.server_port))
        trusted = r'''
require 'vendor/autoload.php';
$url = 'https://127.0.0.1:' . getenv('ATHMOVIL_LOOPBACK_PORT');
$transport = new AthMovil\Http\CurlTransport();
$headers = ['Content-Type: application/json'];
foreach (['/ok' => 200, '/redirect' => 302, '/error' => 503] as $path => $expected) {
    $result = $transport->send('POST', $url . $path, $headers, '{}');
    if ($result->statusCode !== $expected) { throw new RuntimeException('Unexpected HTTP status.'); }
}
try { $transport->send('POST', str_replace('127.0.0.1', 'localhost', $url), $headers, '{}'); }
catch (AthMovil\Exception\TransportException) { echo 'hostname rejected'; exit(0); }
throw new RuntimeException('Mismatched TLS hostname was accepted.');
'''
        check = subprocess.run(["php", "-d", f"curl.cainfo={cert}", "-r", trusted], cwd=root, env=env, text=True, capture_output=True)
        if check.returncode != 0:
            raise RuntimeError(check.stdout + check.stderr)
        if "hostname rejected" not in check.stdout:
            raise RuntimeError("Missing hostname rejection evidence.")
        untrusted = r'''
require 'vendor/autoload.php';
$url = 'https://127.0.0.1:' . getenv('ATHMOVIL_LOOPBACK_PORT');
try { (new AthMovil\Http\CurlTransport())->send('POST', $url, [], '{}'); }
catch (AthMovil\Exception\TransportException) { echo 'certificate rejected'; exit(0); }
throw new RuntimeException('Untrusted certificate was accepted.');
'''
        check = subprocess.run(["php", "-r", untrusted], cwd=root, env=env, text=True, capture_output=True)
        if check.returncode != 0 or "certificate rejected" not in check.stdout:
            raise RuntimeError(check.stdout + check.stderr)
        if requests != ["/ok", "/redirect", "/error"]:
            raise RuntimeError("Redirect was followed, retry occurred, or invalid TLS reached HTTP: " + json.dumps(requests))
        print("PASS: trusted TLS, certificate/hostname rejection, HTTP statuses, no redirects or retries (loopback only)")
    finally:
        server.shutdown()
        server.server_close()
        thread.join()
