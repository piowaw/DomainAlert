import { useState, useEffect, useRef } from 'react';
import { 
  getConversations, 
  createConversation, 
  getConversation, 
  sendMessage, 
  deleteConversation,
  getAiStatus,
  getKnowledgeBase,
  addKnowledge,
  deleteKnowledge,
  installOllama,
  pullModel,
  restartOllama,
  stopOllama,
  removeOllama,
  getDockerStatus,
  type AiConversation,
  type AiMessage,
  type AiStatus,
  type KnowledgeEntry,
  type DockerStatus,
} from '@/lib/api';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import { 
  Brain, 
  Send, 
  Plus, 
  Trash2, 
  MessageSquare, 
  BookOpen, 
  Loader2,
  AlertCircle,
  CheckCircle,
  XCircle,
  Cpu,
  Database,
  Download,
  RefreshCw,
  Power,
  PowerOff,
  Terminal,
  Container,
  Play,
} from 'lucide-react';

export default function AiChatPage() {
  const { toast } = useToast();
  
  // AI Status
  const [aiStatus, setAiStatus] = useState<AiStatus | null>(null);
  
  // Conversations
  const [conversations, setConversations] = useState<AiConversation[]>([]);
  const [activeConversation, setActiveConversation] = useState<AiConversation | null>(null);
  const [messages, setMessages] = useState<AiMessage[]>([]);
  
  // Input
  const [userInput, setUserInput] = useState('');
  const [sending, setSending] = useState(false);
  const [newConvTitle, setNewConvTitle] = useState('');
  const [newConvDomain, setNewConvDomain] = useState('');
  
  // Knowledge Base
  const [knowledge, setKnowledge] = useState<KnowledgeEntry[]>([]);
  const [newKbContent, setNewKbContent] = useState('');
  const [newKbType, setNewKbType] = useState('note');
  const [newKbDomain, setNewKbDomain] = useState('');
  
  // AI Management
  const [mgmtLoading, setMgmtLoading] = useState<string | null>(null);
  const [mgmtOutput, setMgmtOutput] = useState<string | null>(null);
  const [dockerStatus, setDockerStatus] = useState<DockerStatus | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  
  useEffect(() => {
    loadStatus();
    loadConversations();
    loadKnowledge();
  }, []);
  
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const loadStatus = async () => {
    try {
      const status = await getAiStatus();
      setAiStatus(status);
    } catch { /* ignore */ }
    try {
      const ds = await getDockerStatus();
      setDockerStatus(ds);
    } catch { /* ignore */ }
  };

  const handleMgmtAction = async (action: string, fn: () => Promise<{ success: boolean; message: string; output: string }>) => {
    setMgmtLoading(action);
    setMgmtOutput(null);
    try {
      const result = await fn();
      setMgmtOutput(result.output);
      toast({ 
        title: result.success ? 'Sukces' : 'Błąd', 
        description: result.message,
        variant: result.success ? 'default' : 'destructive' 
      });
      await loadStatus();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Operacja nie powiodła się';
      setMgmtOutput(msg);
      toast({ title: 'Błąd', description: msg, variant: 'destructive' });
    } finally {
      setMgmtLoading(null);
    }
  };

  const loadConversations = async () => {
    try {
      const data = await getConversations();
      setConversations(data.conversations);
    } catch { /* ignore */ }
  };

  const loadKnowledge = async () => {
    try {
      const data = await getKnowledgeBase();
      setKnowledge(data.knowledge);
    } catch { /* ignore */ }
  };

  const openConversation = async (id: number) => {
    try {
      const data = await getConversation(id);
      setActiveConversation(data.conversation);
      setMessages(data.conversation.messages || []);
    } catch (err: unknown) {
      toast({ title: 'Błąd', description: err instanceof Error ? err.message : 'Nie udało się otworzyć rozmowy', variant: 'destructive' });
    }
  };

  const handleNewConversation = async () => {
    try {
      const title = newConvTitle.trim() || 'Nowa rozmowa';
      const domain = newConvDomain.trim() || undefined;
      const data = await createConversation(title, domain);
      setConversations([data.conversation, ...conversations]);
      setActiveConversation(data.conversation);
      setMessages([]);
      setNewConvTitle('');
      setNewConvDomain('');
    } catch (err: unknown) {
      toast({ title: 'Błąd', description: err instanceof Error ? err.message : 'Błąd tworzenia rozmowy', variant: 'destructive' });
    }
  };

  const handleDeleteConversation = async (id: number) => {
    try {
      await deleteConversation(id);
      setConversations(conversations.filter(c => c.id !== id));
      if (activeConversation?.id === id) {
        setActiveConversation(null);
        setMessages([]);
      }
    } catch { /* ignore */ }
  };

  const handleSendMessage = async () => {
    if (!userInput.trim() || !activeConversation || sending) return;
    
    const msg = userInput.trim();
    setUserInput('');
    setSending(true);
    
    // Optimistic update
    const tempUserMsg: AiMessage = {
      id: Date.now(),
      conversation_id: activeConversation.id,
      role: 'user',
      content: msg,
      created_at: new Date().toISOString(),
    };
    setMessages(prev => [...prev, tempUserMsg]);
    
    try {
      const data = await sendMessage(activeConversation.id, msg);
      setMessages(prev => [...prev, data.message as AiMessage]);
    } catch (err: unknown) {
      toast({ title: 'Błąd AI', description: err instanceof Error ? err.message : 'Nie udało się uzyskać odpowiedzi', variant: 'destructive' });
    } finally {
      setSending(false);
    }
  };

  const handleAddKnowledge = async () => {
    if (!newKbContent.trim()) return;
    
    try {
      const data = await addKnowledge(
        newKbContent.trim(),
        newKbType,
        newKbDomain.trim() || undefined,
      );
      setKnowledge([data.knowledge, ...knowledge]);
      setNewKbContent('');
      setNewKbDomain('');
      toast({ title: 'Dodano do bazy wiedzy' });
    } catch (err: unknown) {
      toast({ title: 'Błąd', description: err instanceof Error ? err.message : 'Błąd', variant: 'destructive' });
    }
  };

  const handleDeleteKnowledge = async (id: number) => {
    try {
      await deleteKnowledge(id);
      setKnowledge(knowledge.filter(k => k.id !== id));
    } catch { /* ignore */ }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2">
            <Brain className="h-6 w-6" /> Asystent AI
          </h1>
          <p className="text-muted-foreground">
            Rozmawiaj z AI o domenach, analizuj strony, wyceniaj domeny
          </p>
        </div>
        {aiStatus && (
          <Badge variant={aiStatus.ollama_running ? 'default' : 'destructive'} className="flex items-center gap-1">
            {aiStatus.ollama_running ? (
              <><CheckCircle className="h-3 w-3" /> Ollama aktywna · {aiStatus.model}</>
            ) : (
              <><XCircle className="h-3 w-3" /> Ollama niedostępna</>
            )}
          </Badge>
        )}
      </div>

      <Tabs defaultValue="chat" className="space-y-4">
        <TabsList>
          <TabsTrigger value="chat"><MessageSquare className="h-4 w-4 mr-1" /> Chat</TabsTrigger>
          <TabsTrigger value="knowledge"><BookOpen className="h-4 w-4 mr-1" /> Baza wiedzy</TabsTrigger>
          <TabsTrigger value="status"><Cpu className="h-4 w-4 mr-1" /> Status</TabsTrigger>
        </TabsList>

        {/* Chat Tab */}
        <TabsContent value="chat">
          <div className="grid grid-cols-1 lg:grid-cols-4 gap-4" style={{ height: 'calc(100vh - 280px)', minHeight: '500px' }}>
            {/* Conversations sidebar */}
            <Card className="lg:col-span-1">
              <CardHeader className="py-3 px-4">
                <CardTitle className="text-sm">Rozmowy</CardTitle>
              </CardHeader>
              <CardContent className="p-2 space-y-2">
                {/* New conversation */}
                <div className="space-y-1 p-2 border rounded-lg">
                  <Input
                    placeholder="Tytuł rozmowy..."
                    value={newConvTitle}
                    onChange={(e) => setNewConvTitle(e.target.value)}
                    className="h-8 text-xs"
                  />
                  <Input
                    placeholder="Domena (opcjonalnie)..."
                    value={newConvDomain}
                    onChange={(e) => setNewConvDomain(e.target.value)}
                    className="h-8 text-xs"
                  />
                  <Button size="sm" className="w-full h-8 text-xs" onClick={handleNewConversation}>
                    <Plus className="h-3 w-3 mr-1" /> Nowa rozmowa
                  </Button>
                </div>
                
                <ScrollArea className="h-[calc(100%-120px)]">
                  <div className="space-y-1">
                    {conversations.map(conv => (
                      <div 
                        key={conv.id}
                        className={`flex items-center justify-between p-2 rounded-lg cursor-pointer text-sm transition-colors ${
                          activeConversation?.id === conv.id 
                            ? 'bg-primary text-primary-foreground' 
                            : 'hover:bg-muted'
                        }`}
                        onClick={() => openConversation(conv.id)}
                      >
                        <div className="flex-1 min-w-0">
                          <p className="truncate font-medium text-xs">{conv.title}</p>
                          <p className={`text-xs truncate ${
                            activeConversation?.id === conv.id 
                              ? 'text-primary-foreground/70' 
                              : 'text-muted-foreground'
                          }`}>
                            {conv.domain && `🌐 ${conv.domain} · `}
                            {conv.message_count || 0} wiad.
                          </p>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="sm" 
                          className={`h-6 w-6 p-0 ${activeConversation?.id === conv.id ? 'text-primary-foreground/70 hover:text-primary-foreground' : ''}`}
                          onClick={(e) => { e.stopPropagation(); handleDeleteConversation(conv.id); }}
                        >
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </div>
                    ))}
                    {conversations.length === 0 && (
                      <p className="text-xs text-center text-muted-foreground py-4">
                        Brak rozmów. Stwórz nową!
                      </p>
                    )}
                  </div>
                </ScrollArea>
              </CardContent>
            </Card>

            {/* Chat area */}
            <Card className="lg:col-span-3 flex flex-col">
              {activeConversation ? (
                <>
                  <CardHeader className="py-3 px-4 border-b">
                    <div className="flex items-center justify-between">
                      <div>
                        <CardTitle className="text-sm">{activeConversation.title}</CardTitle>
                        {activeConversation.domain && (
                          <CardDescription className="text-xs">
                            🌐 {activeConversation.domain}
                          </CardDescription>
                        )}
                      </div>
                    </div>
                  </CardHeader>
                  
                  <CardContent className="flex-1 p-0 overflow-hidden">
                    <ScrollArea className="h-full p-4">
                      <div className="space-y-4">
                        {messages.length === 0 && (
                          <div className="text-center py-12 text-muted-foreground">
                            <Brain className="h-12 w-12 mx-auto mb-3 opacity-20" />
                            <p className="text-sm">Rozpocznij rozmowę z AI</p>
                            <p className="text-xs mt-1">Zapytaj o domenę, wycenę, analizę...</p>
                          </div>
                        )}
                        
                        {messages.map((msg) => (
                          <div 
                            key={msg.id}
                            className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                          >
                            <div className={`max-w-[80%] rounded-lg p-3 ${
                              msg.role === 'user' 
                                ? 'bg-primary text-primary-foreground' 
                                : 'bg-muted'
                            }`}>
                              <p className="text-sm whitespace-pre-wrap">{msg.content}</p>
                              <p className={`text-xs mt-1 ${
                                msg.role === 'user' ? 'text-primary-foreground/60' : 'text-muted-foreground'
                              }`}>
                                {msg.role === 'user' ? 'Ty' : 'AI'} · {new Date(msg.created_at).toLocaleTimeString('pl')}
                              </p>
                            </div>
                          </div>
                        ))}
                        
                        {sending && (
                          <div className="flex justify-start">
                            <div className="bg-muted rounded-lg p-3 flex items-center gap-2">
                              <Loader2 className="h-4 w-4 animate-spin" />
                              <span className="text-sm text-muted-foreground">AI myśli...</span>
                            </div>
                          </div>
                        )}
                        
                        <div ref={messagesEndRef} />
                      </div>
                    </ScrollArea>
                  </CardContent>
                  
                  <div className="p-4 border-t">
                    <div className="flex gap-2">
                      <Textarea
                        placeholder="Napisz wiadomość... (Enter = wyślij, Shift+Enter = nowa linia)"
                        value={userInput}
                        onChange={(e) => setUserInput(e.target.value)}
                        onKeyDown={handleKeyDown}
                        className="min-h-[44px] max-h-[120px] resize-none"
                        rows={1}
                        disabled={sending}
                      />
                      <Button 
                        onClick={handleSendMessage} 
                        disabled={sending || !userInput.trim()}
                        className="px-4"
                      >
                        {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                      </Button>
                    </div>
                  </div>
                </>
              ) : (
                <CardContent className="flex-1 flex items-center justify-center">
                  <div className="text-center text-muted-foreground">
                    <MessageSquare className="h-16 w-16 mx-auto mb-4 opacity-20" />
                    <p className="text-lg font-medium mb-2">Wybierz lub utwórz rozmowę</p>
                    <p className="text-sm">
                      Stwórz nową rozmowę z panelu po lewej stronie
                    </p>
                  </div>
                </CardContent>
              )}
            </Card>
          </div>
        </TabsContent>

        {/* Knowledge Base Tab */}
        <TabsContent value="knowledge">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Add knowledge */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <Plus className="h-4 w-4" /> Dodaj wiedzę
                </CardTitle>
                <CardDescription>
                  Dodaj informacje do wspólnej bazy wiedzy AI
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div>
                  <label className="text-xs text-muted-foreground">Typ</label>
                  <Select value={newKbType} onValueChange={setNewKbType}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="note">Notatka</SelectItem>
                      <SelectItem value="fact">Fakt</SelectItem>
                      <SelectItem value="rule">Reguła wyceny</SelectItem>
                      <SelectItem value="contact">Kontakt</SelectItem>
                      <SelectItem value="market">Info rynkowe</SelectItem>
                      <SelectItem value="history">Historia</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">Domena (opcjonalnie)</label>
                  <Input
                    placeholder="np. example.com"
                    value={newKbDomain}
                    onChange={(e) => setNewKbDomain(e.target.value)}
                  />
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">Treść</label>
                  <Textarea
                    placeholder="Wpisz informację, którą AI ma zapamiętać..."
                    value={newKbContent}
                    onChange={(e) => setNewKbContent(e.target.value)}
                    rows={4}
                  />
                </div>
                <Button className="w-full" onClick={handleAddKnowledge} disabled={!newKbContent.trim()}>
                  <Database className="h-4 w-4 mr-2" /> Dodaj do bazy
                </Button>
              </CardContent>
            </Card>

            {/* Knowledge list */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <BookOpen className="h-4 w-4" /> Baza wiedzy
                  <Badge variant="secondary">{knowledge.length}</Badge>
                </CardTitle>
                <CardDescription>
                  Wspólna baza wiedzy używana przez AI we wszystkich rozmowach
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ScrollArea className="h-96">
                  <div className="space-y-3">
                    {knowledge.map(entry => (
                      <div key={entry.id} className="border rounded-lg p-3">
                        <div className="flex items-start justify-between">
                          <div className="flex items-center gap-2 mb-2">
                            <Badge variant="outline" className="text-xs">{entry.type}</Badge>
                            {entry.domain && (
                              <Badge variant="secondary" className="text-xs">🌐 {entry.domain}</Badge>
                            )}
                            <span className="text-xs text-muted-foreground">
                              {new Date(entry.created_at).toLocaleDateString('pl')}
                            </span>
                          </div>
                          <Button 
                            variant="ghost" 
                            size="sm" 
                            className="h-6 w-6 p-0 text-muted-foreground hover:text-destructive"
                            onClick={() => handleDeleteKnowledge(entry.id)}
                          >
                            <Trash2 className="h-3 w-3" />
                          </Button>
                        </div>
                        <p className="text-sm">{entry.content}</p>
                      </div>
                    ))}
                    {knowledge.length === 0 && (
                      <div className="text-center py-8 text-muted-foreground">
                        <Database className="h-8 w-8 mx-auto mb-2 opacity-20" />
                        <p className="text-sm">Baza wiedzy jest pusta</p>
                        <p className="text-xs mt-1">Dodaj informacje, które AI będzie wykorzystywać</p>
                      </div>
                    )}
                  </div>
                </ScrollArea>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Status Tab */}
        <TabsContent value="status">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <Cpu className="h-4 w-4" /> Status Ollama
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center gap-2">
                  {aiStatus?.ollama_running ? (
                    <><CheckCircle className="h-5 w-5 text-green-500" /> <span className="font-medium">Aktywna</span></>
                  ) : (
                    <><XCircle className="h-5 w-5 text-red-500" /> <span className="font-medium">Niedostępna</span></>
                  )}
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">URL</p>
                  <p className="text-sm font-mono">{aiStatus?.ollama_url || '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Aktywny model</p>
                  <p className="text-sm font-mono">{aiStatus?.model || '—'}</p>
                </div>
                {aiStatus?.error && (
                  <div className="flex items-center gap-2 text-destructive text-sm">
                    <AlertCircle className="h-4 w-4" />
                    <span>{aiStatus.error}</span>
                  </div>
                )}
                <Button variant="outline" size="sm" onClick={loadStatus}>
                  Odśwież status
                </Button>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <Container className="h-4 w-4" /> Docker
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center gap-2">
                  {dockerStatus?.docker_available ? (
                    <><CheckCircle className="h-5 w-5 text-green-500" /> <span className="font-medium">Docker dostępny</span></>
                  ) : (
                    <><XCircle className="h-5 w-5 text-red-500" /> <span className="font-medium">Docker niedostępny</span></>
                  )}
                </div>
                {dockerStatus?.docker_version && (
                  <div>
                    <p className="text-xs text-muted-foreground">Wersja</p>
                    <p className="text-sm font-mono">{dockerStatus.docker_version}</p>
                  </div>
                )}
                {dockerStatus?.container ? (
                  <div>
                    <p className="text-xs text-muted-foreground">Kontener Ollama</p>
                    <div className="flex items-center gap-2 mt-1">
                      <Badge variant={dockerStatus.container.status.includes('Up') ? 'default' : 'outline'} className="text-xs">
                        {dockerStatus.container.status}
                      </Badge>
                    </div>
                    <p className="text-xs font-mono mt-1 text-muted-foreground">{dockerStatus.container.image}</p>
                  </div>
                ) : (
                  <div>
                    <p className="text-xs text-muted-foreground">Kontener Ollama</p>
                    <p className="text-sm">Nie utworzony</p>
                  </div>
                )}
                {dockerStatus?.volume_exists && (
                  <Badge variant="outline" className="text-xs">Volume: ollama_data</Badge>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Dostępne modele</CardTitle>
              </CardHeader>
              <CardContent>
                {aiStatus?.models_available && aiStatus.models_available.length > 0 ? (
                  <div className="space-y-2">
                    {aiStatus.models_available.map((model, i) => (
                      <div key={i} className="flex items-center gap-2">
                        <Badge variant={model === aiStatus.model ? 'default' : 'outline'}>
                          {model}
                        </Badge>
                        {model === aiStatus.model && (
                          <span className="text-xs text-muted-foreground">(aktywny)</span>
                        )}
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">Brak modeli lub Ollama nie jest uruchomiona</p>
                )}
              </CardContent>
            </Card>

            {/* Management Panel */}
            <Card className="md:col-span-2">
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <Power className="h-4 w-4" /> Zarządzanie kontenerem Ollama
                </CardTitle>
                <CardDescription>Uruchamianie, restart i zarządzanie Ollama przez Docker na serwerze</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                  {/* Start/Install Container */}
                  <Button 
                    variant={aiStatus?.ollama_running ? "outline" : "default"}
                    className="h-auto py-3 flex flex-col items-center gap-1"
                    disabled={mgmtLoading !== null}
                    onClick={() => handleMgmtAction('install', installOllama)}
                  >
                    {mgmtLoading === 'install' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <Play className="h-5 w-5" />
                    )}
                    <span className="text-sm font-medium">Uruchom Ollama</span>
                    <span className="text-xs text-muted-foreground font-normal">Docker container</span>
                  </Button>

                  {/* Pull Model */}
                  <Button 
                    variant="outline"
                    className="h-auto py-3 flex flex-col items-center gap-1"
                    disabled={mgmtLoading !== null}
                    onClick={() => handleMgmtAction('pull', () => pullModel())}
                  >
                    {mgmtLoading === 'pull' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <Download className="h-5 w-5" />
                    )}
                    <span className="text-sm font-medium">Pobierz model</span>
                    <span className="text-xs text-muted-foreground font-normal">{aiStatus?.model || 'deepseek-r1:1.5b'}</span>
                  </Button>

                  {/* Restart Container */}
                  <Button 
                    variant="outline"
                    className="h-auto py-3 flex flex-col items-center gap-1"
                    disabled={mgmtLoading !== null}
                    onClick={() => handleMgmtAction('restart', restartOllama)}
                  >
                    {mgmtLoading === 'restart' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <RefreshCw className="h-5 w-5" />
                    )}
                    <span className="text-sm font-medium">Restart</span>
                    <span className="text-xs text-muted-foreground font-normal">Uruchom ponownie</span>
                  </Button>

                  {/* Stop Container */}
                  <Button 
                    variant="outline"
                    className="h-auto py-3 flex flex-col items-center gap-1"
                    disabled={mgmtLoading !== null || !aiStatus?.ollama_running}
                    onClick={() => handleMgmtAction('stop', stopOllama)}
                  >
                    {mgmtLoading === 'stop' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <PowerOff className="h-5 w-5" />
                    )}
                    <span className="text-sm font-medium">Zatrzymaj</span>
                    <span className="text-xs text-muted-foreground font-normal">Stop kontener</span>
                  </Button>

                  {/* Remove Container */}
                  <Button 
                    variant="destructive"
                    className="h-auto py-3 flex flex-col items-center gap-1"
                    disabled={mgmtLoading !== null}
                    onClick={() => handleMgmtAction('remove', removeOllama)}
                  >
                    {mgmtLoading === 'remove' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <Trash2 className="h-5 w-5" />
                    )}
                    <span className="text-sm font-medium">Usuń kontener</span>
                    <span className="text-xs font-normal opacity-80">Reinstalacja</span>
                  </Button>
                </div>

                {/* Output Console */}
                {mgmtOutput && (
                  <div className="space-y-2">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                      <Terminal className="h-4 w-4" />
                      <span>Wynik operacji</span>
                    </div>
                    <div className="bg-zinc-950 text-green-400 rounded-lg p-4 font-mono text-xs max-h-48 overflow-y-auto whitespace-pre-wrap">
                      {mgmtOutput}
                    </div>
                  </div>
                )}

                {mgmtLoading && (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    <span>
                      {mgmtLoading === 'install' && 'Uruchamianie kontenera Ollama... (może potrwać kilka minut przy pierwszym uruchomieniu)'}
                      {mgmtLoading === 'pull' && 'Pobieranie modelu w kontenerze... (może potrwać kilka minut)'}
                      {mgmtLoading === 'restart' && 'Restartowanie kontenera...'}
                      {mgmtLoading === 'stop' && 'Zatrzymywanie kontenera...'}
                      {mgmtLoading === 'remove' && 'Usuwanie kontenera...'}
                    </span>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Portainer Instructions */}
            <Card className="md:col-span-2">
              <CardHeader>
                <CardTitle className="text-lg">Instrukcja Portainer / Docker CLI</CardTitle>
                <CardDescription>Jak ręcznie skonfigurować Ollama przez Portainer lub terminal</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <h4 className="font-medium text-sm mb-2">🐳 Przez Portainer (GUI)</h4>
                  <ol className="text-sm space-y-2 list-decimal pl-5 text-muted-foreground">
                    <li>Otwórz <strong>Portainer</strong> → <strong>Containers</strong> → <strong>Add Container</strong></li>
                    <li>Name: <code className="text-xs bg-muted px-1 rounded">ollama</code></li>
                    <li>Image: <code className="text-xs bg-muted px-1 rounded">ollama/ollama:latest</code></li>
                    <li>Port mapping: <code className="text-xs bg-muted px-1 rounded">11434 → 11434</code></li>
                    <li>Volumes: <code className="text-xs bg-muted px-1 rounded">ollama_data → /root/.ollama</code></li>
                    <li>Restart policy: <code className="text-xs bg-muted px-1 rounded">Unless stopped</code></li>
                    <li>Kliknij <strong>Deploy the container</strong></li>
                    <li>Po starcie przejdź do <strong>Console</strong> kontenera i wykonaj: <code className="text-xs bg-muted px-1 rounded">ollama pull deepseek-r1:1.5b</code></li>
                  </ol>
                </div>
                <div>
                  <h4 className="font-medium text-sm mb-2">💻 Przez terminal (Docker CLI)</h4>
                  <div className="bg-muted rounded-lg p-4 font-mono text-sm space-y-1">
                    <p className="text-muted-foreground"># 1. Uruchom kontener Ollama</p>
                    <p>docker run -d -v ollama_data:/root/.ollama \</p>
                    <p>  -p 11434:11434 --name ollama \</p>
                    <p>  --restart unless-stopped ollama/ollama</p>
                    <br />
                    <p className="text-muted-foreground"># 2. Pobierz model AI</p>
                    <p>docker exec ollama ollama pull deepseek-r1:1.5b</p>
                    <br />
                    <p className="text-muted-foreground"># 3. Sprawdź czy działa</p>
                    <p>curl http://localhost:11434/api/tags</p>
                    <br />
                    <p className="text-muted-foreground"># Zarządzanie kontenerem</p>
                    <p>docker stop ollama    # Zatrzymaj</p>
                    <p>docker start ollama   # Uruchom</p>
                    <p>docker restart ollama # Restart</p>
                    <p>docker logs ollama    # Logi</p>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground">
                  Model DeepSeek R1 1.5B wymaga ~1.5 GB RAM. Dane modeli są przechowywane
                  w Docker volume <code className="text-xs bg-muted px-1 rounded">ollama_data</code>, więc przetrwają restart kontenera.
                  Dla lepszych wyników użyj <code className="text-xs bg-muted px-1 rounded">deepseek-r1:7b</code> (wymaga ~8 GB RAM).
                </p>
              </CardContent>
            </Card>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
