#!/usr/bin/env python3
# Cloudflared tunnel management for e-Factura
import json, os, pathlib, subprocess, sys, time, shutil, urllib.request
from dotenv import load_dotenv

# Configuration
ROOT, HOST, TUN_NAME, PORT = (
    "scyte.ro", "efactura.scyte.ro", "efactura", 80
)

HERE = pathlib.Path(__file__).resolve().parent
CF_EXE = HERE / "cloudflared.exe"
CERT = HERE / "cert.pem"
CRED_JSON = HERE / "efactura.json"
TOK_FILE = HERE / "efactura.token"

load_dotenv(HERE / ".env")

def _env():
    return {"TUNNEL_ORIGIN_CERT": str(CERT), **os.environ}

def _tid_from_token(tok: str) -> str | None:
    """Extract TunnelID from cloudflared run-token."""
    try:
        import base64
        if tok.lstrip().startswith("{"):
            obj = json.loads(tok)
        else:
            b64 = tok.split(".")[1] if tok.count(".") == 2 else tok
            b64 += "=" * (-len(b64) % 4)
            obj = json.loads(base64.b64decode(b64))
        return obj.get("TunnelID") or obj.get("t")
    except Exception:
        return None

def bootstrap():
    """Bootstrap cloudflared executable and certificate."""
    # Download cloudflared if not exists
    if not CF_EXE.exists():
        print("Downloading cloudflared.exe...")
        urllib.request.urlretrieve(
            "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe",
            CF_EXE,
        )

    # Copy or create certificate
    if not CERT.exists():
        home_cert = pathlib.Path.home() / ".cloudflared" / "cert.pem"
        if home_cert.exists():
            shutil.copy2(home_cert, CERT)
        else:
            subprocess.check_call(
                [CF_EXE, "tunnel", "login", "--origincert", str(CERT)], env=_env()
            )

    # Create tunnel (idempotent)
    subprocess.run([CF_EXE, "tunnel", "create", TUN_NAME],
                   env=_env(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def get_credentials():
    """Get tunnel credentials (JSON or token)."""
    if CRED_JSON.exists():
        return "json", CRED_JSON
    elif TOK_FILE.exists():
        return "token", TOK_FILE.read_text().strip()
    else:
        print("Requesting new tunnel token...")
        cred = subprocess.check_output([CF_EXE, "tunnel", "token", TUN_NAME],
                                       env=_env(), text=True).strip()
        TOK_FILE.write_text(cred)
        print("Token saved to efactura.token")
        return "token", cred

def setup_dns(mode, cred):
    """Setup DNS routing for the tunnel."""
    if mode == "token":
        tid = _tid_from_token(cred) or sys.exit("Cannot parse TunnelID from token")
        subprocess.run([CF_EXE, "tunnel", "route", "dns", "--overwrite-dns",
                        tid, HOST], env=_env(),
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    else:
        subprocess.run([CF_EXE, "tunnel", "route", "dns", "--overwrite-dns",
                        TUN_NAME, HOST], env=_env(),
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def start_tunnel():
    """Start the cloudflared tunnel."""
    try:
        bootstrap()
        mode, cred = get_credentials()
        setup_dns(mode, cred)
        
        # Build command - use localhost with Host header for Herd compatibility
        cmd = [str(CF_EXE), "tunnel", "run", "--url", "http://127.0.0.1:80", 
               "--http-host-header", "u-core.test"]
        if mode == "json":
            cmd += [TUN_NAME, "--cred-file", str(CRED_JSON)]
        else:
            cmd += ["--token", cred]
        
        print(f"Starting tunnel in {mode.upper()} mode...")
        print(f"Command: {' '.join(cmd)}")
        
        # Start the process
        proc = subprocess.Popen(cmd, env=_env(),
                                stdout=subprocess.PIPE, 
                                stderr=subprocess.STDOUT,
                                text=True)
        
        # Wait a bit to check if it starts successfully
        for i in range(10):
            if proc.poll() is not None:
                out, _ = proc.communicate()
                print(f"Tunnel failed to start: {out}")
                return False
            time.sleep(0.5)
        
        print("Tunnel started successfully")
        return True
        
    except Exception as e:
        print(f"Error starting tunnel: {e}")
        return False

def check_tunnel():
    """Check if tunnel process is running."""
    try:
        # Check if cloudflared process is running
        result = subprocess.run(['tasklist', '/FI', 'IMAGENAME eq cloudflared.exe', '/FO', 'CSV'],
                                capture_output=True, text=True)
        
        lines = result.stdout.strip().split('\n')
        if len(lines) > 1:  # Header + at least one process
            return True
        return False
    except Exception:
        return False

def stop_tunnel():
    """Stop all cloudflared processes."""
    try:
        subprocess.run(['taskkill', '/F', '/IM', 'cloudflared.exe'], 
                       capture_output=True)
        print("Tunnel stopped")
        return True
    except Exception as e:
        print(f"Error stopping tunnel: {e}")
        return False

def main():
    if len(sys.argv) < 2:
        print("Usage: python tunnel.py [start|stop|status]")
        return
    
    command = sys.argv[1]
    
    if command == "start":
        success = start_tunnel()
        sys.exit(0 if success else 1)
    elif command == "stop":
        success = stop_tunnel()
        sys.exit(0 if success else 1)
    elif command == "status":
        running = check_tunnel()
        print("running" if running else "stopped")
        sys.exit(0 if running else 1)
    else:
        print("Invalid command. Use: start, stop, or status")
        sys.exit(1)

if __name__ == "__main__":
    main()