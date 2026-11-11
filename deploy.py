#!/usr/bin/env python3
"""Deploy A3 Motor Service Station to Cloudflare Pages + D1.

Run with the venv python (has blake3):
  ~/workspace/a3motor-cf/.venv/bin/python ~/workspace/a3motor-cf/deploy.py

Idempotent: reuses the existing D1 database and Pages project when present.
Account calls use the stored custom.cloudflare credential via surrogate.
Asset calls use the short-lived Pages upload JWT (never printed).
"""
import base64, json, mimetypes, os, sys, time, urllib.request, urllib.error, uuid

sys.path.insert(0, "/opt/muse/skills/custom-connector-setup/bin")
from dynamic_credentials import add_surrogate_to_request, read_json_response
from blake3 import blake3

BASE = "https://api.cloudflare.com/client/v4"
ACCOUNT = "71aa57b2f43b02d8637ae4c6e9d1c00c"
PROJECT = "a3motor"
DB_NAME = "a3motor-db"
ROOT = os.path.dirname(os.path.abspath(__file__))
DIST = os.path.join(ROOT, "dist")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        raise RuntimeError("redirect refused")

OPENER = urllib.request.build_opener(NoRedirect())

def do(req, timeout=60):
    try:
        with OPENER.open(req, timeout=timeout) as resp:
            return resp.status, read_json_response(resp)
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8", "replace"))
        except Exception:
            return e.code, {"success": False, "errors": [{"message": f"HTTP {e.code}"}]}

