<?php

class ScraperService {
    
    /**
     * Scrape website content and meta information
     */
    public function scrapeWebsite(string $domain): array {
        $result = [
            'title' => null,
            'description' => null,
            'keywords' => null,
            'h1' => [],
            'links_count' => 0,
            'images_count' => 0,
            'text_content' => '',
            'technologies' => [],
            'emails' => [],
            'phones' => [],
            'social_links' => [],
            'for_sale_indicators' => [],
            'language' => null,
            'server' => null,
            'status_code' => null,
            'redirect_url' => null,
            'ssl_valid' => false,
            'ssl_expiry' => null,
            'scraped_at' => date('Y-m-d H:i:s'),
            'error' => null,
        ];
        
        // Try HTTPS first, then HTTP
        $urls = ["https://$domain", "http://$domain"];
        $html = null;
        $headers = [];
        
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DomainAlert/1.0)',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => true,
                CURLOPT_ENCODING => '', // Accept all encodings
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $sslVerify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
            
            if ($response !== false && $httpCode > 0) {
                $result['status_code'] = $httpCode;
                $result['redirect_url'] = ($effectiveUrl !== $url) ? $effectiveUrl : null;
                $result['ssl_valid'] = ($sslVerify === 0 && str_starts_with($url, 'https'));
                
                $headerStr = substr($response, 0, $headerSize);
                $html = substr($response, $headerSize);
                
                // Parse headers
                foreach (explode("\r\n", $headerStr) as $line) {
                    if (str_contains($line, ':')) {
                        [$key, $val] = explode(':', $line, 2);
                        $headers[strtolower(trim($key))] = trim($val);
                    }
                }
                
                $result['server'] = $headers['server'] ?? null;
                
                // SSL certificate info
                if (str_starts_with($url, 'https')) {
                    $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
                    if (!empty($certInfo)) {
                        foreach ($certInfo as $cert) {
                            if (isset($cert['Expire date'])) {
                                $result['ssl_expiry'] = date('Y-m-d', strtotime($cert['Expire date']));
                            }
                        }
                    }
                }
                
                curl_close($ch);
                break;
            }
            
