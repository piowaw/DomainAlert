<?php

class AiService {
    
    private string $ollamaUrl;
    private string $model;
    private PDO $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ollamaUrl = defined('OLLAMA_URL') ? OLLAMA_URL : 'http://localhost:11434';
        $this->model = defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'deepseek-r1:1.5b';
        
        $this->initTables();
    }
    
    private function initTables(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ai_knowledge (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT,
                type TEXT NOT NULL,
                content TEXT NOT NULL,
                source TEXT,
                added_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS ai_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT,
                domain TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
            
            CREATE TABLE IF NOT EXISTS ai_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS domain_details_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id INTEGER NOT NULL,
                whois_raw TEXT,
                whois_parsed TEXT,
                scrape_data TEXT,
                google_data TEXT,
                dns_records TEXT,
                ai_analysis TEXT,
                scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
            );
        ");
        
        // Add unique index on domain_id for cache
        try {
            $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_domain_details_domain_id ON domain_details_cache(domain_id)");
        } catch (Exception $e) {
            // Ignore if already exists
        }
    }
    
    /**
     * Check if Ollama is running and model is available
     */
    public function getStatus(): array {
        $status = [
            'ollama_running' => false,
            'model' => $this->model,
            'ollama_url' => $this->ollamaUrl,
            'models_available' => [],
            'error' => null,
        ];
        
        try {
            $ch = curl_init("{$this->ollamaUrl}/api/tags");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $status['ollama_running'] = true;
                $status['models_available'] = array_map(
                    fn($m) => $m['name'], 
                    $data['models'] ?? []
                );
            }
        } catch (Exception $e) {
            $status['error'] = $e->getMessage();
        }
        
        return $status;
    }
    
    /**
     * Analyze domain data with AI
     */
    public function analyzeDomain(string $domain, array $whoisData, array $scrapeData, array $googleData): ?string {
        $prompt = $this->buildDomainAnalysisPrompt($domain, $whoisData, $scrapeData, $googleData);
        
        // Get relevant knowledge base entries
        $knowledge = $this->getKnowledgeForDomain($domain);
        if (!empty($knowledge)) {
            $prompt .= "\n\nDodatkowa wiedza z bazy:\n";
            foreach ($knowledge as $entry) {
                $prompt .= "- [{$entry['type']}] {$entry['content']}\n";
            }
        }
        
        return $this->chat($prompt);
    }
    
    /**
     * Send a chat message and get AI response
     */
    public function chat(string $message, ?string $systemPrompt = null): ?string {
        if (!$systemPrompt) {
            $systemPrompt = "Jesteś ekspertem od domen internetowych, analizy stron WWW i wyceny domen. "
                . "Odpowiadasz po polsku. Twoja wiedza obejmuje: analiza WHOIS, wycena domen, "
                . "identyfikacja właścicieli, ocena wartości biznesowej strony, rozpoznawanie stron na sprzedaż, "
                . "analiza technologii i SEO. Bądź konkretny i precyzyjny w odpowiedziach.";
        }
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ];
        
        return $this->callOllama($messages);
    }
    
    /**
     * Continue a conversation
     */
    public function continueConversation(int $conversationId, string $userMessage, int $userId): ?array {
        // Get conversation
        $stmt = $this->db->prepare("SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conversation) {
            return null;
        }
        
        // Save user message
        $stmt = $this->db->prepare("INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
        $stmt->execute([$conversationId, $userMessage]);
        
        // Get conversation history
        $stmt = $this->db->prepare("SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->execute([$conversationId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build messages for AI
        $systemPrompt = "Jesteś ekspertem od domen internetowych, analizy stron WWW i wyceny domen. "
            . "Odpowiadasz po polsku. Twoja wiedza obejmuje: analiza WHOIS, wycena domen, "
            . "identyfikacja właścicieli, ocena wartości biznesowej strony, rozpoznawanie stron na sprzedaż, "
            . "analiza technologii i SEO. Prowadzisz dialog - pamiętasz kontekst rozmowy.";
        
        // If domain-specific conversation, add context
        if ($conversation['domain']) {
            $domainKnowledge = $this->getKnowledgeForDomain($conversation['domain']);
            if (!empty($domainKnowledge)) {
                $systemPrompt .= "\n\nWiedza o domenie {$conversation['domain']}:\n";
                foreach ($domainKnowledge as $entry) {
                    $systemPrompt .= "- [{$entry['type']}] {$entry['content']}\n";
                }
            }
        }
        
        // Get global knowledge
        $globalKnowledge = $this->getGlobalKnowledge();
        if (!empty($globalKnowledge)) {
            $systemPrompt .= "\n\nGlobalna baza wiedzy:\n";
            foreach ($globalKnowledge as $entry) {
                $systemPrompt .= "- [{$entry['type']}] {$entry['content']}\n";
            }
        }
        
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // Add history (limited to last 20 messages to avoid context overflow)
        $historySlice = array_slice($history, -20);
        foreach ($historySlice as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        
        // Call AI
        $aiResponse = $this->callOllama($messages);
        
        if ($aiResponse) {
            // Save AI response
            $stmt = $this->db->prepare("INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)");
            $stmt->execute([$conversationId, $aiResponse]);
            
            // Update conversation timestamp
            $stmt = $this->db->prepare("UPDATE ai_conversations SET updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$conversationId]);
        }
        
        return [
            'role' => 'assistant',
            'content' => $aiResponse ?? 'Przepraszam, nie udało się wygenerować odpowiedzi. Sprawdź czy Ollama jest uruchomiona.',
        ];
    }
    
    /**
     * Create a new conversation
     */
    public function createConversation(int $userId, ?string $title = null, ?string $domain = null): int {
        $stmt = $this->db->prepare("INSERT INTO ai_conversations (user_id, title, domain) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $title ?? 'Nowa rozmowa', $domain]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get user's conversations
     */
    public function getConversations(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                (SELECT COUNT(*) FROM ai_messages WHERE conversation_id = c.id) as message_count
            FROM ai_conversations c 
            WHERE c.user_id = ? 
            ORDER BY c.updated_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get conversation with messages
     */
    public function getConversation(int $conversationId, int $userId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conversation) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->execute([$conversationId]);
        $conversation['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $conversation;
    }
    
    /**
     * Delete conversation
     */
    public function deleteConversation(int $conversationId, int $userId): bool {
        $stmt = $this->db->prepare("DELETE FROM ai_conversations WHERE id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Add knowledge to the shared knowledge base
     */
    public function addKnowledge(string $content, string $type, ?string $domain = null, ?string $source = null, ?int $userId = null): int {
        $stmt = $this->db->prepare("INSERT INTO ai_knowledge (domain, type, content, source, added_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$domain, $type, $content, $source, $userId]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get knowledge base entries
     */
    public function getKnowledgeBase(?string $domain = null): array {
        if ($domain) {
            $stmt = $this->db->prepare("SELECT * FROM ai_knowledge WHERE domain = ? OR domain IS NULL ORDER BY created_at DESC");
            $stmt->execute([$domain]);
        } else {
            $stmt = $this->db->query("SELECT * FROM ai_knowledge ORDER BY created_at DESC LIMIT 100");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Delete knowledge entry
     */
    public function deleteKnowledge(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM ai_knowledge WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get knowledge for specific domain
     */
    private function getKnowledgeForDomain(string $domain): array {
        $stmt = $this->db->prepare("SELECT * FROM ai_knowledge WHERE domain = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$domain]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get global knowledge (not domain-specific)
     */
    private function getGlobalKnowledge(): array {
        $stmt = $this->db->query("SELECT * FROM ai_knowledge WHERE domain IS NULL ORDER BY created_at DESC LIMIT 20");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Build analysis prompt for domain
     */
    private function buildDomainAnalysisPrompt(string $domain, array $whoisData, array $scrapeData, array $googleData): string {
        $prompt = "Przeanalizuj poniższe dane o domenie **$domain** i napisz kompleksowy raport po polsku.\n\n";
        
        // WHOIS data
        $prompt .= "## Dane WHOIS:\n";
        $prompt .= "- Zarejestrowana: " . ($whoisData['is_registered'] ? 'TAK' : 'NIE') . "\n";
        if ($whoisData['expiry_date']) $prompt .= "- Data wygaśnięcia: {$whoisData['expiry_date']}\n";
        if ($whoisData['registrar']) $prompt .= "- Rejestrator: {$whoisData['registrar']}\n";
        
        // Website data
        if (!empty($scrapeData['title'])) {
            $prompt .= "\n## Dane ze strony WWW:\n";
            $prompt .= "- Tytuł: {$scrapeData['title']}\n";
            if ($scrapeData['description']) $prompt .= "- Opis: {$scrapeData['description']}\n";
            if ($scrapeData['language']) $prompt .= "- Język: {$scrapeData['language']}\n";
            if (!empty($scrapeData['technologies'])) $prompt .= "- Technologie: " . implode(', ', $scrapeData['technologies']) . "\n";
            if ($scrapeData['status_code']) $prompt .= "- Kod HTTP: {$scrapeData['status_code']}\n";
            if (!empty($scrapeData['emails'])) $prompt .= "- Emaile: " . implode(', ', $scrapeData['emails']) . "\n";
            if (!empty($scrapeData['for_sale_indicators'])) $prompt .= "- Wskaźniki sprzedaży: " . implode(', ', $scrapeData['for_sale_indicators']) . "\n";
            if (!empty($scrapeData['social_links'])) {
                $socials = array_map(fn($s) => "{$s['platform']}: {$s['url']}", $scrapeData['social_links']);
                $prompt .= "- Social media: " . implode(', ', $socials) . "\n";
            }
            if (!empty($scrapeData['text_content'])) {
                $prompt .= "- Treść strony (fragment): " . mb_substr($scrapeData['text_content'], 0, 2000) . "\n";
            }
        }
        
        // Google data
        if (!empty($googleData['results'])) {
            $prompt .= "\n## Wyniki Google:\n";
            $prompt .= "- Znalezionych wyników: ~{$googleData['total_results']}\n";
            foreach (array_slice($googleData['results'], 0, 5) as $result) {
                $prompt .= "- {$result['title']}: {$result['snippet']}\n";
            }
        }
        
        $prompt .= "\n## Proszę o:\n";
        $prompt .= "1. **Opis firmy/właściciela** - kim jest właściciel, czym się zajmuje\n";
        $prompt .= "2. **Ocena strony** - jakość, profesjonalizm, aktualność\n";
        $prompt .= "3. **Czy domena jest na sprzedaż?** - analiza wskaźników\n";
        $prompt .= "4. **Szacunkowa wartość domeny** - na podstawie nazwy, rozszerzenia, branży, ruchu\n";
        $prompt .= "5. **Wnioski i rekomendacje** - podsumowanie\n";
        
        return $prompt;
    }
    
    /**
     * Call Ollama API
     */
    private function callOllama(array $messages): ?string {
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'num_predict' => 2048,
            ],
        ];
        
        $ch = curl_init("{$this->ollamaUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120, // AI can take long
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Ollama curl error: {$error}");
            return null;
        }
        
        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            return $responseData['message']['content'] ?? null;
        }
        
        error_log("Ollama HTTP {$httpCode}: {$response}");
        return null;
    }
    
    /**
     * Cache domain details
     */
    public function cacheDomainDetails(int $domainId, array $data): void {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO domain_details_cache 
            (domain_id, whois_raw, whois_parsed, scrape_data, google_data, dns_records, ai_analysis, scraped_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $domainId,
            $data['whois_raw'] ?? null,
            json_encode($data['whois_parsed'] ?? []),
            json_encode($data['scrape_data'] ?? []),
            json_encode($data['google_data'] ?? []),
            json_encode($data['dns_records'] ?? []),
            $data['ai_analysis'] ?? null,
        ]);
    }
    
    /**
     * Get cached domain details
     */
    public function getCachedDomainDetails(int $domainId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM domain_details_cache WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        $cache = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cache) return null;
        
        return [
            'whois_raw' => $cache['whois_raw'],
            'whois_parsed' => json_decode($cache['whois_parsed'], true),
            'scrape_data' => json_decode($cache['scrape_data'], true),
            'google_data' => json_decode($cache['google_data'], true),
            'dns_records' => json_decode($cache['dns_records'], true),
            'ai_analysis' => $cache['ai_analysis'],
            'scraped_at' => $cache['scraped_at'],
        ];
    }
}
