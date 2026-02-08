const API_BASE = '/api';

interface ApiOptions {
  method?: string;
  body?: object;
  timeoutMs?: number;
}

async function apiCall<T>(endpoint: string, options: ApiOptions = {}): Promise<T> {
  const token = localStorage.getItem('token');
  
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  // Abort controller for timeout
  const controller = new AbortController();
  const timeoutMs = options.timeoutMs || 60000; // default 60s
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
  
  let response: Response;
  try {
    response = await fetch(`${API_BASE}/${endpoint}`, {
      method: options.method || 'GET',
      headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: controller.signal,
    });
  } catch (err: unknown) {
    clearTimeout(timeoutId);
    if (err instanceof DOMException && err.name === 'AbortError') {
      throw new Error('Przekroczono czas oczekiwania na odpowiedź serwera. AI może potrzebować więcej czasu — spróbuj ponownie.');
    }
    throw new Error('Nie udało się połączyć z serwerem. Sprawdź połączenie.');
  } finally {
    clearTimeout(timeoutId);
  }

  // Handle non-JSON responses (server error pages, timeouts returning HTML)
  const contentType = response.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    const text = await response.text().catch(() => '');
    if (text.includes('<!DOCTYPE') || text.includes('<html')) {
      throw new Error(`Serwer zwrócił błąd (HTTP ${response.status}). Prawdopodobnie przekroczono limit czasu — spróbuj ponownie.`);
    }
    // Try parsing as JSON anyway (some servers don't set content-type)
    try {
      const data = JSON.parse(text);
      if (!response.ok) throw new Error(data.error || `Błąd serwera (HTTP ${response.status})`);
      return data;
    } catch {
      throw new Error(`Nieoczekiwana odpowiedź serwera (HTTP ${response.status})`);
    }
  }
  
  const data = await response.json();
  
  if (!response.ok) {
    throw new Error(data.error || 'An error occurred');
  }
  
  return data;
}

// Auth
export async function login(email: string, password: string) {
  const result = await apiCall<{ token: string; user: User }>('auth/login', {
    method: 'POST',
    body: { email, password },
  });
  localStorage.setItem('token', result.token);
  return result;
}

export async function register(email: string, password: string, invite_token: string) {
  const result = await apiCall<{ token: string; user: User }>('auth/register', {
    method: 'POST',
    body: { email, password, invite_token },
  });
  localStorage.setItem('token', result.token);
  return result;
}

export async function getCurrentUser() {
  return apiCall<{ user: User }>('auth/me');
}

export function logout() {
  localStorage.removeItem('token');
}

// Domains
export async function getDomains() {
  return apiCall<{ domains: Domain[] }>('domains');
}

export async function addDomain(domain: string) {
  return apiCall<{ domain: Domain; whois: WhoisData }>('domains', {
    method: 'POST',
    body: { domain },
  });
}

export async function importDomains(text: string) {
  return apiCall<{ imported: ImportResult[] }>('domains/import', {
    method: 'POST',
    body: { text },
  });
}

// Import domains in batches to avoid timeouts
export async function importDomainsInBatches(
  domains: string[],
  batchSize: number = 50,
  onProgress?: (imported: number, total: number) => void
): Promise<{ imported: number; errors: number }> {
  let imported = 0;
  let errors = 0;
  
  for (let i = 0; i < domains.length; i += batchSize) {
    const batch = domains.slice(i, i + batchSize);
    try {
      const result = await importDomains(batch.join(','));
      imported += result.imported.length;
    } catch (err) {
      errors += batch.length;
    }
    
    if (onProgress) {
      onProgress(Math.min(i + batchSize, domains.length), domains.length);
    }
    
    // Small delay between batches to avoid overloading server
    if (i + batchSize < domains.length) {
      await new Promise(resolve => setTimeout(resolve, 200));
    }
  }
  
  return { imported, errors };
}

export async function checkDomain(id: number) {
  return apiCall<{ domain: Domain; whois: WhoisData }>('domains/check', {
    method: 'POST',
    body: { id },
  });
}

export async function deleteDomain(id: number) {
  return apiCall<{ success: boolean }>(`domains/${id}`, {
    method: 'DELETE',
  });
}

// Users
export async function getUsers() {
  return apiCall<{ users: User[] }>('users');
}

export async function createUser(email: string) {
  return apiCall<{ user: User; password: string }>('users', {
    method: 'POST',
    body: { email },
  });
}

export async function deleteUser(id: number) {
  return apiCall<{ success: boolean }>(`users/${id}`, {
    method: 'DELETE',
  });
}

// Invitations
export async function getInvitations() {
  return apiCall<{ invitations: Invitation[] }>('invitations');
}

export async function createInvitation(email?: string) {
  return apiCall<{ invitation: Invitation }>('invitations', {
    method: 'POST',
    body: { email },
  });
}

export async function deleteInvitation(id: number) {
  return apiCall<{ success: boolean }>(`invitations/${id}`, {
    method: 'DELETE',
  });
}

