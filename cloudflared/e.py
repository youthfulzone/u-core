#!/usr/bin/env python3
# efactura_downloader.py – Cloudflare tunnel + ANAF helper (2025-07-14)

import argparse, base64, io, json, os, pathlib, queue, re, shutil, subprocess, \
       sys, textwrap, threading, time, urllib.request, zipfile, requests
from datetime import datetime, timezone                 # ★ added timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse, urlencode
import webbrowser, pprint
from dotenv import load_dotenv
import re, json 

# ───────────────────────── CONFIG ────────────────────────────────────────
ROOT, HOST, TUN_NAME, PORT, RATE = (
    "scyte.ro", "efactura.scyte.ro", "efactura", 8765, 2.0
)

HERE       = pathlib.Path(__file__).resolve().parent
CF_EXE     = HERE / "cloudflared.exe"
CERT       = HERE / "cert.pem"
CRED_JSON  = HERE / "efactura.json"      # static JSON creds
TOK_FILE   = HERE / "efactura.token"     # run-token
JWT_FILE   = HERE / "tokens.json"        # ANAF access / refresh JWTs

API_BASE   = "https://api.anaf.ro/prod/FCTEL/rest"
LISTA      = f"{API_BASE}/listaMesajeFactura"
DESCA      = f"{API_BASE}/descarcare"
TRANS      = f"{API_BASE}/transformare/{{std}}/{{novld}}"
TEST_HELLO = "https://api.anaf.ro/TestOauth/jaxrs/hello?name=hello"

AUTH_URL   = "https://logincert.anaf.ro/anaf-oauth2/v1/authorize"
TOKEN_URL  = "https://logincert.anaf.ro/anaf-oauth2/v1/token"

TIMEOUT, CHUNK = 30, 1 << 20
TIP2DIR = {"FACTURA PRIMITA": "Primite", "FACTURA TRIMISA": "Trimise"}

load_dotenv(HERE / ".env")
CID, CSEC = os.getenv("CLIENT_ID", "").strip(), os.getenv("CLIENT_SECRET", "").strip()

# ────────────────── small HTTP helper with global rate-limit ─────────────
_id_regex = re.compile(
    r"""\bid[A-Za-z0-9_]*        # key: id, id_solicitare, idDescarcare, …
        ["']?                    # optional quote right after the key (JSON style)
        \s*[:=]\s*               # colon or equal sign
        ["']?                    # opening quote for the value (optional)
        ([\w-]+)                 # ← capture the actual id (digits / uuid / hex)
    """,
    re.IGNORECASE | re.VERBOSE,
)

_last = 0.0

def _rate():
    global _last
    d = RATE - (time.monotonic() - _last)
    if d > 0:
        time.sleep(d)
    _last = time.monotonic()


def _req(m, u, **k):
    _rate()
    return requests.request(m, u, **k)

_get  = lambda u, **k: _req("GET",  u, **k)
_post = lambda u, **k: _req("POST", u, **k)
HDR   = lambda t: {"Authorization": f"Bearer {t}"}
_env  = lambda: {"TUNNEL_ORIGIN_CERT": str(CERT), **os.environ}

# ────────────────── misc helpers ─────────────────────────────────────────

def _tid_from_token(tok: str) -> str | None:
    """Extract TunnelID from cloudflared run-token in any format."""
    try:
        if tok.lstrip().startswith("{"):
            obj = json.loads(tok)
        else:
            b64 = tok.split(".")[1] if tok.count(".") == 2 else tok
            b64 += "=" * (-len(b64) % 4)
            obj = json.loads(base64.b64decode(b64))
        return obj.get("TunnelID") or obj.get("t")
    except Exception:
        return None


def _ensure_dict(x: object) -> dict:
    """
    ANAF sometimes returns strings instead of dict.
    • dict  -> unchanged
    • empty / whitespace -> {}
    • other str -> json.loads or {'_raw': str(x)}
    """
    if isinstance(x, dict):
        return x
    if x is None or not str(x).strip():
        return {}
    if isinstance(x, (bytes, bytearray)):
        x = x.decode(errors="ignore")
    try:
        return json.loads(x)           # succeeds for valid JSON text
    except Exception:
        return {"_raw": str(x)}        # keep raw for debugging

