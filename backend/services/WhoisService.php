<?php

class WhoisService {
    private array $whoisServers = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'info' => 'whois.afilias.net',
        'biz' => 'whois.biz',
        'io' => 'whois.nic.io',
        'co' => 'whois.nic.co',
        'me' => 'whois.nic.me',
        'pl' => 'whois.dns.pl',
        'de' => 'whois.denic.de',
        'uk' => 'whois.nic.uk',
        'eu' => 'whois.eu',
        'fr' => 'whois.nic.fr',
        'nl' => 'whois.domain-registry.nl',
        'ru' => 'whois.tcinet.ru',
        'xyz' => 'whois.nic.xyz',
        'online' => 'whois.nic.online',
        'site' => 'whois.nic.site',
        'app' => 'whois.nic.google',
        'dev' => 'whois.nic.google',
    ];
    
    public function lookup(string $domain): array {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domain);
        $domain = rtrim($domain, '/');
        
        $result = [
            'domain' => $domain,
            'is_registered' => false,
            'expiry_date' => null,
            'registrar' => null,
            'raw' => '',
            'error' => null
        ];
        
        // Try multiple methods
        $whoisData = null;
        
        // Method 1: Try RDAP API (modern, HTTP-based)
        $whoisData = $this->queryRdap($domain);
        if ($whoisData) {
            return $this->parseRdapResponse($domain, $whoisData);
        }
        
        // Method 2: Try socket connection
        try {
            $parts = explode('.', $domain);
            $tld = end($parts);
            $server = $this->whoisServers[$tld] ?? "whois.nic.$tld";
            $whoisData = $this->queryWhoisSocket($server, $domain);
        } catch (Exception $e) {
            // Socket failed, try next method
        }
        
        // Method 3: Try shell whois command
        if (empty($whoisData)) {
            $whoisData = $this->queryWhoisCommand($domain);
        }
        
        // Method 4: Try external API as last resort
        if (empty($whoisData)) {
            $whoisData = $this->queryExternalApi($domain);
        }
        
        if (empty($whoisData)) {
            $result['error'] = 'Nie udało się sprawdzić domeny. Sprawdź konfigurację serwera.';
            return $result;
        }
        
        $result['raw'] = $whoisData;
        
        // Check if domain is registered
        $notFoundPatterns = [
            'no match',
            'not found',
            'no data found',
            'no entries found',
            'domain not found',
            'status: free',
            'status: available',
            'is free',
            'no object found',
            'available for registration',
        ];
        
        $lowerWhois = strtolower($whoisData);
        foreach ($notFoundPatterns as $pattern) {
            if (str_contains($lowerWhois, $pattern)) {
                $result['is_registered'] = false;
                return $result;
            }
        }
        
        $result['is_registered'] = true;
        
        // Extract expiry date
        $expiryPatterns = [
            '/expir(?:y|ation|es?)[^:]*:\s*(\d{4}[-\/]\d{2}[-\/]\d{2})/i',
            '/expir(?:y|ation|es?)[^:]*:\s*(\d{2}[-\/]\d{2}[-\/]\d{4})/i',
            '/expir(?:y|ation|es?)[^:]*:\s*(\d{2}[-\.]\w{3}[-\.]\d{4})/i',
            '/paid-till:\s*(\d{4}[\.\-]\d{2}[\.\-]\d{2})/i',
            '/registry expiry date:\s*(\d{4}-\d{2}-\d{2})/i',
            '/registrar registration expiration date:\s*(\d{4}-\d{2}-\d{2})/i',
            '/renewal date:\s*(\d{2}-\w{3}-\d{4})/i',
            '/expiry date:\s*(\d{4}-\d{2}-\d{2})/i',
            '/expiration:\s*(\d{4}-\d{2}-\d{2})/i',
        ];
        
        foreach ($expiryPatterns as $pattern) {
            if (preg_match($pattern, $whoisData, $matches)) {
                $dateStr = $matches[1];
                $timestamp = strtotime($dateStr);
                if ($timestamp !== false) {
                    $result['expiry_date'] = date('Y-m-d', $timestamp);
                    break;
                }
            }
        }
        
        // Extract registrar
        if (preg_match('/registrar:\s*(.+)/i', $whoisData, $matches)) {
            $result['registrar'] = trim($matches[1]);
        }
        
        return $result;
    }
    
    /**
     * Query RDAP API (HTTP-based, works on shared hosting)
     */
    private function queryRdap(string $domain): ?array {
        $parts = explode('.', $domain);
        $tld = end($parts);
        
        // RDAP bootstrap for common TLDs
        $rdapServers = [
            'com' => 'https://rdap.verisign.com/com/v1/domain/',
            'net' => 'https://rdap.verisign.com/net/v1/domain/',
            'org' => 'https://rdap.publicinterestregistry.org/rdap/domain/',
            'io' => 'https://rdap.nic.io/domain/',
            'pl' => 'https://rdap.dns.pl/domain/',
            'de' => 'https://rdap.denic.de/domain/',
            'eu' => 'https://rdap.eurid.eu/domain/',
        ];
        
        $rdapUrl = $rdapServers[$tld] ?? null;
        if (!$rdapUrl) {
            return null;
        }
        
        $ch = curl_init($rdapUrl . $domain);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Parse RDAP response
     */
    private function parseRdapResponse(string $domain, array $data): array {
        $result = [
            'domain' => $domain,
            'is_registered' => true,
            'expiry_date' => null,
            'registrar' => null,
            'raw' => json_encode($data, JSON_PRETTY_PRINT),
            'error' => null
        ];
        
        // Extract expiry date from events
        if (isset($data['events'])) {
            foreach ($data['events'] as $event) {
                if ($event['eventAction'] === 'expiration') {
                    $result['expiry_date'] = date('Y-m-d', strtotime($event['eventDate']));
                    break;
                }
            }
        }
        
        // Extract registrar
        if (isset($data['entities'])) {
            foreach ($data['entities'] as $entity) {
                if (in_array('registrar', $entity['roles'] ?? [])) {
                    $result['registrar'] = $entity['vcardArray'][1][1][3] ?? ($entity['handle'] ?? null);
                    break;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Query WHOIS via socket (blocked on some shared hosting)
     */
    private function queryWhoisSocket(string $server, string $domain): string {
        $socket = @fsockopen($server, 43, $errno, $errstr, 10);
        
        if (!$socket) {
            throw new Exception("Could not connect to WHOIS server: $errstr");
        }
        
        if (str_contains($server, 'verisign')) {
            $query = "=$domain\r\n";
        } else {
            $query = "$domain\r\n";
        }
        
        fwrite($socket, $query);
        
        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 128);
        }
        
        fclose($socket);
        
        // For Verisign, query registrar WHOIS for more details
        if (str_contains($server, 'verisign') && preg_match('/whois server:\s*(.+)/i', $response, $matches)) {
            $registrarWhois = trim($matches[1]);
            if ($registrarWhois && $registrarWhois !== $server) {
                try {
                    $detailedResponse = $this->queryWhoisSocket($registrarWhois, $domain);
                    $response .= "\n\n--- Registrar WHOIS ---\n" . $detailedResponse;
                } catch (Exception $e) {
                    // Ignore secondary lookup errors
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Query WHOIS via shell command
     */
    private function queryWhoisCommand(string $domain): string {
        if (!function_exists('shell_exec')) {
            return '';
        }
        
        $domain = escapeshellarg($domain);
        $output = @shell_exec("whois $domain 2>/dev/null");
        
        return $output ?: '';
    }
    
    /**
     * Query external API (fallback)
     */
    private function queryExternalApi(string $domain): string {
        // Using whoisjson.com free API
        $url = "https://whoisjson.com/api/v1/whois?domain=" . urlencode($domain);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                // Convert to text format for parsing
                $text = '';
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $text .= "$key: $value\n";
                    }
                }
                return $text;
            }
        }
        
        return '';
    }
    
    public function isAvailable(string $domain): bool {
        $result = $this->lookup($domain);
        return !$result['is_registered'];
    }
}