export async function verifyInvitation(token: string) {
  return apiCall<{ valid: boolean; email?: string }>('invitations/verify', {
    method: 'POST',
    body: { token },
  });
}

// Notifications
export async function getNotificationInfo() {
  return apiCall<{ topic: string; subscription_url: string; instructions: string; smtp_configured: boolean }>('notifications');
}

export async function testNtfy() {
  return apiCall<{ success: boolean; error?: string; server: string; topic: string }>('notifications/test-ntfy', {
    method: 'POST',
  });
}

export async function testEmail(email?: string) {
  return apiCall<{ success: boolean; error?: string }>('notifications/test-email', {
    method: 'POST',
    body: email ? { email } : {},
  });
}

// Types
export interface User {
  id: number;
  email: string;
  is_admin: boolean;
  created_at?: string;
}

export interface Domain {
  id: number;
  domain: string;
  expiry_date: string | null;
  is_registered: boolean;
  last_checked: string;
  added_by: number;
  added_by_email?: string;
  created_at: string;
}

export interface WhoisData {
  domain: string;
  is_registered: boolean;
  expiry_date: string | null;
  registrar: string | null;
  error?: string;
}

export interface Invitation {
  id: number;
  token: string;
  email: string | null;
  created_by: number;
  created_by_email: string;
  used: boolean;
  created_at: string;
}

export interface ImportResult {
  domain: string;
  expiry_date: string | null;
  is_registered: boolean;
  added: boolean;
}

