import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { getDomainsFiltered, addDomain, importDomainsInBatches, checkDomain, deleteDomain, getNotificationInfo, testNtfy, testEmail, type Domain, type DomainFilters } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Globe, Plus, Upload, RefreshCw, Trash2, Bell, LogOut, Settings, ExternalLink, Loader2, Wand2, Search, ChevronLeft, ChevronRight, ListTodo } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function DashboardPage() {
  const { user, logout } = useAuth();
  const [domains, setDomains] = useState<Domain[]>([]);
  const [loading, setLoading] = useState(true);
  const [newDomain, setNewDomain] = useState('');
  const [importText, setImportText] = useState('');
  const [addLoading, setAddLoading] = useState(false);
  const [importLoading, setImportLoading] = useState(false);
  const [checkingId, setCheckingId] = useState<number | null>(null);
  const [notifyInfo, setNotifyInfo] = useState<{ topic: string; subscription_url: string } | null>(null);
  const [addDialogOpen, setAddDialogOpen] = useState(false);
  const [importDialogOpen, setImportDialogOpen] = useState(false);
  const [notifyDialogOpen, setNotifyDialogOpen] = useState(false);
  const [testingNtfy, setTestingNtfy] = useState(false);
  const [testingEmail, setTestingEmail] = useState(false);
  const [importProgress, setImportProgress] = useState<{ current: number; total: number } | null>(null);
  
  // Filter and pagination state
  const [searchQuery, setSearchQuery] = useState('');
  const [filter, setFilter] = useState<'all' | 'registered' | 'available' | 'expiring'>('all');
  const [sortBy, setSortBy] = useState<'domain' | 'expiry_date' | 'created_at'>('expiry_date');
  const [sortDir, setSortDir] = useState<'ASC' | 'DESC'>('ASC');
  const [page, setPage] = useState(1);
  const [limit, setLimit] = useState(25);
  const [totalPages, setTotalPages] = useState(1);
  const [totalDomains, setTotalDomains] = useState(0);
  
  // Stats
  const [stats, setStats] = useState({ registered: 0, available: 0, expiring: 0 });

  const loadDomains = useCallback(async () => {
    try {
      const filters: DomainFilters = {
        search: searchQuery || undefined,
        filter,
        sort: sortBy,
        dir: sortDir,
        page,
        limit,
      };
      const result = await getDomainsFiltered(filters);
      setDomains(result.domains);
      setTotalPages(result.pagination.total_pages);
      setTotalDomains(result.pagination.total);
    } catch (err) {
      console.error('Failed to load domains:', err);
    } finally {
      setLoading(false);
    }
  }, [searchQuery, filter, sortBy, sortDir, page, limit]);
  
  // Load stats separately for all domains
  const loadStats = useCallback(async () => {
    try {
      const all = await getDomainsFiltered({ limit: 100000 }); // Get all for stats
      const allDomains = all.domains;
      const registered = allDomains.filter(d => d.is_registered).length;
      const available = allDomains.filter(d => !d.is_registered).length;
      const expiring = allDomains.filter(d => {
        if (!d.is_registered || !d.expiry_date) return false;
        const days = Math.ceil((new Date(d.expiry_date).getTime() - Date.now()) / (1000 * 60 * 60 * 24));
        return days <= 30;
      }).length;
      setStats({ registered, available, expiring });
    } catch (err) {
      console.error('Failed to load stats:', err);
    }
  }, []);

  useEffect(() => {
    loadDomains();
    loadNotifyInfo();
  }, [loadDomains]);
  
  useEffect(() => {
    loadStats();
  }, [loadStats]);
  
  // Reset to page 1 when filters change
  useEffect(() => {
    setPage(1);
  }, [searchQuery, filter, sortBy, sortDir, limit]);

  const loadNotifyInfo = async () => {
    try {
      const result = await getNotificationInfo();
      setNotifyInfo(result);
    } catch (err) {
      console.error('Failed to load notification info:', err);
    }
  };

  const handleAddDomain = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newDomain.trim()) return;

    setAddLoading(true);
    try {
      await addDomain(newDomain);
      setNewDomain('');
      setAddDialogOpen(false);
      await loadDomains();
      await loadStats();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to add domain');
    } finally {
      setAddLoading(false);
    }
  };

  const handleImport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!importText.trim()) return;

    setImportLoading(true);
    setImportProgress({ current: 0, total: 0 });
    
    try {
      // Parse domains from text
      const domainList = importText
        .split(/[,\s\n]+/)
        .map(d => d.trim())
        .filter(d => d.length > 0);
      
      if (domainList.length === 0) {
        alert('Nie znaleziono domen do zaimportowania');
        return;
      }
      
      setImportProgress({ current: 0, total: domainList.length });
      
      const result = await importDomainsInBatches(
        domainList,
        50, // batch size
        (current, total) => setImportProgress({ current, total })
      );
      
      alert(`Zaimportowano ${result.imported} domen${result.errors > 0 ? ` (błędy: ${result.errors})` : ''}`);
      setImportText('');
      setImportDialogOpen(false);
      await loadDomains();
      await loadStats();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to import domains');
    } finally {
      setImportLoading(false);
      setImportProgress(null);
    }
  };

  const handleCheck = async (id: number) => {
    setCheckingId(id);
    try {
      await checkDomain(id);
      await loadDomains();
      await loadStats();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to check domain');
    } finally {
      setCheckingId(null);
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Czy na pewno chcesz usunąć tę domenę?')) return;
    
    try {
      await deleteDomain(id);
      await loadDomains();
      await loadStats();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to delete domain');
    }
  };

  const formatDate = (dateStr: string | null) => {
    if (!dateStr) return 'Brak danych';
    const date = new Date(dateStr);
    return date.toLocaleDateString('pl-PL');
  };

  const getDaysUntilExpiry = (dateStr: string | null) => {
    if (!dateStr) return null;
    const expiry = new Date(dateStr);
    const today = new Date();
    const diff = Math.ceil((expiry.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    return diff;
  };

  const getExpiryBadge = (domain: Domain) => {
    if (!domain.is_registered) {
      return <Badge variant="success">Dostępna!</Badge>;
    }
    
    const days = getDaysUntilExpiry(domain.expiry_date);
    if (days === null) {
      return <Badge variant="secondary">Brak daty</Badge>;
    }
    if (days < 0) {
      return <Badge variant="destructive">Wygasła</Badge>;
    }
    if (days <= 7) {
      return <Badge variant="destructive">{days} dni</Badge>;
    }
    if (days <= 30) {
      return <Badge variant="warning">{days} dni</Badge>;
    }
    return <Badge variant="outline">{days} dni</Badge>;
  };

  return (
    <div className="min-h-screen bg-muted/30">
      {/* Header */}
      <header className="bg-background border-b">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="bg-primary rounded-lg p-2">
              <Globe className="h-6 w-6 text-primary-foreground" />
            </div>
            <h1 className="text-xl font-bold">DomainAlert</h1>
          </div>
          <div className="flex items-center gap-4">
            <Link to="/generator">
              <Button variant="outline" size="sm">
                <Wand2 className="h-4 w-4 mr-2" />
                Generator
              </Button>
            </Link>
            <Link to="/tasks">
              <Button variant="outline" size="sm">
                <ListTodo className="h-4 w-4 mr-2" />
                Zadania
              </Button>
            </Link>
            <Dialog open={notifyDialogOpen} onOpenChange={setNotifyDialogOpen}>
              <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                  <Bell className="h-4 w-4 mr-2" />
                  Powiadomienia
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Powiadomienia Push</DialogTitle>
                  <DialogDescription>
                    Zasubskrybuj powiadomienia przez ntfy
                  </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                  <p className="text-sm text-muted-foreground">
                    Aby otrzymywać powiadomienia o dostępnych domenach, zainstaluj aplikację ntfy i zasubskrybuj poniższy temat:
                  </p>
                  {notifyInfo && (
                    <div className="space-y-2">
                      <Label>Temat ntfy</Label>
                      <div className="flex gap-2">
                        <Input value={notifyInfo.topic} readOnly />
                        <Button
                          variant="outline"
                          onClick={() => navigator.clipboard.writeText(notifyInfo.topic)}
                        >
                          Kopiuj
                        </Button>
                      </div>
                    </div>
                  )}
                  <div className="flex gap-2">
                    <a href="https://ntfy.sh" target="_blank" rel="noopener noreferrer">
                      <Button variant="outline" size="sm">
                        <ExternalLink className="h-4 w-4 mr-2" />
                        ntfy.sh
                      </Button>
                    </a>
                    {notifyInfo && (
                      <a href={notifyInfo.subscription_url} target="_blank" rel="noopener noreferrer">
                        <Button variant="outline" size="sm">
                          <ExternalLink className="h-4 w-4 mr-2" />
                          Otwórz temat
                        </Button>
                      </a>
                    )}
                  </div>
                  <div className="border-t pt-4 mt-4">
                    <Label className="mb-2 block">Testuj powiadomienia</Label>
                    <div className="flex gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={testingNtfy}
                        onClick={async () => {
                          setTestingNtfy(true);
                          try {
                            const result = await testNtfy();
                            if (result.success) {
                              alert('Powiadomienie ntfy wysłane!');
                            } else {
                              alert('Błąd: ' + (result.error || 'Nieznany błąd'));
                            }
                          } catch (err) {
                            alert(err instanceof Error ? err.message : 'Błąd');
                          } finally {
                            setTestingNtfy(false);
                          }
                        }}
                      >
                        {testingNtfy ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Bell className="h-4 w-4 mr-2" />}
                        Test ntfy
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={testingEmail}
                        onClick={async () => {
                          setTestingEmail(true);
                          try {
                            const result = await testEmail();
                            if (result.success) {
                              alert('Email testowy wysłany!');
                            } else {
                              alert('Błąd: ' + (result.error || 'Nieznany błąd'));
                            }
                          } catch (err) {
                            alert(err instanceof Error ? err.message : 'Błąd');
                          } finally {
                            setTestingEmail(false);
                          }
                        }}
                      >
                        {testingEmail ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Bell className="h-4 w-4 mr-2" />}
                        Test Email
                      </Button>
                    </div>
                  </div>
                </div>
              </DialogContent>
            </Dialog>
            {user?.is_admin && (
              <Link to="/admin">
                <Button variant="outline" size="sm">
                  <Settings className="h-4 w-4 mr-2" />
                  Admin
                </Button>
              </Link>
            )}
            <Link to="/settings">
              <Button variant="ghost" size="sm">
                <Settings className="h-4 w-4" />
              </Button>
            </Link>
            <span className="text-sm text-muted-foreground">{user?.email}</span>
            <Button variant="ghost" size="sm" onClick={logout}>
              <LogOut className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </header>

      {/* Main content */}
      <main className="container mx-auto px-4 py-8">
        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Wszystkie domeny</CardDescription>
              <CardTitle className="text-3xl">{stats.registered + stats.available}</CardTitle>
            </CardHeader>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Zarejestrowane</CardDescription>
              <CardTitle className="text-3xl">{stats.registered}</CardTitle>
            </CardHeader>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Dostępne</CardDescription>
              <CardTitle className="text-3xl text-green-600">{stats.available}</CardTitle>
            </CardHeader>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Wygasają w 30 dni</CardDescription>
              <CardTitle className="text-3xl text-orange-600">{stats.expiring}</CardTitle>
            </CardHeader>
          </Card>
        </div>

        {/* Actions */}
        <div className="flex gap-4 mb-6">
          <Dialog open={addDialogOpen} onOpenChange={setAddDialogOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" />
                Dodaj domenę
              </Button>
            </DialogTrigger>
            <DialogContent>
              <form onSubmit={handleAddDomain}>
                <DialogHeader>
                  <DialogTitle>Dodaj domenę</DialogTitle>
                  <DialogDescription>
                    Wprowadź nazwę domeny do monitorowania
                  </DialogDescription>
                </DialogHeader>
                <div className="py-4">
                  <Label htmlFor="domain">Domena</Label>
                  <Input
                    id="domain"
                    placeholder="example.com"
                    value={newDomain}
                    onChange={(e) => setNewDomain(e.target.value)}
                    className="mt-2"
                  />
                </div>
                <DialogFooter>
                  <Button type="submit" disabled={addLoading}>
                    {addLoading ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Sprawdzanie...
                      </>
                    ) : (
                      'Dodaj'
                    )}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>

          <Dialog open={importDialogOpen} onOpenChange={setImportDialogOpen}>
            <DialogTrigger asChild>
              <Button variant="outline">
                <Upload className="h-4 w-4 mr-2" />
                Importuj z listy
              </Button>
            </DialogTrigger>
            <DialogContent>
              <form onSubmit={handleImport}>
                <DialogHeader>
                  <DialogTitle>Importuj domeny</DialogTitle>
                  <DialogDescription>
                    Wklej listę domen oddzielonych przecinkami, spacjami lub nowymi liniami
                  </DialogDescription>
                </DialogHeader>
                <div className="py-4">
                  <Label htmlFor="import">Lista domen</Label>
                  <Textarea
                    id="import"
                    placeholder="example.com, test.pl&#10;another-domain.net"
                    value={importText}
                    onChange={(e) => setImportText(e.target.value)}
                    className="mt-2 h-32"
                  />
                  {importProgress && (
                    <div className="mt-4 space-y-2">
                      <div className="flex justify-between text-sm">
                        <span>Postęp importu...</span>
                        <span>{importProgress.current} / {importProgress.total}</span>
                      </div>
                      <div className="w-full bg-muted rounded-full h-2">
                        <div 
                          className="bg-primary h-2 rounded-full transition-all"
                          style={{ width: `${importProgress.total > 0 ? (importProgress.current / importProgress.total) * 100 : 0}%` }}
                        />
                      </div>
                    </div>
                  )}
                </div>
                <DialogFooter>
                  <Button type="submit" disabled={importLoading}>
                    {importLoading ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Importowanie...
                      </>
                    ) : (
                      'Importuj'
                    )}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>

          <Button variant="outline" onClick={loadDomains}>
            <RefreshCw className="h-4 w-4 mr-2" />
            Odśwież
          </Button>
        </div>

        {/* Domains table */}
        <Card>
          <CardHeader>
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
              <div>
                <CardTitle>Lista domen</CardTitle>
                <CardDescription>
                  {totalDomains} domen • Strona {page} z {totalPages}
                </CardDescription>
              </div>
              
              {/* Search and filters */}
              <div className="flex flex-wrap gap-2 items-center">
                <div className="relative">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Szukaj domeny..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="pl-8 w-48"
                  />
                </div>
                
                <Select value={filter} onValueChange={(v) => setFilter(v as typeof filter)}>
                  <SelectTrigger className="w-36">
                    <SelectValue placeholder="Filtr" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Wszystkie</SelectItem>
                    <SelectItem value="registered">Zarejestrowane</SelectItem>
                    <SelectItem value="available">Dostępne</SelectItem>
                    <SelectItem value="expiring">Wygasające</SelectItem>
                  </SelectContent>
                </Select>
                
                <Select value={sortBy} onValueChange={(v) => setSortBy(v as typeof sortBy)}>
                  <SelectTrigger className="w-40">
                    <SelectValue placeholder="Sortuj" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="expiry_date">Data wygaśnięcia</SelectItem>
                    <SelectItem value="domain">Nazwa domeny</SelectItem>
                    <SelectItem value="created_at">Data dodania</SelectItem>
                  </SelectContent>
                </Select>
                
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSortDir(sortDir === 'ASC' ? 'DESC' : 'ASC')}
                >
                  {sortDir === 'ASC' ? '↑' : '↓'}
                </Button>
                
                <Select value={String(limit)} onValueChange={(v) => setLimit(Number(v))}>
                  <SelectTrigger className="w-20">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="10">10</SelectItem>
                    <SelectItem value="25">25</SelectItem>
                    <SelectItem value="50">50</SelectItem>
                    <SelectItem value="100">100</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="flex justify-center py-8">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : domains.length === 0 ? (
              <div className="text-center py-8 text-muted-foreground">
                {searchQuery || filter !== 'all' 
                  ? 'Brak domen pasujących do kryteriów wyszukiwania.' 
                  : 'Brak domen. Dodaj pierwszą domenę do monitorowania.'}
              </div>
            ) : (
              <>
                <DomainTable 
                  domains={domains} 
                  onCheck={handleCheck} 
                  onDelete={handleDelete}
                  checkingId={checkingId}
                  formatDate={formatDate}
                  getExpiryBadge={getExpiryBadge}
                />
                
                {/* Pagination */}
                {totalPages > 1 && (
                  <div className="flex items-center justify-between mt-4 pt-4 border-t">
                    <p className="text-sm text-muted-foreground">
                      Wyświetlanie {(page - 1) * limit + 1} - {Math.min(page * limit, totalDomains)} z {totalDomains}
                    </p>
                    <div className="flex gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={page <= 1}
                        onClick={() => setPage(p => Math.max(1, p - 1))}
                      >
                        <ChevronLeft className="h-4 w-4" />
                        Poprzednia
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={page >= totalPages}
                        onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                      >
                        Następna
                        <ChevronRight className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </CardContent>
        </Card>
      </main>
    </div>
  );
}

interface DomainTableProps {
  domains: Domain[];
  onCheck: (id: number) => void;
  onDelete: (id: number) => void;
  checkingId: number | null;
  formatDate: (date: string | null) => string;
  getExpiryBadge: (domain: Domain) => React.ReactNode;
}

function DomainTable({ domains, onCheck, onDelete, checkingId, formatDate, getExpiryBadge }: DomainTableProps) {
  if (domains.length === 0) {
    return (
      <div className="text-center py-8 text-muted-foreground">
        Brak domen w tej kategorii
      </div>
    );
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Domena</TableHead>
          <TableHead>Status</TableHead>
          <TableHead>Data wygaśnięcia</TableHead>
          <TableHead>Ostatnie sprawdzenie</TableHead>
          <TableHead>Dodane przez</TableHead>
          <TableHead className="text-right">Akcje</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {domains.map((domain) => (
          <TableRow key={domain.id}>
            <TableCell className="font-medium">{domain.domain}</TableCell>
            <TableCell>{getExpiryBadge(domain)}</TableCell>
            <TableCell>{formatDate(domain.expiry_date)}</TableCell>
            <TableCell>{formatDate(domain.last_checked)}</TableCell>
            <TableCell className="text-muted-foreground">{domain.added_by_email || '-'}</TableCell>
            <TableCell className="text-right">
              <div className="flex justify-end gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onCheck(domain.id)}
                  disabled={checkingId === domain.id}
                >
                  {checkingId === domain.id ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <RefreshCw className="h-4 w-4" />
                  )}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDelete(domain.id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
