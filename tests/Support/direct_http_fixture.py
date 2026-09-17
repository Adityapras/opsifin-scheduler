"""Loopback-only concurrent HTTP fixture; never contacts client endpoints."""
import json
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

state = {"active": 0, "max_active": 0, "requests": [], "bytes": 0}
lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        if self.path == "/metrics":
            with lock:
                body = json.dumps(state).encode()
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        self.do_POST()

    def do_POST(self):
        query = parse_qs(urlparse(self.path).query)
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        with lock:
            state["active"] += 1
            state["max_active"] = max(state["max_active"], state["active"])
            state["requests"].append({"path": self.path, "started": time.time()})
        try:
            time.sleep(float(query.get("delay", ["0.01"])[0]))
            if "disconnect" in query:
                self.connection.shutdown(socket.SHUT_RDWR)
                self.connection.close()
                return
            status = int(query.get("status", ["200"])[0])
            body = query.get("body", ['{"message":"done"}'])[0].encode()
            body += b"x" * int(query.get("bytes", ["0"])[0])
            self.send_response(status)
            if status == 302:
                self.send_header("Location", "/unexpected-redirect")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            with lock:
                state["bytes"] += len(body)
        except (BrokenPipeError, ConnectionResetError):
            pass
        finally:
            with lock:
                state["active"] -= 1


class Server(ThreadingHTTPServer):
    request_queue_size = 512
    daemon_threads = True


server = Server(("127.0.0.1", 0), Handler)
print(server.server_port, flush=True)
server.serve_forever()