def api(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    headers = {"Content-Type": "application/json"} if body is not None else {}
    req = urllib.request.Request(BASE + path, method=method, data=data, headers=headers)
    add_surrogate_to_request(req, "custom.cloudflare", allowed_hosts=["api.cloudflare.com"])
    return do(req)

def api_jwt(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    headers = {"Authorization": "Bearer " + JWT}
    if body is not None:
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, method=method, data=data, headers=headers)
    return do(req)

def die(step, status, body):
    print(f"FAILED at {step}: HTTP {status} {json.dumps(body.get('errors', body), ensure_ascii=False)[:500]}")
    sys.exit(1)

# 1. D1 database -------------------------------------------------------------
status, body = api("GET", f"/accounts/{ACCOUNT}/d1/database")
if status != 200: die("list D1", status, body)
db = next((d for d in body["result"] if d["name"] == DB_NAME), None)
if db is None:
    status, body = api("POST", f"/accounts/{ACCOUNT}/d1/database", {"name": DB_NAME})
    if status not in (200, 201): die("create D1", status, body)
    db = body["result"]
DB_ID = db["uuid"]
print(f"D1 database: {DB_NAME} ({DB_ID})")

# 2. Schema ------------------------------------------------------------------
with open(os.path.join(ROOT, "schema.sql"), encoding="utf-8") as f:
    statements = [s.strip() for s in f.read().split(";") if s.strip()]
for stmt in statements:
    status, body = api("POST", f"/accounts/{ACCOUNT}/d1/database/{DB_ID}/query", {"sql": stmt})
    if status != 200 or not body.get("success"): die("schema", status, body)
print(f"Schema applied: {len(statements)} statements")

# 3. Pages project -------------------------------------------------------------
status, body = api("GET", f"/accounts/{ACCOUNT}/pages/projects/{PROJECT}")
if status == 404:
    status, body = api("POST", f"/accounts/{ACCOUNT}/pages/projects",
                       {"name": PROJECT, "production_branch": "main"})
    if status not in (200, 201): die("create Pages project", status, body)
elif status != 200:
    die("get Pages project", status, body)
print(f"Pages project: {PROJECT} (subdomain {body['result'].get('subdomain')})")

# 4. Bind D1 into production deployment config --------------------------------
status, body = api("PATCH", f"/accounts/{ACCOUNT}/pages/projects/{PROJECT}", {
    "deployment_configs": {
        "production": {
            "d1_databases": {"DB": {"id": DB_ID}},
            "compatibility_date": "2025-01-01",
        }
    }
})
if status != 200: die("bind D1", status, body)
print("D1 binding set on production config")

# 5. Upload assets ---------------------------------------------------------------
status, body = api("GET", f"/accounts/{ACCOUNT}/pages/projects/{PROJECT}/upload-token")
if status != 200: die("upload-token", status, body)
JWT = body["result"]["jwt"]

files = []
for name in sorted(os.listdir(DIST)):
    if name == "_worker.js": continue  # advanced mode: sent as _worker.bundle, not a static asset
    path = os.path.join(DIST, name)
    if not os.path.isfile(path): continue
    raw = open(path, "rb").read()
    ext = name.rsplit(".", 1)[-1] if "." in name else ""
    digest = blake3(base64.b64encode(raw) + ext.encode()).hexdigest()[:32]
    ctype = mimetypes.guess_type(name)[0] or "application/octet-stream"
    if name.endswith(".js"): ctype = "application/javascript"
    files.append({"path": "/" + name, "hash": digest, "raw": raw, "ctype": ctype})
manifest = {f["path"]: f["hash"] for f in files}

status, body = api_jwt("POST", "/pages/assets/check-missing", {"hashes": list(manifest.values())})
if status != 200: die("check-missing", status, body)
missing = set(body["result"])
to_upload = [f for f in files if f["hash"] in missing]
if to_upload:
    payload = [{"key": f["hash"], "value": base64.b64encode(f["raw"]).decode(),
                "metadata": {"contentType": f["ctype"]}, "base64": True} for f in to_upload]
    status, body = api_jwt("POST", "/pages/assets/upload", payload)
    if status != 200: die("upload assets", status, body)
    print(f"Uploaded {len(to_upload)} asset(s)")
else:
    print("All assets already present")
status, body = api_jwt("POST", "/pages/assets/upsert-hashes", {"hashes": list(manifest.values())})
if status != 200: die("upsert-hashes", status, body)

# 6. Create deployment (multipart) ----------------------------------------------
boundary = "----a3motor" + uuid.uuid4().hex
parts = []
def field(name, value, filename=None, ctype="text/plain"):
    disp = f'Content-Disposition: form-data; name="{name}"'
    if filename: disp += f'; filename="{filename}"'
    parts.append(f"--{boundary}\r\n{disp}\r\nContent-Type: {ctype}\r\n\r\n".encode() +
                 (value if isinstance(value, bytes) else value.encode()) + b"\r\n")
field("manifest", json.dumps(manifest))
field("branch", "main")
# Advanced mode worker: the "_worker.bundle" part holds a nested multipart
# document (metadata + the module file), mirroring wrangler's upload format.
worker_raw = open(os.path.join(DIST, "_worker.js"), "rb").read()
ib = "----a3motorbundle" + uuid.uuid4().hex
inner = (
    f"--{ib}\r\n".encode()
    + b'Content-Disposition: form-data; name="metadata"\r\n\r\n'
    + json.dumps({"main_module": "_worker.js"}).encode() + b"\r\n"
    + f"--{ib}\r\n".encode()
    + b'Content-Disposition: form-data; name="_worker.js"; filename="_worker.js"\r\n'
    + b"Content-Type: application/javascript+module\r\n\r\n"
    + worker_raw + b"\r\n"
    + f"--{ib}--\r\n".encode()
)
field("_worker.bundle", inner, "_worker.bundle", "application/octet-stream")
body_bytes = b"".join(parts) + f"--{boundary}--\r\n".encode()
req = urllib.request.Request(BASE + f"/accounts/{ACCOUNT}/pages/projects/{PROJECT}/deployments",
                             method="POST", data=body_bytes,
                             headers={"Content-Type": f"multipart/form-data; boundary={boundary}"})
add_surrogate_to_request(req, "custom.cloudflare", allowed_hosts=["api.cloudflare.com"])
status, body = do(req)
if status not in (200, 201): die("create deployment", status, body)
deployment = body["result"]
dep_id = deployment["id"]
print(f"Deployment created: {dep_id} url={deployment.get('url')}")

# 7. Poll until deploy stage finishes ---------------------------------------------
for _ in range(40):
    time.sleep(3)
    status, body = api("GET", f"/accounts/{ACCOUNT}/pages/projects/{PROJECT}/deployments/{dep_id}")
    if status != 200: die("poll deployment", status, body)
    stage = (body["result"].get("latest_stage") or {})
    if stage.get("name") == "deploy":
        print(f"Deploy stage: {stage.get('status')}")
        if stage.get("status") == "success": break
        if stage.get("status") in ("failure", "canceled"): die("deploy stage", status, body)
else:
    print("Polling timed out; check the deployment in the dashboard")
print(f"DONE: https://{PROJECT}.pages.dev")
