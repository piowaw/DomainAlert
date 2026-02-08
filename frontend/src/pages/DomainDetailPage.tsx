import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { getDomainDetails, type Domain, type DomainDetails } from '@/lib/api';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useToast } from '@/hooks/use-toast';
import { 
  ArrowLeft, 
  RefreshCw, 
  Globe, 
  Shield, 
  Server, 
  Search, 
  Brain,
  Mail,
  Phone,
  ExternalLink,
  Lock,
  FileText,
  Cpu,
  Share2,
  ShoppingCart,
  Loader2,
  AlertCircle,
  CheckCircle,
  XCircle,
  Copy,
} from 'lucide-react';

export default function DomainDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { toast } = useToast();
  
  const [domain, setDomain] = useState<Domain | null>(null);
  const [details, setDetails] = useState<DomainDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [cached, setCached] = useState(false);

  const loadDetails = async (refresh = false) => {
    if (!id) return;
    
    if (refresh) setRefreshing(true);
    else setLoading(true);
    
    try {
      const result = await getDomainDetails(parseInt(id), refresh);
      setDomain(result.domain);
      setDetails(result.details);
      setCached(result.cached);
    } catch (err: unknown) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się pobrać szczegółów domeny',
        variant: 'destructive',
      });
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    loadDetails();
  }, [id]);

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast({ title: 'Skopiowano do schowka' });
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center py-20 gap-4">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
        <p className="text-muted-foreground">Analizuję domenę... (WHOIS, scraping, Google, DNS, AI)</p>
        <p className="text-xs text-muted-foreground">To może potrwać do 30 sekund przy pierwszym skanowaniu</p>
      </div>
    );
  }

  if (!domain || !details) {
    return (
      <div className="text-center py-20">
        <AlertCircle className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
        <h2 className="text-xl font-bold mb-2">Nie znaleziono domeny</h2>
        <Button onClick={() => navigate('/')}>Powrót do listy</Button>
      </div>
    );
  }

  const scrape = details.scrape_data;
  const google = details.google_data;
  const whoisParsed = details.whois_parsed;
  const dns = details.dns_records;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate('/')}>
          <ArrowLeft className="h-4 w-4 mr-1" /> Powrót
        </Button>
        <div className="flex-1">
          <div className="flex items-center gap-3">
            <Globe className="h-6 w-6 text-primary" />
            <h1 className="text-2xl font-bold">{domain.domain}</h1>
            <Badge variant={domain.is_registered ? 'default' : 'destructive'}>
              {domain.is_registered ? 'Zarejestrowana' : 'Wolna'}
            </Badge>
            {cached && (
              <Badge variant="outline" className="text-xs">
                Z cache ({details.scraped_at})
              </Badge>
            )}
          </div>
        </div>
        <Button 
          variant="outline" 
          onClick={() => loadDetails(true)} 
          disabled={refreshing}
        >
          <RefreshCw className={`h-4 w-4 mr-2 ${refreshing ? 'animate-spin' : ''}`} />
          {refreshing ? 'Odświeżam...' : 'Odśwież dane'}
        </Button>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Card>
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-2">
              <Shield className="h-4 w-4 text-blue-500" />
              <div>
                <p className="text-xs text-muted-foreground">Status</p>
                <p className="font-semibold text-sm">{domain.is_registered ? 'Zajęta' : 'Wolna'}</p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-2">
              <Lock className="h-4 w-4 text-green-500" />
              <div>
                <p className="text-xs text-muted-foreground">SSL</p>
                <p className="font-semibold text-sm">
                  {scrape?.ssl_valid ? 'Ważny' : 'Brak/Nieważny'}
                  {scrape?.ssl_expiry && ` (do ${scrape.ssl_expiry})`}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-2">
              <FileText className="h-4 w-4 text-purple-500" />
              <div>
                <p className="text-xs text-muted-foreground">Wygasa</p>
                <p className="font-semibold text-sm">{domain.expiry_date || 'Brak danych'}</p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-2">
              <Server className="h-4 w-4 text-orange-500" />
              <div>
                <p className="text-xs text-muted-foreground">HTTP</p>
                <p className="font-semibold text-sm">
                  {scrape?.status_code || '—'}
                  {scrape?.server && ` · ${scrape.server}`}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* For Sale Warning */}
      {scrape?.for_sale_indicators && scrape.for_sale_indicators.length > 0 && (
        <Card className="border-yellow-500 bg-yellow-50 dark:bg-yellow-950">
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-3">
              <ShoppingCart className="h-5 w-5 text-yellow-600" />
              <div>
                <p className="font-semibold text-yellow-800 dark:text-yellow-200">
                  Domena może być na sprzedaż!
                </p>
                <p className="text-sm text-yellow-700 dark:text-yellow-300">
                  Wykryto wskaźniki: {scrape.for_sale_indicators.join(', ')}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Main Content Tabs */}
      <Tabs defaultValue="ai" className="space-y-4">
        <TabsList className="flex flex-wrap h-auto gap-1">
          <TabsTrigger value="ai"><Brain className="h-4 w-4 mr-1" /> Analiza AI</TabsTrigger>
          <TabsTrigger value="website"><Globe className="h-4 w-4 mr-1" /> Strona WWW</TabsTrigger>
          <TabsTrigger value="whois"><Shield className="h-4 w-4 mr-1" /> WHOIS</TabsTrigger>
          <TabsTrigger value="dns"><Server className="h-4 w-4 mr-1" /> DNS</TabsTrigger>
          <TabsTrigger value="google"><Search className="h-4 w-4 mr-1" /> Google</TabsTrigger>
        </TabsList>

        {/* AI Analysis Tab */}
        <TabsContent value="ai">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Brain className="h-5 w-5" /> Analiza AI
              </CardTitle>
              <CardDescription>
                Automatyczna analiza domeny przez lokalny model AI
              </CardDescription>
            </CardHeader>
            <CardContent>
              {details.ai_analysis ? (
                <div className="prose dark:prose-invert max-w-none text-sm whitespace-pre-wrap">
                  {details.ai_analysis}
                </div>
              ) : (
                <div className="text-center py-8 text-muted-foreground">
                  <AlertCircle className="h-8 w-8 mx-auto mb-3" />
                  <p>Analiza AI niedostępna.</p>
                  <p className="text-xs mt-1">Upewnij się, że Ollama jest uruchomiona na serwerze.</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Website Tab */}
        <TabsContent value="website" className="space-y-4">
          {scrape?.error ? (
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center gap-2 text-destructive">
                  <XCircle className="h-5 w-5" />
                  <span>{scrape.error}</span>
                </div>
              </CardContent>
            </Card>
          ) : (
            <>
              {/* Basic Info */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">Informacje o stronie</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  {scrape?.title && (
                    <div>
                      <p className="text-xs text-muted-foreground">Tytuł</p>
                      <p className="font-medium">{scrape.title}</p>
                    </div>
                  )}
                  {scrape?.description && (
                    <div>
                      <p className="text-xs text-muted-foreground">Opis</p>
                      <p className="text-sm">{scrape.description}</p>
                    </div>
                  )}
                  {scrape?.keywords && (
                    <div>
                      <p className="text-xs text-muted-foreground">Słowa kluczowe</p>
                      <p className="text-sm">{scrape.keywords}</p>
                    </div>
                  )}
                  {scrape?.h1 && scrape.h1.length > 0 && (
                    <div>
                      <p className="text-xs text-muted-foreground">Nagłówki H1</p>
                      {scrape.h1.map((h, i) => <p key={i} className="text-sm">• {h}</p>)}
                    </div>
                  )}
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4 pt-2">
                    <div>
                      <p className="text-xs text-muted-foreground">Linki</p>
                      <p className="font-semibold">{scrape?.links_count ?? 0}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">Obrazy</p>
                      <p className="font-semibold">{scrape?.images_count ?? 0}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">Język</p>
                      <p className="font-semibold">{scrape?.language || '—'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">Przekierowanie</p>
                      <p className="font-semibold text-xs truncate">{scrape?.redirect_url || 'Brak'}</p>
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Technologies */}
              {scrape?.technologies && scrape.technologies.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle className="text-lg flex items-center gap-2">
                      <Cpu className="h-4 w-4" /> Technologie
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-2">
                      {scrape.technologies.map(tech => (
                        <Badge key={tech} variant="secondary">{tech}</Badge>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}

              {/* Contact & Social */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Emails & Phones */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-lg flex items-center gap-2">
                      <Mail className="h-4 w-4" /> Kontakt
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {scrape?.emails && scrape.emails.length > 0 ? (
                      scrape.emails.map((email, i) => (
                        <div key={i} className="flex items-center gap-2">
                          <Mail className="h-3 w-3 text-muted-foreground" />
                          <span className="text-sm">{email}</span>
                          <Button variant="ghost" size="sm" className="h-6 w-6 p-0" onClick={() => copyToClipboard(email)}>
                            <Copy className="h-3 w-3" />
                          </Button>
                        </div>
                      ))
                    ) : (
                      <p className="text-sm text-muted-foreground">Brak emaili</p>
                    )}
                    {scrape?.phones && scrape.phones.length > 0 && (
                      <>
                        <hr className="my-2" />
                        {scrape.phones.map((phone, i) => (
                          <div key={i} className="flex items-center gap-2">
                            <Phone className="h-3 w-3 text-muted-foreground" />
                            <span className="text-sm">{phone}</span>
                          </div>
                        ))}
                      </>
                    )}
                  </CardContent>
                </Card>

                {/* Social Links */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-lg flex items-center gap-2">
                      <Share2 className="h-4 w-4" /> Social Media
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {scrape?.social_links && scrape.social_links.length > 0 ? (
                      scrape.social_links.map((link, i) => (
                        <a 
                          key={i} 
                          href={link.url} 
                          target="_blank" 
                          rel="noopener noreferrer"
                          className="flex items-center gap-2 text-sm text-primary hover:underline"
                        >
                          <ExternalLink className="h-3 w-3" />
                          <Badge variant="outline" className="text-xs">{link.platform}</Badge>
                          <span className="truncate">{link.url}</span>
                        </a>
                      ))
                    ) : (
                      <p className="text-sm text-muted-foreground">Nie znaleziono profili</p>
                    )}
                  </CardContent>
                </Card>
              </div>

              {/* Page Content Preview */}
              {scrape?.text_content && (
                <Card>
                  <CardHeader>
                    <CardTitle className="text-lg">Treść strony (fragment)</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <ScrollArea className="h-48">
                      <p className="text-sm text-muted-foreground whitespace-pre-wrap">
                        {scrape.text_content}
                      </p>
                    </ScrollArea>
                  </CardContent>
                </Card>
              )}
            </>
          )}
        </TabsContent>

        {/* WHOIS Tab */}
        <TabsContent value="whois">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Shield className="h-5 w-5" /> Dane WHOIS
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground">Status</p>
                  <div className="flex items-center gap-1">
                    {whoisParsed?.is_registered ? (
                      <><CheckCircle className="h-4 w-4 text-green-500" /> <span>Zarejestrowana</span></>
                    ) : (
                      <><XCircle className="h-4 w-4 text-red-500" /> <span>Wolna</span></>
                    )}
                  </div>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Data wygaśnięcia</p>
                  <p className="font-semibold">{whoisParsed?.expiry_date || 'Brak danych'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Rejestrator</p>
                  <p className="font-semibold">{whoisParsed?.registrar || 'Brak danych'}</p>
                </div>
              </div>
              
              <div>
                <p className="text-xs text-muted-foreground mb-2">Surowe dane WHOIS</p>
                <ScrollArea className="h-80">
                  <pre className="text-xs p-4 bg-muted rounded-lg whitespace-pre-wrap font-mono">
                    {details.whois_raw || 'Brak danych WHOIS'}
                  </pre>
                </ScrollArea>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* DNS Tab */}
        <TabsContent value="dns">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Server className="h-5 w-5" /> Rekordy DNS
              </CardTitle>
            </CardHeader>
            <CardContent>
              {dns && dns.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b">
                        <th className="text-left py-2 px-2 font-medium">Typ</th>
                        <th className="text-left py-2 px-2 font-medium">Host</th>
                        <th className="text-left py-2 px-2 font-medium">Wartość</th>
                        <th className="text-left py-2 px-2 font-medium">TTL</th>
                        <th className="text-left py-2 px-2 font-medium">Priorytet</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dns.map((record, i) => (
                        <tr key={i} className="border-b">
                          <td className="py-2 px-2">
                            <Badge variant="outline" className="text-xs">{record.type}</Badge>
                          </td>
                          <td className="py-2 px-2 text-xs">{record.host}</td>
                          <td className="py-2 px-2 text-xs font-mono break-all">{record.value}</td>
                          <td className="py-2 px-2 text-xs">{record.ttl}s</td>
                          <td className="py-2 px-2 text-xs">{record.priority ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-muted-foreground">Brak rekordów DNS</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Google Tab */}
        <TabsContent value="google">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Search className="h-5 w-5" /> Wyniki Google
              </CardTitle>
              <CardDescription>
                Znaleziono ~{google?.total_results || 0} wyników
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {google?.error && (
                <div className="flex items-center gap-2 text-yellow-600 text-sm">
                  <AlertCircle className="h-4 w-4" />
                  <span>{google.error}</span>
                </div>
              )}
              {google?.results && google.results.length > 0 ? (
                google.results.map((result, i) => (
                  <div key={i} className="border rounded-lg p-3 space-y-1">
                    <a 
                      href={result.url} 
                      target="_blank" 
                      rel="noopener noreferrer"
                      className="text-primary hover:underline font-medium text-sm flex items-center gap-1"
                    >
                      {result.title}
                      <ExternalLink className="h-3 w-3" />
                    </a>
                    <p className="text-xs text-green-700 dark:text-green-400 truncate">{result.url}</p>
                    <p className="text-sm text-muted-foreground">{result.snippet}</p>
                  </div>
                ))
              ) : (
                <p className="text-muted-foreground">Brak wyników Google</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
