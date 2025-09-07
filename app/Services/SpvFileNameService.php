<?php

namespace App\Services;

class SpvFileNameService
{
    /**
     * Extract meaningful filename from SPV message details
     */
    public static function extractFileName(string $detalii, string $cif = ''): string
    {
        // Clean the input
        $detalii = trim($detalii);
        
        // Define patterns for different document types
        $patterns = [
            // Document Fisa Rol pentru CIF=39491167 (cod arondare 024001) in format PDF -> Fisa Rol
            '/Document\s+([^p]+?)\s+pentru\s+CIF/i' => '$1',
            
            // recipisa pentru CIF 44855221, tip D301, numar_inregistrare -> D301
            '/recipisa\s+pentru\s+CIF\s+\d+,\s+tip\s+([^,\s]+)/i' => '$1',
            
            // AMEF - CONECTAT LA SISTEMUL INFORMATIC NATIONAL DAR NU -> AMEF
            '/^([A-Z]+)\s*-\s*.+/i' => '$1',
            
            // Extract document type patterns like "tip D390", "tip D301"
            '/tip\s+([A-Z]+\d+)/i' => '$1',
            
            // Extract specific document names
            '/pentru\s+([^,\s]+)\s+CIF/i' => '$1',
            
            // Generic patterns for common document types
            '/\b(factura|bon|chitanta|contract|nota|aviz|recipisa|fisa)\b/i' => '$1',
            
            // Extract codes like "D301", "D390", etc.
            '/\b([A-Z]\d{3})\b/' => '$1',
            
            // Extract capitalized words that might be document names
            '/\b([A-Z]{2,})\b/' => '$1',
        ];
        
        // Try each pattern
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $detalii, $matches)) {
                $fileName = trim($matches[1] ?? $replacement);
                
                // Clean up the extracted name
                $fileName = self::cleanFileName($fileName);
                
                if (!empty($fileName) && strlen($fileName) > 1) {
                    return $fileName;
                }
            }
        }
        
        // Fallback: Try to extract first meaningful word
        if (preg_match('/\b([a-zA-Z]{3,})\b/', $detalii, $matches)) {
            return self::cleanFileName($matches[1]);
        }
        
        // Final fallback: use document type or generic name
        return 'Document';
    }

    /**
     * Extract period information from SPV message details
     */
    public static function extractPeriod(string $detalii): ?string
    {
        // Pattern for "perioada raportare 6.2025", "perioada 7.2025", etc.
        if (preg_match('/perioada\s+(?:raportare\s+)?(\d{1,2}\.20\d{2})/i', $detalii, $matches)) {
            return $matches[1];
        }
        
        // Pattern for standalone periods like "6.2025", "12.2024"
        if (preg_match('/\b(\d{1,2}\.20\d{2})\b/', $detalii, $matches)) {
            return $matches[1];
        }
        
        // Pattern for "luna 6 an 2025" or similar
        if (preg_match('/luna\s+(\d{1,2})\s+(?:an\s+)?(\d{4})/i', $detalii, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        
        // Pattern for "06/2025", "6/2025" format
        if (preg_match('/\b(\d{1,2})\/(\d{4})\b/', $detalii, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        
        return null;
    }
    
    /**
     * Clean and normalize the extracted filename
     */
    private static function cleanFileName(string $fileName): string
    {
        // Remove unwanted characters and normalize
        $fileName = trim($fileName);
        $fileName = preg_replace('/[^\w\s-]/', '', $fileName);
        $fileName = preg_replace('/\s+/', '_', $fileName);
        $fileName = trim($fileName, '_-');
        
        // Capitalize properly
        return ucfirst(strtolower($fileName));
    }
    
    /**
     * Generate full filename with extension and CIF
     */
    public static function generateFullFileName(string $detalii, string $cif, string $extension = 'pdf'): string
    {
        $baseName = self::extractFileName($detalii, $cif);
        $cleanCif = preg_replace('/[^0-9]/', '', $cif);
        $period = self::extractPeriod($detalii);
        
        if ($period) {
            return "{$baseName}_{$cleanCif}_{$period}.{$extension}";
        }
        
        return "{$baseName}_{$cleanCif}.{$extension}";
    }
    
    /**
     * Test the filename extraction with multiple examples
     */
    public static function testExtraction(): array
    {
        $testCases = [
            'Document Fisa Rol pentru CIF=39491167 (cod arondare 024001) in format PDF',
            'recipisa pentru CIF 44855221, tip D301, numar_inregistrare INTERNT-960039573-',
            'recipisa pentru CIF 44855221, tip D390, numar_inregistrare INTERNT-960038959-',
            'AMEF - CONECTAT LA SISTEMUL INFORMATIC NATIONAL DAR NU',
            'Document pentru factura CIF 12345678',
            'NOTA DE DEBIT pentru perioada 6.2025',
            'Situatie TVA tip D112 pentru CIF 99887766 perioada raportare 12.2024',
            'recipisa D301 pentru luna 7 an 2025',
            'Document Fisa Rol perioada 03/2025',
        ];
        
        $results = [];
        foreach ($testCases as $detalii) {
            $results[] = [
                'input' => $detalii,
                'extracted' => self::extractFileName($detalii),
                'period' => self::extractPeriod($detalii),
                'full_filename' => self::generateFullFileName($detalii, '12345678'),
            ];
        }
        
        return $results;
    }
}