def _extract_id(msg: dict | str) -> str | None:
    """
    Extract a download-id from an ANAF e-Factura ‘message’ in four passes:

    1.   Direct dict keys that contain “id”   → msg["id"], msg["id_solicitare"], …
    2.   Look inside msg["detalii"] if it contains embedded JSON
    3.   Apply the broad regex to *all* raw text (XML, JSON, whatever)
    4.   Give up → None
    """
    # ── 1. immediate dict keys ───────────────────────────────────────────────
    if isinstance(msg, dict):
        for k, v in msg.items():
            if "id" in k.lower() and v not in (None, "", 0):
                return str(v)

        # ── 2. recurse once into “detalii” if it looks like JSON ────────────
        det = msg.get("detalii")
        if isinstance(det, str) and det.lstrip().startswith("{"):
            try:
                j = json.loads(det)
                if isinstance(j, dict):
                    return _extract_id(j)
            except Exception:
                pass

        raw = msg.get("_raw", "")        # maybe left by _ensure_dict
    else:
        raw = str(msg)                    # msg came in as a plain string

    # ── 3. last-chance regex sweep over whatever raw text we have ────────────
    m = _id_regex.search(raw)
    return m.group(1) if m else None

# ────────────────── bootstrap cloudflared & cert ─────────────────────────
if not CF_EXE.exists():
    print("• downloading cloudflared.exe …")
    urllib.request.urlretrieve(
        "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe",
        CF_EXE,
    )

if not CERT.exists():
    home_cert = pathlib.Path.home() / ".cloudflared" / "cert.pem"
    if home_cert.exists():
        shutil.copy2(home_cert, CERT)
    else:
        subprocess.check_call(
            [CF_EXE, "tunnel", "login", "--origincert", str(CERT)], env=_env()
        )

