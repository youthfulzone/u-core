<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Failed</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .error-container {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            text-align: center;
        }
        .error-icon {
            font-size: 3rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }
        h1 {
            color: #1f2937;
            margin-bottom: 1rem;
        }
        .error-message {
            color: #6b7280;
            margin-bottom: 2rem;
            padding: 1rem;
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 4px;
        }
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }
        .btn-primary {
            background-color: #3b82f6;
            color: white;
        }
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">📄</div>
        <h1>Download Failed</h1>
        
        <div class="error-message">
            {{ $message }}
        </div>
        
        <div class="actions">
            <a href="{{ route('spv.index') }}" class="btn btn-primary">
                Back to SPV
            </a>
            <a href="https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60" target="_blank" class="btn btn-secondary">
                Re-authenticate at ANAF
            </a>
        </div>
        
        <script>
            // Auto-close window after 5 seconds if opened in new tab
            if (window.opener) {
                setTimeout(() => {
                    window.close();
                }, 5000);
            }
        </script>
    </div>
</body>
</html>