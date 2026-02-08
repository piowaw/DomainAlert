<?php
/**
 * High-speed parallel RDAP lookup engine
 * 
 * Uses curl_multi with massive concurrency (200+ simultaneous requests)
 * and optional multi-process forking via pcntl for maximum throughput.
 * 
 * Single process:  200 concurrent RDAP requests
 * Multi-process:   WORKERS × 200 = 1600+ concurrent requests
 * 
 * Expected throughput: 100-500 domains/sec depending on network & RDAP servers
 */

class RdapEngine {
    
    // How many curl handles run simultaneously in one process
    private int $concurrency;
    
    // Timeout per request
    private int $timeout;
    private int $connectTimeout;
    
    // Known RDAP servers per TLD (direct, no bootstrap lookup needed)
    private array $rdapServers = [
        'com'     => 'https://rdap.verisign.com/com/v1/domain/',
        'net'     => 'https://rdap.verisign.com/net/v1/domain/',
        'org'     => 'https://rdap.publicinterestregistry.org/rdap/domain/',
        'io'      => 'https://rdap.nic.io/domain/',
        'pl'      => 'https://rdap.dns.pl/domain/',
        'de'      => 'https://rdap.denic.de/domain/',
        'eu'      => 'https://rdap.eurid.eu/domain/',
        'uk'      => 'https://rdap.nominet.uk/uk/domain/',
        'co.uk'   => 'https://rdap.nominet.uk/uk/domain/',
        'fr'      => 'https://rdap.nic.fr/domain/',
        'nl'      => 'https://rdap.sidn.nl/domain/',
        'xyz'     => 'https://rdap.nic.xyz/domain/',
        'app'     => 'https://rdap.nic.google/domain/',
        'dev'     => 'https://rdap.nic.google/domain/',
        'page'    => 'https://rdap.nic.google/domain/',
        'co'      => 'https://rdap.nic.co/domain/',
        'me'      => 'https://rdap.nic.me/domain/',
        'info'    => 'https://rdap.afilias.net/rdap/info/domain/',
        'biz'     => 'https://rdap.nic.biz/domain/',
        'online'  => 'https://rdap.centralnic.com/online/domain/',
        'site'    => 'https://rdap.centralnic.com/site/domain/',
        'store'   => 'https://rdap.centralnic.com/store/domain/',
        'tech'    => 'https://rdap.centralnic.com/tech/domain/',
        'space'   => 'https://rdap.centralnic.com/space/domain/',
        'fun'     => 'https://rdap.centralnic.com/fun/domain/',
        'website' => 'https://rdap.centralnic.com/website/domain/',
        'shop'    => 'https://rdap.centralnic.com/shop/domain/',
        'cloud'   => 'https://rdap.centralnic.com/cloud/domain/',
        'club'    => 'https://rdap.centralnic.com/club/domain/',
        'live'    => 'https://rdap.centralnic.com/live/domain/',
        'pro'     => 'https://rdap.centralnic.com/pro/domain/',
        'ru'      => 'https://rdap.ripn.net/domain/',
        'su'      => 'https://rdap.ripn.net/domain/',
        'se'      => 'https://rdap.iis.se/domain/',
        'nu'      => 'https://rdap.iis.se/domain/',
        'be'      => 'https://rdap.dns.be/domain/',
        'cz'      => 'https://rdap.nic.cz/domain/',
        'sk'      => 'https://rdap.sk-nic.sk/domain/',
        'at'      => 'https://rdap.nic.at/domain/',
        'ch'      => 'https://rdap.nic.ch/domain/',
        'li'      => 'https://rdap.nic.ch/domain/',
        'it'      => 'https://rdap.nic.it/domain/',
        'es'      => 'https://rdap.nic.es/domain/',
        'pt'      => 'https://rdap.dns.pt/domain/',
        'fi'      => 'https://rdap.fi/domain/',
        'dk'      => 'https://rdap.dk-hostmaster.dk/domain/',
        'no'      => 'https://rdap.norid.no/domain/',
        'lt'      => 'https://rdap.domreg.lt/domain/',
        'lv'      => 'https://rdap.nic.lv/domain/',
        'ee'      => 'https://rdap.tld.ee/domain/',
        'au'      => 'https://rdap.auda.org.au/domain/',
        'nz'      => 'https://rdap.irs.net.nz/domain/',
        'ca'      => 'https://rdap.ca/domain/',
        'us'      => 'https://rdap.nic.us/domain/',
        'br'      => 'https://rdap.registro.br/domain/',
        'mx'      => 'https://rdap.mx/domain/',
        'ar'      => 'https://rdap.nic.ar/domain/',
        'cl'      => 'https://rdap.nic.cl/domain/',
        'jp'      => 'https://rdap.jprs.jp/domain/',
        'kr'      => 'https://rdap.kr/domain/',
        'cn'      => 'https://rdap.cnnic.cn/domain/',
        'tw'      => 'https://rdap.twnic.tw/domain/',
        'in'      => 'https://rdap.registry.in/domain/',
        'sg'      => 'https://rdap.sgnic.sg/domain/',
        'hk'      => 'https://rdap.hkirc.hk/domain/',
        'za'      => 'https://rdap.nic.za/domain/',
        'ke'      => 'https://rdap.kenic.or.ke/domain/',
        'ng'      => 'https://rdap.nic.net.ng/domain/',
        'top'     => 'https://rdap.nic.top/domain/',
        'vip'     => 'https://rdap.nic.vip/domain/',
        'icu'     => 'https://rdap.centralnic.com/icu/domain/',
        'cc'      => 'https://rdap.verisign.com/cc/v1/domain/',
        'tv'      => 'https://rdap.verisign.com/tv/v1/domain/',
        'name'    => 'https://rdap.verisign.com/name/v1/domain/',
        'mobi'    => 'https://rdap.nic.mobi/domain/',
        'asia'    => 'https://rdap.nic.asia/domain/',
        'tel'     => 'https://rdap.nic.tel/domain/',
        'travel'  => 'https://rdap.nic.travel/domain/',
        'museum'  => 'https://rdap.nic.museum/domain/',
        'coop'    => 'https://rdap.nic.coop/domain/',
        'aero'    => 'https://rdap.nic.aero/domain/',
    ];
    