subprocess.run([CF_EXE, "tunnel", "create", TUN_NAME],
               env=_env(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

# ────────────────── pick connector creds ─────────────────────────────────
if CRED_JSON.exists():
    mode, cred = "json", CRED_JSON
elif TOK_FILE.exists():
    mode, cred = "token", TOK_FILE.read_text().strip()
else:
    print("• requesting new tunnel token")
    cred = subprocess.check_output([CF_EXE, "tunnel", "token", TUN_NAME],
                                   env=_env(), text=True).strip()
    TOK_FILE.write_text(cred)
    mode = "token"
    print("  saved → efactura.token")

# map hostname → tunnel (idempotent)
if mode == "token":
    tid = _tid_from_token(cred) or sys.exit("✖ Cannot parse TunnelID from token")
    subprocess.run([CF_EXE, "tunnel", "route", "dns", "--overwrite-dns",
                    tid, HOST], env=_env(),
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
else:
    subprocess.run([CF_EXE, "tunnel", "route", "dns", "--overwrite-dns",
                    TUN_NAME, HOST], env=_env(),
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

# ────────────────── connector launcher ───────────────────────────────────

def _run_connector(cmd):
    cmd = [str(c) for c in cmd]
    proc = subprocess.Popen(cmd, env=_env(),
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            text=True)
    for _ in range(20):            # 10 s grace
        if proc.poll() is not None:
            out, _ = proc.communicate()
            sys.exit(textwrap.dedent(f"""
                ✖ tunnel connector failed.

                {' '.join(cmd)}
                {out or '(no output)'}
            """))
        time.sleep(0.5)
    return proc

# ────────────────── tiny callback server ────────────────────────────────
_code_q: queue.Queue[str] = queue.Queue()

class CB(BaseHTTPRequestHandler):
    def log_message(self, *_): ...
    def do_GET(self):
        qs = parse_qs(urlparse(self.path).query)
        if "code" in qs:
            _code_q.put(qs["code"][0])
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"OAuth OK - you may close this tab.")  # bytes / ASCII
        else:
            self.send_response(400)
            self.end_headers()


def _serve():
    srv = ThreadingHTTPServer(("127.0.0.1", PORT), CB)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv

# ────────────────── JWT helpers ─────────────────────────────────────────

def _jwt_load():
    return json.loads(JWT_FILE.read_text()) if JWT_FILE.exists() else None


def _jwt_save(j):
    j["expires_at"] = int(time.time()) + int(j.get("expires_in", 3600)) - 60
    if _jwt_load() != j:
        JWT_FILE.write_text(json.dumps(j, indent=2))


def _jwt_refresh(rf):
    payload = {"grant_type": "refresh_token",
               "refresh_token": rf,
               "token_content_type": "jwt"}
    r = _post(TOKEN_URL, auth=(CID, CSEC),
              data=payload,
              headers={"Content-Type": "application/x-www-form-urlencoded"},
              timeout=TIMEOUT)
    r.raise_for_status()
    j = r.json()
    if "refresh_token" not in j:
        j["refresh_token"] = rf
    _jwt_save(j)
    return j

# ────────────────── interactive OAuth (with retry) ──────────────────────

def get_jwt(force_login=False, dbg=False):
    j = _jwt_load()
    if j and j.get("expires_at", 0) > time.time():
        return j
    if j and j.get("refresh_token"):
        return _jwt_refresh(j["refresh_token"])

    cmd = [CF_EXE, "tunnel", "run", "--url", f"http://127.0.0.1:{PORT}"]
    if mode == "json":
        cmd += [TUN_NAME, "--cred-file", CRED_JSON]
    else:
        cmd += ["--token", cred]
    print(f"• connector {mode.upper()} mode")

    proc = _run_connector(cmd)
    srv  = _serve()
    redirect = f"https://{HOST}/callback"

    for attempt in range(1, 4):
        params = dict(response_type="code", client_id=CID,
                      redirect_uri=redirect, token_content_type="jwt")
        if force_login:
            params["prompt"] = "login"
        webbrowser.open(f"{AUTH_URL}?{urlencode(params)}")
        print(f"· waiting for ANAF redirect … (attempt {attempt})")

        try:
            code = _code_q.get(timeout=90)
        except queue.Empty:
            code = input("No redirect detected. Paste code= value here: ").strip()

        payload = {"grant_type": "authorization_code",
                   "code": code,
                   "redirect_uri": redirect,
                   "token_content_type": "jwt"}
        r = _post(TOKEN_URL, auth=(CID, CSEC), data=payload,
                  headers={"Content-Type": "application/x-www-form-urlencoded"},
                  timeout=TIMEOUT)

        if r.status_code == 500:
            print("⚠️  ANAF returned 500 – trying again …")
            continue

        r.raise_for_status()
        srv.shutdown(); proc.terminate()
        j = r.json(); _jwt_save(j)
        if dbg:
            print("Decoded JWT:")
            pprint.pp(json.loads(base64.b64decode(j['access_token'].split('.')[1] + '==')))
        print("✔ tokens.json saved")
        return j

    srv.shutdown(); proc.terminate()
    sys.exit("✖ OAuth failed three times.")

# ────────────────── e-Factura API helpers ────────────────────────────────

def lista_mesaje(cui, days, tok, dbg=False):
    r = _get(LISTA, headers=HDR(tok["access_token"]),
             params={"cif": cui, "zile": days}, timeout=TIMEOUT)
    if dbg:
        print("DEBUG listaMesaje =", r.text)
    if r.status_code == 401:
        tok = _jwt_refresh(tok["refresh_token"])
        r   = _get(LISTA, headers=HDR(tok["access_token"]),
                   params={"cif": cui, "zile": days}, timeout=TIMEOUT)
    r.raise_for_status()

    data = r.json()
    msgs = data["mesaje"] if isinstance(data, dict) and "mesaje" in data else data
    return msgs, tok

# ★ PATCH #1 – do not abort on 400/404 for certain message IDs
# ────────────────── smarter download helper ─────────────────────────────
def descarca(mid: str | None, dst: pathlib.Path, tok: dict) -> dict:
    """Download one message (ZIP / PDF / XML) into *dst*.

    • Handles 401 (token refresh) and 400/404 errors gracefully
    • Detects payload type by magic bytes (ZIP, PDF, XML/TXT)
    • Skips if the target folder already contains any files
    """
    if not mid:
        print("      ! message without id – skipped")
        return tok

    dst.mkdir(parents=True, exist_ok=True)
    if any(dst.iterdir()):
        return tok                     # already downloaded

    def _dl():
        return _get(DESCA,
                    headers=HDR(tok["access_token"]),
                    params={"id": mid},
                    timeout=TIMEOUT)

    r = _dl()
    if r.status_code == 401:
        tok = _jwt_refresh(tok["refresh_token"])
        r   = _dl()

    if r.status_code in (400, 404):
        print(f"      ! id {mid} rejected by ANAF ({r.status_code}) – skipping")
        return tok

    r.raise_for_status()
    blob = r.content

    # 1) ZIP archive (normal case)
    if blob[:4] == b"PK\x03\x04":
        try:
            with zipfile.ZipFile(io.BytesIO(blob)) as z:
                z.extractall(dst)
        except zipfile.BadZipFile:
            (dst / f"{mid}.zip.broken").write_bytes(blob)

    # 2) direct PDF (rare buyer-reply messages)
    elif blob[:5] == b"%PDF-":
        (dst / f"{mid}.pdf").write_bytes(blob)

    # 3) plain XML or fallback text
    else:
        name = f"{mid}.xml" if blob.lstrip().startswith(b"<") else f"{mid}.txt"
        (dst / name).write_bytes(blob)

    return tok

def to_pdf(xml, tok):
    r = _post(TRANS.format(std="FACT1", novld="DA"),
              headers={**HDR(tok["access_token"]), "Content-Type": "text/plain"},
              data=xml.read_bytes(), timeout=TIMEOUT)
    if r.status_code == 401:
        tok = _jwt_refresh(tok["refresh_token"])
        r   = _post(TRANS.format(std="FACT1", novld="DA"),
                    headers={**HDR(tok["access_token"]), "Content-Type": "text/plain"},
                    data=xml.read_bytes(), timeout=TIMEOUT)
    r.raise_for_status()
    pdf = xml.with_suffix(".pdf")
    pdf.write_bytes(r.content)
    return pdf, tok

# ────────────────── CLI main ─────────────────────────────────────────────

def main():
    pa = argparse.ArgumentParser("Download RO e-Factura")
    pa.add_argument("--cui", required=True, nargs="+")
    pa.add_argument("--days", type=int, default=60)
    pa.add_argument("--dest", default="./efactura")
    pa.add_argument("--pdf",  action="store_true")
    a = pa.parse_args()

    if not (CID and CSEC):
        sys.exit("Add CLIENT_ID and CLIENT_SECRET to .env")

    root = pathlib.Path(a.dest).expanduser()
    root.mkdir(parents=True, exist_ok=True)

    tok = get_jwt()

    for cui in a.cui:
        print(f"\n### {cui} – last {a.days} days")
        raw_msgs, tok = lista_mesaje(cui, a.days, tok)
        msgs = [_ensure_dict(m) for m in raw_msgs if _ensure_dict(m)]
        print(f"   {len(msgs)} message(s)")

        for m in msgs:
            mid  = _extract_id(m)
            slot = TIP2DIR.get(m.get("tip"), "Erori")

            day  = (m.get("data_creare") or "")[:10] \
                or datetime.now(timezone.utc).date().isoformat()

            # ── NEW: year / month tree ─────────────────────────────────
            year  = day[:4]
            month = day[5:7] if len(day) >= 7 else "NA"        # 01 … 12

            folder = root / year / cui / slot / month / f"{day}_{mid or 'NA'}"
            # ───────────────────────────────────────────────────────────

            tok = descarca(mid, folder, tok)

            if a.pdf:
                for xml in folder.glob("*.xml"):
                    try:
                        pdf, tok = to_pdf(xml, tok)
                        print(f"      ↳ {slot:<7} {pdf.name}")
                    except Exception as exc:
                        print(f"      ! PDF {xml.name}: {exc}")

if __name__ == "__main__":
    main()
