const API_BASE = '/api';

interface ApiOptions {
  method?: string;
  body?: object;
}

async function apiCall<T>(endpoint: string, options: ApiOptions = {}): Promise<T> {
  const token = localStorage.getItem('token');
  
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  
  const response = await fetch(`${API_BASE}/${endpoint}`, {
    method: options.method || 'GET',
    headers,
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  
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
