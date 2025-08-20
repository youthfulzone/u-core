<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ANAF Cookie Extractor</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-2xl w-full bg-white rounded-lg shadow-lg p-6">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">🍪 ANAF Cookie Extractor</h1>
                <p class="text-gray-600">Sistem automat de extragere cookie-uri pentru SPV</p>
            </div>

            <div id="step-1" class="space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h2 class="font-semibold text-blue-900 mb-2">📋 Pașii pentru sincronizare:</h2>
                    <ol class="list-decimal list-inside text-blue-800 space-y-1">
                        <li>Deschideți o fereastră nouă/tab nou</li>
                        <li>Navigați la <strong>https://webserviced.anaf.ro</strong></li>
                        <li>Autentificați-vă cu tokenul fiscal</li>
                        <li>După autentificare, reveniți la această pagină</li>
                        <li>Apăsați butonul "Extrage Cookie-uri"</li>
                    </ol>
                </div>

                <div class="text-center">
                    <button id="open-anaf" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-700 transition-colors">
                        🔐 Deschide ANAF pentru autentificare
                    </button>
                </div>
            </div>

            <div id="step-2" class="hidden space-y-4">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <h2 class="font-semibold text-green-900 mb-2">✅ Gata pentru extragere</h2>
                    <p class="text-green-800">Dacă v-ați autentificat la ANAF, puteți extrage cookie-urile:</p>
                </div>

                <div class="text-center">
                    <button id="extract-cookies" class="bg-green-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-green-700 transition-colors">
                        🍪 Extrage Cookie-uri ANAF
                    </button>
                </div>
            </div>

            <div id="step-3" class="hidden space-y-4">
                <div id="result-success" class="hidden bg-green-50 border border-green-200 rounded-lg p-4">
                    <h2 class="font-semibold text-green-900 mb-2">🎉 Succes!</h2>
                    <p class="text-green-800">Cookie-urile ANAF au fost trimise cu succes la aplicația SPV.</p>
                    <p class="text-sm text-green-600 mt-2">Puteți închide această fereastră și să folosiți funcția de sincronizare.</p>
                </div>

                <div id="result-error" class="hidden bg-red-50 border border-red-200 rounded-lg p-4">
                    <h2 class="font-semibold text-red-900 mb-2">❌ Eroare</h2>
                    <p id="error-message" class="text-red-800"></p>
                    <button id="retry" class="mt-2 bg-red-600 text-white px-4 py-2 rounded font-medium hover:bg-red-700 transition-colors">
                        Încearcă din nou
                    </button>
                </div>
            </div>

            <div class="mt-6 text-center">
                <button id="close-window" class="text-gray-500 hover:text-gray-700 text-sm">
                    Închide fereastra
                </button>
            </div>
        </div>
    </div>

    <script>
    // Cookie extraction logic
    const targetCookies = ['JSESSIONID', 'MRHSession', 'F5_ST', 'LastMRH_Session'];
    
    document.getElementById('open-anaf').addEventListener('click', function() {
        // Open ANAF in a new window
        window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank');
        
        // Show step 2
        document.getElementById('step-1').classList.add('hidden');
        document.getElementById('step-2').classList.remove('hidden');
    });

    document.getElementById('extract-cookies').addEventListener('click', async function() {
        try {
            // Get all cookies from the current domain
            const allCookies = document.cookie.split(';');
            const anafCookies = {};
            
            // Extract target cookies
            allCookies.forEach(cookie => {
                const [name, value] = cookie.trim().split('=');
                if (name && targetCookies.includes(name)) {
                    anafCookies[name] = value;
                }
            });

            console.log('Extracted cookies:', anafCookies);

            if (Object.keys(anafCookies).length === 0) {
                throw new Error('Nu am găsit cookie-uri ANAF pe această pagină. Asigurați-vă că v-ați autentificat la https://webserviced.anaf.ro');
            }

            // Send cookies to Laravel
            const response = await fetch('{{ route('anaf.extension.cookies') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify({
                    cookies: anafCookies,
                    source: 'manual_extractor',
                    timestamp: Math.floor(Date.now() / 1000),
                    browser_info: {
                        userAgent: navigator.userAgent,
                        url: window.location.href
                    }
                })
            });

            const result = await response.json();

            // Show results
            document.getElementById('step-2').classList.add('hidden');
            document.getElementById('step-3').classList.remove('hidden');

            if (result.success) {
                document.getElementById('result-success').classList.remove('hidden');
                setTimeout(() => {
                    window.close();
                }, 3000);
            } else {
                throw new Error(result.message || 'Eroare necunoscută');
            }

        } catch (error) {
            console.error('Cookie extraction failed:', error);
            document.getElementById('step-2').classList.add('hidden');
            document.getElementById('step-3').classList.remove('hidden');
            document.getElementById('result-error').classList.remove('hidden');
            document.getElementById('error-message').textContent = error.message;
        }
    });

    document.getElementById('retry').addEventListener('click', function() {
        document.getElementById('step-3').classList.add('hidden');
        document.getElementById('result-success').classList.add('hidden');
        document.getElementById('result-error').classList.add('hidden');
        document.getElementById('step-2').classList.remove('hidden');
    });

    document.getElementById('close-window').addEventListener('click', function() {
        window.close();
    });

    // Auto-detect if we're on ANAF domain
    if (window.location.hostname.includes('anaf.ro')) {
        // We're on ANAF domain - auto-extract
        setTimeout(() => {
            document.getElementById('extract-cookies').click();
        }, 1000);
    }
    </script>
</body>
</html>