            curl_close($ch);
        }
        
        if (empty($html)) {
            $result['error'] = 'Nie udało się połączyć ze stroną';
            return $result;
        }
        
        // Parse HTML
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new DOMXPath($doc);
        
        // Title
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $result['title'] = trim($titleNodes->item(0)->textContent);
        }
        
        // Meta description
        $descNodes = $xpath->query('//meta[@name="description"]/@content');
        if ($descNodes->length > 0) {
            $result['description'] = trim($descNodes->item(0)->nodeValue);
        }
        
        // Meta keywords
        $kwNodes = $xpath->query('//meta[@name="keywords"]/@content');
        if ($kwNodes->length > 0) {
            $result['keywords'] = trim($kwNodes->item(0)->nodeValue);
        }
        
        // Language
        $htmlNodes = $xpath->query('//html/@lang');
        if ($htmlNodes->length > 0) {
            $result['language'] = trim($htmlNodes->item(0)->nodeValue);
        }
        
        // H1 tags
        $h1Nodes = $xpath->query('//h1');
        foreach ($h1Nodes as $h1) {
            $text = trim($h1->textContent);
            if ($text) $result['h1'][] = $text;
        }
        
        // Count links and images
        $result['links_count'] = $xpath->query('//a')->length;
        $result['images_count'] = $xpath->query('//img')->length;
        
        // Extract text content (clean body)
        $bodyNodes = $xpath->query('//body');
        if ($bodyNodes->length > 0) {
            // Remove script and style elements
            $scripts = $xpath->query('//script | //style | //noscript');
            foreach ($scripts as $script) {
                $script->parentNode->removeChild($script);
            }
            $text = trim($bodyNodes->item(0)->textContent);
            // Clean whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $result['text_content'] = mb_substr($text, 0, 5000); // Limit to 5000 chars
        }
        
        // Detect technologies
        $result['technologies'] = $this->detectTechnologies($html, $headers);
        
        // Extract emails
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $html, $emailMatches);
        $result['emails'] = array_values(array_unique($emailMatches[0] ?? []));
        
        // Extract phone numbers
        preg_match_all('/(?:\+\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{2,4}[-.\s]?\d{2,4}(?:[-.\s]?\d{2,4})?/', $result['text_content'], $phoneMatches);
        $phones = array_filter($phoneMatches[0] ?? [], fn($p) => strlen(preg_replace('/\D/', '', $p)) >= 7);
        $result['phones'] = array_values(array_unique(array_slice($phones, 0, 10)));
        
        // Social links
        $socialPatterns = [
            'facebook' => '/facebook\.com\/[a-zA-Z0-9.]+/i',
            'twitter' => '/(?:twitter|x)\.com\/[a-zA-Z0-9_]+/i',
            'linkedin' => '/linkedin\.com\/(?:company|in)\/[a-zA-Z0-9_-]+/i',
            'instagram' => '/instagram\.com\/[a-zA-Z0-9_.]+/i',
            'youtube' => '/youtube\.com\/(?:channel|c|@)[\/a-zA-Z0-9_-]+/i',
            'github' => '/github\.com\/[a-zA-Z0-9_-]+/i',
        ];
        
        foreach ($socialPatterns as $platform => $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $result['social_links'][] = ['platform' => $platform, 'url' => 'https://' . $match[0]];
            }
        }
        
        // For sale indicators
        $forSalePatterns = [
            'domain is for sale',
            'this domain is for sale',
            'buy this domain',
            'domain for sale',
            'ta domena jest na sprzedaż',
            'domena na sprzedaż',
            'kup domenę',
            'domain available for purchase',
            'make an offer',
            'złóż ofertę',
            'dan.com', 'sedo.com', 'afternic.com', 'godaddy.com/domain-auctions',
            'hugedomains.com', 'undeveloped.com',
        ];
        
        $lowerHtml = strtolower($html);
        foreach ($forSalePatterns as $pattern) {
            if (str_contains($lowerHtml, $pattern)) {
                $result['for_sale_indicators'][] = $pattern;
            }
        }
        
        return $result;
    }
    
    /**
     * Search Google for domain information
     */
    public function searchGoogle(string $domain): array {
        $result = [
            'results' => [],
            'total_results' => 0,
            'cached_pages' => false,
            'error' => null,
        ];
        
        // Search Google for the domain
        $query = urlencode("\"$domain\" OR site:$domain");
        $url = "https://www.google.com/search?q=$query&num=10&hl=pl";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pl,en-US;q=0.9,en;q=0.8',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$html) {
            $result['error'] = 'Nie udało się przeszukać Google';
            return $result;
        }
        
        // Parse Google results
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new DOMXPath($doc);
        
        // Extract search results
        $resultDivs = $xpath->query('//div[@class="g"] | //div[contains(@class, "tF2Cxc")]');
        foreach ($resultDivs as $div) {
            $titleNode = $xpath->query('.//h3', $div);
            $linkNode = $xpath->query('.//a/@href', $div);
            $snippetNode = $xpath->query('.//div[contains(@class, "VwiC3b")] | .//span[contains(@class, "aCOpRe")]', $div);
            
            $title = $titleNode->length > 0 ? trim($titleNode->item(0)->textContent) : '';
            $link = $linkNode->length > 0 ? trim($linkNode->item(0)->nodeValue) : '';
            $snippet = $snippetNode->length > 0 ? trim($snippetNode->item(0)->textContent) : '';
            
            if ($title && $link) {
                $result['results'][] = [
                    'title' => $title,
                    'url' => $link,
                    'snippet' => $snippet,
                ];
            }
        }
        
        // Try to extract total results count
        if (preg_match('/Około\s+([\d\s,.]+)\s+wynik/i', $html, $match)) {
            $result['total_results'] = (int)preg_replace('/\D/', '', $match[1]);
        } elseif (preg_match('/About\s+([\d,]+)\s+result/i', $html, $match)) {
            $result['total_results'] = (int)str_replace(',', '', $match[1]);
        }
        
        $result['total_results'] = max($result['total_results'], count($result['results']));
        
        return $result;
    }
    
    /**
     * Detect web technologies from HTML and headers
     */
    private function detectTechnologies(string $html, array $headers): array {
        $technologies = [];
        
        $patterns = [
            'WordPress' => ['/wp-content/i', '/wp-includes/i', '/wordpress/i'],
            'Joomla' => ['/joomla/i', '/com_content/i'],
            'Drupal' => ['/drupal/i', '/sites\/all/i'],
            'Shopify' => ['/cdn\.shopify\.com/i', '/shopify/i'],
            'Wix' => ['/wix\.com/i', '/wixstatic\.com/i'],
            'Squarespace' => ['/squarespace/i', '/sqsp\.com/i'],
            'React' => ['/__react/i', '/react-root/i', '/react\.production/i'],
            'Vue.js' => ['/vue\.js/i', '/vue\.min\.js/i', '/__vue/i'],
            'Angular' => ['/angular/i', '/ng-version/i'],
            'Next.js' => ['/_next\//i', '/__next/i'],
            'Laravel' => ['/laravel/i', '/csrf-token/i'],
            'Django' => ['/csrfmiddlewaretoken/i', '/django/i'],
            'Bootstrap' => ['/bootstrap/i'],
            'Tailwind CSS' => ['/tailwindcss/i'],
            'jQuery' => ['/jquery/i'],
            'Google Analytics' => ['/google-analytics\.com/i', '/gtag/i', '/ga\.js/i'],
            'Google Tag Manager' => ['/googletagmanager\.com/i'],
            'Cloudflare' => ['/cloudflare/i'],
            'Nginx' => [],
            'Apache' => [],
            'PHP' => [],
        ];
        
        // Check headers for server tech
        $serverHeader = strtolower($headers['server'] ?? '');
        $poweredBy = strtolower($headers['x-powered-by'] ?? '');
        
        if (str_contains($serverHeader, 'nginx')) $technologies[] = 'Nginx';
        if (str_contains($serverHeader, 'apache')) $technologies[] = 'Apache';
        if (str_contains($serverHeader, 'cloudflare')) $technologies[] = 'Cloudflare';
        if (str_contains($poweredBy, 'php')) $technologies[] = 'PHP';
        if (str_contains($poweredBy, 'asp.net')) $technologies[] = 'ASP.NET';
        if (str_contains($poweredBy, 'express')) $technologies[] = 'Express.js';
        
        // Check HTML patterns
        foreach ($patterns as $tech => $techPatterns) {
            if (in_array($tech, $technologies)) continue;
            foreach ($techPatterns as $pattern) {
                if (preg_match($pattern, $html)) {
                    $technologies[] = $tech;
                    break;
                }
            }
        }
        
        return array_values(array_unique($technologies));
    }
    
    /**
     * Get DNS records for a domain
     */
    public function getDnsRecords(string $domain): array {
        $records = [];
        
        $types = [DNS_A, DNS_AAAA, DNS_MX, DNS_NS, DNS_TXT, DNS_CNAME, DNS_SOA];
        $typeNames = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA'];
        
        foreach ($types as $i => $type) {
            try {
                $dnsRecords = @dns_get_record($domain, $type);
                if ($dnsRecords) {
                    foreach ($dnsRecords as $record) {
                        $records[] = [
                            'type' => $typeNames[$i],
                            'host' => $record['host'] ?? $domain,
                            'value' => $record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? $record['txt'] ?? $record['mname'] ?? '',
                            'ttl' => $record['ttl'] ?? 0,
                            'priority' => $record['pri'] ?? null,
                        ];
                    }
                }
            } catch (Exception $e) {
                // Ignore DNS errors
            }
        }
        
        return $records;
    }
}