    // Cache for IANA RDAP bootstrap (auto-discovered servers)
    private static array $bootstrapCache = [];
    
    public function __construct(int $concurrency = 200, int $timeout = 8, int $connectTimeout = 4) {
        $this->concurrency = $concurrency;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }
    
    /**
     * Get RDAP URL for a TLD. Uses hardcoded map first, then IANA bootstrap.
     */
    public function getRdapUrl(string $tld): ?string {
        $tld = strtolower($tld);
        
        // Known server
        if (isset($this->rdapServers[$tld])) {
            return $this->rdapServers[$tld];
        }
        
        // Already tried and failed
        if (isset(self::$bootstrapCache[$tld])) {
            return self::$bootstrapCache[$tld] ?: null;
        }
        
        // Try IANA RDAP bootstrap
        $url = $this->bootstrapRdap($tld);
        self::$bootstrapCache[$tld] = $url ?? '';
        
        if ($url) {
            $this->rdapServers[$tld] = $url;
        }
        
        return $url;
    }
    
    /**
     * IANA RDAP bootstrap — discover RDAP server for unknown TLD
     */
    private function bootstrapRdap(string $tld): ?string {
        $ch = curl_init('https://data.iana.org/rdap/dns.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code !== 200 || !$body) return null;
        
        $data = json_decode($body, true);
        if (!$data || !isset($data['services'])) return null;
        
        // Cache ALL TLDs from bootstrap to avoid repeated fetches
        foreach ($data['services'] as $service) {
            $tlds = $service[0] ?? [];
            $urls = $service[1] ?? [];
            if (empty($urls)) continue;
            $rdapUrl = rtrim($urls[0], '/') . '/domain/';
            foreach ($tlds as $t) {
                $t = strtolower($t);
                if (!isset($this->rdapServers[$t])) {
                    $this->rdapServers[$t] = $rdapUrl;
                    self::$bootstrapCache[$t] = $rdapUrl;
                }
            }
        }
        
        return $this->rdapServers[$tld] ?? null;
    }
    
    /**
     * Look up many domains in parallel using curl_multi with a sliding window.
     * 
     * Instead of batching (wait for all N to finish), this uses a rolling window:
     * as soon as one request completes, the next one starts immediately.
     * This keeps all $concurrency slots busy at all times.
     * 
     * @param string[] $domainNames Array of domain names
     * @return array [domainName => parsed_result | null]
     */
    public function lookupBatch(array $domainNames): array {
        $results = [];
        $queue = [];
        $noRdap = [];
        
        // Separate domains into RDAP-capable and fallback
        foreach ($domainNames as $name) {
            $tld = $this->extractTld($name);
            $url = $this->getRdapUrl($tld);
            if ($url) {
                $queue[] = ['name' => $name, 'url' => $url . $name];
            } else {
                $noRdap[] = $name;
                $results[$name] = null; // needs fallback
            }
        }
        
        if (empty($queue)) {
            return $results;
        }
        
        // Rolling window curl_multi
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->concurrency);
        curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $this->concurrency);
        
        $active = [];
        $queueIdx = 0;
        
        // Fill initial window
        while ($queueIdx < count($queue) && count($active) < $this->concurrency) {
            $item = $queue[$queueIdx++];
            $ch = $this->createHandle($item['url']);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = ['ch' => $ch, 'domain' => $item['name']];
        }
        