export interface Job {
  id: number;
  user_id: number;
  type: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  total: number;
  claimed: number;
  processed: number;
  errors: number;
  data: string;
  result: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedDomains {
  domains: Domain[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    total_pages: number;
  };
}

export interface DomainFilters {
  search?: string;
  filter?: 'all' | 'registered' | 'available' | 'expiring';
  sort?: 'domain' | 'expiry_date' | 'is_registered' | 'last_checked' | 'created_at';
  dir?: 'ASC' | 'DESC';
  page?: number;
  limit?: number;
}

// Enhanced Domains with search/filter/pagination
export async function getDomainsFiltered(filters: DomainFilters = {}) {
  const params = new URLSearchParams();
  if (filters.search) params.append('search', filters.search);
  if (filters.filter) params.append('filter', filters.filter);
  if (filters.sort) params.append('sort', filters.sort);
  if (filters.dir) params.append('dir', filters.dir);
  if (filters.page) params.append('page', filters.page.toString());
  if (filters.limit) params.append('limit', filters.limit.toString());
  
  const query = params.toString();
  return apiCall<PaginatedDomains>(`domains${query ? `?${query}` : ''}`);
}

export interface DomainStats {
  total: number;
  registered: number;
  available: number;
  expiring: number;
}

export async function getDomainStats() {
  return apiCall<DomainStats>('domains/stats');
}

// Profile
export async function getProfile() {
  return apiCall<{ user: User }>('profile');
}

export async function updateProfile(data: { email?: string; password?: string; current_password: string }) {
  return apiCall<{ success: boolean; user: User }>('profile', {
    method: 'PUT',
    body: data,
  });
}

// Jobs (Background Tasks)
export async function getJobs() {
  return apiCall<{ jobs: Job[] }>('jobs');
}

export async function createJob(type: string, data: object) {
  return apiCall<{ job: Job }>('jobs', {
    method: 'POST',
    body: { type, data },
  });
}

export async function createBulkWhoisCheckJob(checkAll: boolean = true, domainIds?: number[]) {
  return createJob('whois_check', { check_all: checkAll, domain_ids: domainIds });
}

export async function createImportJob(domains: string[]) {
  return createJob('import', { domains });
}

export async function getJobStatus(id: number) {
  return apiCall<{ job: Job }>(`jobs/${id}`);
}

export async function processJob(jobId: number, batchSize: number = 20) {
  return apiCall<{ job: Job }>('jobs/process', {
    method: 'POST',
    body: { job_id: jobId, batch_size: batchSize },
  });
}

export async function resumeJob(jobId: number) {
  return apiCall<{ job: Job; message: string }>('jobs/resume', {
    method: 'POST',
    body: { job_id: jobId },
  });
}

export async function deleteJob(id: number) {
  return apiCall<{ success: boolean }>(`jobs/${id}`, {
    method: 'DELETE',
  });
}

// Admin: Update user role
export async function updateUserRole(id: number, isAdmin: boolean) {
  return apiCall<{ success: boolean; user: User }>(`users/${id}`, {
    method: 'PUT',
    body: { is_admin: isAdmin },
  });
}

// Domain Details
export interface DomainDetails {
  whois_raw: string;
  whois_parsed: {
    domain: string;
    is_registered: boolean;
    expiry_date: string | null;
    registrar: string | null;
    raw: string;
    error: string | null;
  };
  scrape_data: {
    title: string | null;
    description: string | null;
    keywords: string | null;
    h1: string[];
    links_count: number;
    images_count: number;
    text_content: string;
    technologies: string[];
    emails: string[];
    phones: string[];
    social_links: { platform: string; url: string }[];
    for_sale_indicators: string[];
    language: string | null;
    server: string | null;
    status_code: number | null;
    redirect_url: string | null;
    ssl_valid: boolean;
    ssl_expiry: string | null;
    error: string | null;
  };
  google_data: {
    results: { title: string; url: string; snippet: string }[];
    total_results: number;
    error: string | null;
  };
  dns_records: { type: string; host: string; value: string; ttl: number; priority: number | null }[];
  ai_analysis: string | null;
  scraped_at: string;
}

export async function getDomainDetails(id: number, refresh = false) {
  const qs = refresh ? '?refresh=1' : '';
  return apiCall<{ domain: Domain; details: DomainDetails; cached: boolean }>(`domains/${id}${qs}`);
}

export async function getDomainAiAnalysis(domainId: number) {
  return apiCall<{ ai_analysis: string | null; error: string | null }>(`ai/analyze/${domainId}`, {
    method: 'POST',
    timeoutMs: 360000, // 6 min — AI needs time
  });
}

// AI
export interface AiStatus {
  ollama_running: boolean;
  model: string;
  ollama_url: string;
  models_available: string[];
  model_ready: boolean;
  error: string | null;
}

export async function getAiStatus() {
  return apiCall<AiStatus>('ai/status');
}

export interface AiConversation {
  id: number;
  user_id: number;
  title: string;
  domain: string | null;
  created_at: string;
  updated_at: string;
  message_count?: number;
  messages?: AiMessage[];
}

export interface AiMessage {
  id: number;
  conversation_id: number;
  role: 'user' | 'assistant' | 'system';
  content: string;
  created_at: string;
}

export async function getConversations() {
  return apiCall<{ conversations: AiConversation[] }>('ai/conversations');
}

export async function createConversation(title?: string, domain?: string) {
  return apiCall<{ conversation: AiConversation }>('ai/conversations', {
    method: 'POST',
    body: { title, domain },
  });
}

export async function getConversation(id: number) {
  return apiCall<{ conversation: AiConversation }>(`ai/conversations/${id}`);
}

export async function sendMessage(conversationId: number, message: string) {
  return apiCall<{ message: AiMessage }>(`ai/conversations/${conversationId}`, {
    method: 'POST',
    body: { message },
    timeoutMs: 360000, // 6 min — AI needs time
  });
}

export async function deleteConversation(id: number) {
  return apiCall<{ success: boolean }>(`ai/conversations/${id}`, {
    method: 'DELETE',
  });
}

// Knowledge Base
export interface KnowledgeEntry {
  id: number;
  domain: string | null;
  type: string;
  content: string;
  source: string | null;
  added_by: number | null;
  created_at: string;
}

export async function getKnowledgeBase(domain?: string) {
  const qs = domain ? `?domain=${encodeURIComponent(domain)}` : '';
  return apiCall<{ knowledge: KnowledgeEntry[] }>(`ai/knowledge${qs}`);
}

export async function addKnowledge(content: string, type: string, domain?: string, source?: string) {
  return apiCall<{ knowledge: KnowledgeEntry }>('ai/knowledge', {
    method: 'POST',
    body: { content, type, domain, source },
  });
}

export async function deleteKnowledge(id: number) {
  return apiCall<{ success: boolean }>(`ai/knowledge/${id}`, {
    method: 'DELETE',
  });
}

export async function quickAiChat(message: string) {
  return apiCall<{ response: string }>('ai/chat', {
    method: 'POST',
    body: { message },
    timeoutMs: 360000, // 6 min — AI needs time
  });
}

// AI Environment Management (Docker)
export interface AiManagementResult {
  success: boolean;
  message: string;
  output: string;
  already_installed?: boolean;
  status?: AiStatus;
}

export interface DockerStatus {
  docker_available: boolean;
  docker_version: string | null;
  container: {
    id: string;
    status: string;
    image: string;
    ports: string;
  } | null;
  volume_exists: boolean;
}

export async function installOllama() {
  return apiCall<AiManagementResult>('ai/install', { method: 'POST' });
}

export async function pullModel(model?: string) {
  return apiCall<AiManagementResult>('ai/pull-model', {
    method: 'POST',
    body: model ? { model } : {},
    timeoutMs: 900000, // 15 min — model download
  });
}

export async function restartOllama() {
  return apiCall<AiManagementResult>('ai/restart', { method: 'POST' });
}

export async function stopOllama() {
  return apiCall<AiManagementResult>('ai/stop', { method: 'POST' });
}

export async function removeOllama() {
  return apiCall<AiManagementResult>('ai/remove', { method: 'POST' });
}

export async function getDockerStatus() {
  return apiCall<DockerStatus>('ai/docker-status');
}

export async function testOllama() {
  return apiCall<{ success: boolean; response: string | null; error: string | null; status: AiStatus }>('ai/test', { method: 'POST', timeoutMs: 180000 });
}

export async function setActiveModel(model: string) {
  return apiCall<{ success: boolean; message: string; model: string }>('ai/set-model', {
    method: 'POST',
    body: { model },
  });
}