        // Process with rolling window
        do {
            $status = curl_multi_exec($mh, $running);
            
            // Check for completed transfers
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                
                if (isset($active[$key])) {
                    $domain = $active[$key]['domain'];
                    $results[$domain] = $this->parseResponse($ch, $domain);
                    
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($active[$key]);
                    
                    // Add next from queue (rolling window)
                    if ($queueIdx < count($queue)) {
                        $item = $queue[$queueIdx++];
                        $newCh = $this->createHandle($item['url']);
                        curl_multi_add_handle($mh, $newCh);
                        $active[(int)$newCh] = ['ch' => $newCh, 'domain' => $item['name']];
                    }
                }
            }
            
            if ($running > 0 && $status === CURLM_OK) {
                curl_multi_select($mh, 0.01);
            }
        } while (!empty($active));
        
        curl_multi_close($mh);
        
        return $results;
    }
    
    /**
     * Create a curl handle for RDAP request
     */
    private function createHandle(string $url): \CurlHandle {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_HTTPHEADER      => ['Accept: application/rdap+json'],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_ENCODING        => '', // accept gzip/deflate/br
            CURLOPT_TCP_FASTOPEN    => true,
            CURLOPT_TCP_NODELAY     => true,
            CURLOPT_DNS_CACHE_TIMEOUT => 600, // cache DNS for 10 min
        ]);
        return $ch;
    }
    
    /**
     * Parse a completed curl response into domain data
     */
    private function parseResponse(\CurlHandle $ch, string $domain): ?array {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        
        if ($code === 404) {
            return [
                'domain' => $domain,
                'is_registered' => false,
                'expiry_date' => null,
                'registrar' => null,
                'raw' => '',
                'error' => null,
            ];
        }
        
        if ($code !== 200 || !$body) {
            return null; // needs fallback
        }
        
        $json = json_decode($body, true);
        if (!$json) return null;
        
        $result = [
            'domain' => $domain,
            'is_registered' => true,
            'expiry_date' => null,
            'registrar' => null,
            'raw' => '',
            'error' => null,
        ];
        
        // Extract expiry date
        if (isset($json['events'])) {
            foreach ($json['events'] as $ev) {
                if (($ev['eventAction'] ?? '') === 'expiration' && !empty($ev['eventDate'])) {
                    $ts = strtotime($ev['eventDate']);
                    if ($ts) $result['expiry_date'] = date('Y-m-d', $ts);
                    break;
                }
            }
        }
        
        // Extract registrar
        if (isset($json['entities'])) {
            foreach ($json['entities'] as $ent) {
                if (in_array('registrar', $ent['roles'] ?? [])) {
                    $result['registrar'] = $ent['vcardArray'][1][1][3] 
                        ?? $ent['handle'] 
                        ?? $ent['legalRepresentative'] 
                        ?? null;
                    break;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Extract TLD from domain name (handles multi-part TLDs like co.uk)
     */
    private function extractTld(string $domain): string {
        $parts = explode('.', $domain);
        if (count($parts) >= 3) {
            $twoLevel = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            if (isset($this->rdapServers[$twoLevel])) {
                return $twoLevel;
            }
        }
        return end($parts);
    }
    
    /**
     * Fork multiple child processes, each processing a slice of domains.
     * Uses pcntl_fork for true parallelism. Each child returns results via a temp file.
     * 
     * @param string[] $domainNames
     * @param int $numWorkers Number of child processes
     * @return array [domainName => parsed_result | null]
     */
    public function lookupMultiProcess(array $domainNames, int $numWorkers = 8): array {
        if (!function_exists('pcntl_fork') || count($domainNames) < $numWorkers * 10) {
            // Not enough domains or no pcntl — single process
            return $this->lookupBatch($domainNames);
        }
        
        $chunks = array_chunk($domainNames, (int)ceil(count($domainNames) / $numWorkers));
        $tmpDir = sys_get_temp_dir();
        $pids = [];
        $tmpFiles = [];
        
        foreach ($chunks as $i => $chunk) {
            $tmpFile = "$tmpDir/rdap_worker_{$i}_" . getmypid() . ".json";
            $tmpFiles[$i] = $tmpFile;
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                // Fork failed, process in main
                $results = $this->lookupBatch($chunk);
                file_put_contents($tmpFile, json_encode($results));
            } elseif ($pid === 0) {
                // Child process
                $results = $this->lookupBatch($chunk);
                file_put_contents($tmpFile, json_encode($results));
                exit(0);
            } else {
                // Parent
                $pids[$i] = $pid;
            }
        }
        
        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Merge results
        $allResults = [];
        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                $data = json_decode(file_get_contents($tmpFile), true);
                if (is_array($data)) {
                    $allResults = array_merge($allResults, $data);
                }
                @unlink($tmpFile);
            }
        }
        
        return $allResults;
    }
    
    public function getConcurrency(): int {
        return $this->concurrency;
    }
}
