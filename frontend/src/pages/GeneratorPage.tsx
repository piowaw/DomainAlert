import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { importDomainsInBatches } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ArrowLeft, Wand2, Plus, Copy, Loader2, Check, Shuffle, Filter, Download } from 'lucide-react';

// TLD options
const TLDS = [
  { value: 'com', label: '.com', popular: true },
  { value: 'net', label: '.net', popular: true },
  { value: 'org', label: '.org', popular: true },
  { value: 'io', label: '.io', popular: true },
  { value: 'co', label: '.co', popular: true },
  { value: 'ai', label: '.ai', popular: true },
  { value: 'app', label: '.app', popular: true },
  { value: 'dev', label: '.dev', popular: true },
  { value: 'pl', label: '.pl', popular: true },
  { value: 'eu', label: '.eu', popular: false },
  { value: 'de', label: '.de', popular: false },
  { value: 'uk', label: '.uk', popular: false },
  { value: 'fr', label: '.fr', popular: false },
  { value: 'it', label: '.it', popular: false },
  { value: 'es', label: '.es', popular: false },
  { value: 'nl', label: '.nl', popular: false },
  { value: 'xyz', label: '.xyz', popular: false },
  { value: 'info', label: '.info', popular: false },
  { value: 'biz', label: '.biz', popular: false },
  { value: 'me', label: '.me', popular: false },
  { value: 'tv', label: '.tv', popular: false },
  { value: 'cc', label: '.cc', popular: false },
  { value: 'ws', label: '.ws', popular: false },
  { value: 'mobi', label: '.mobi', popular: false },
  { value: 'pro', label: '.pro', popular: false },
  { value: 'tech', label: '.tech', popular: false },
  { value: 'online', label: '.online', popular: false },
  { value: 'store', label: '.store', popular: false },
  { value: 'shop', label: '.shop', popular: false },
  { value: 'blog', label: '.blog', popular: false },
];

const CONSONANTS = 'bcdfghjklmnpqrstvwxyz';
const VOWELS = 'aeiou';
const LETTERS = 'abcdefghijklmnopqrstuvwxyz';
const NUMBERS = '0123456789';

export default function GeneratorPage() {
  const { user } = useAuth();
  
  // Generator settings
  const [activeTab, setActiveTab] = useState('length');
  const [selectedTlds, setSelectedTlds] = useState<string[]>(['com']);
  const [generatedDomains, setGeneratedDomains] = useState<string[]>([]);
  const [selectedDomains, setSelectedDomains] = useState<Set<string>>(new Set());
  const [importing, setImporting] = useState(false);
  const [importSuccess, setImportSuccess] = useState(false);
  const [importProgress, setImportProgress] = useState<{ current: number; total: number } | null>(null);
  const [importResult, setImportResult] = useState<{ imported: number; errors: number } | null>(null);
  
  // Length-based generator
  const [domainLength, setDomainLength] = useState(4);
  const [charType, setCharType] = useState<'letters' | 'alphanumeric' | 'numbers'>('letters');
  const [generateCount, setGenerateCount] = useState(100);
  
  // Pattern-based generator
  const [pattern, setPattern] = useState('CVCV'); // C=consonant, V=vowel, L=letter, N=number
  
  // Keyword-based generator
  const [keyword, setKeyword] = useState('');
  const [keywordPosition, setKeywordPosition] = useState<'prefix' | 'suffix' | 'both'>('prefix');
  const [keywordAdditions, setKeywordAdditions] = useState<string[]>(['app', 'hub', 'lab', 'io', 'pro', 'go', 'my', 'get', 'the', 'try']);
  
  // Dictionary combinations
  const [wordList1, setWordList1] = useState('');
  const [wordList2, setWordList2] = useState('');
  const [separator, setSeparator] = useState('');
  
  // Custom list
  const [customList, setCustomList] = useState('');
  
  // Pronounceable
  const [pronounceableLength, setPronounceableLength] = useState(5);
  const [pronounceableCount, setPronounceableCount] = useState(100);
  
  // Number domains
  const [numberLength, setNumberLength] = useState(3);
  const [numberPrefix, setNumberPrefix] = useState('');
  const [numberSuffix, setNumberSuffix] = useState('');

  // Toggle TLD selection
  const toggleTld = (tld: string) => {
    setSelectedTlds(prev => 
      prev.includes(tld) 
        ? prev.filter(t => t !== tld)
        : [...prev, tld]
    );
  };

  // Generate random string
  const randomString = (length: number, chars: string): string => {
    let result = '';
    for (let i = 0; i < length; i++) {
      result += chars[Math.floor(Math.random() * chars.length)];
    }
    return result;
  };

  // Generate from pattern
  const generateFromPattern = (pat: string): string => {
    let result = '';
    for (const char of pat.toUpperCase()) {
      switch (char) {
        case 'C': result += CONSONANTS[Math.floor(Math.random() * CONSONANTS.length)]; break;
        case 'V': result += VOWELS[Math.floor(Math.random() * VOWELS.length)]; break;
        case 'L': result += LETTERS[Math.floor(Math.random() * LETTERS.length)]; break;
        case 'N': result += NUMBERS[Math.floor(Math.random() * NUMBERS.length)]; break;
        default: result += char.toLowerCase();
      }
    }
    return result;
  };

  // Generate pronounceable domain
  const generatePronounceable = (length: number): string => {
    let result = '';
    let lastWasVowel = Math.random() > 0.5;
    
    for (let i = 0; i < length; i++) {
      if (lastWasVowel) {
        // Add consonant, sometimes double
        result += CONSONANTS[Math.floor(Math.random() * CONSONANTS.length)];
        if (i < length - 2 && Math.random() > 0.8) {
          result += CONSONANTS[Math.floor(Math.random() * CONSONANTS.length)];
          i++;
        }
      } else {
        result += VOWELS[Math.floor(Math.random() * VOWELS.length)];
      }
      lastWasVowel = !lastWasVowel;
    }
    
    return result.substring(0, length);
  };

  // Add TLDs to domains
  const addTlds = (names: string[]): string[] => {
    const result: string[] = [];
    for (const name of names) {
      for (const tld of selectedTlds) {
        result.push(`${name}.${tld}`);
      }
    }
    return result;
  };

  // Generate by length
  const generateByLength = () => {
    const chars = charType === 'letters' ? LETTERS : 
                  charType === 'numbers' ? NUMBERS : 
                  LETTERS + NUMBERS;
    
    const names = new Set<string>();
    let attempts = 0;
    const maxAttempts = generateCount * 10;
    
    while (names.size < generateCount && attempts < maxAttempts) {
      names.add(randomString(domainLength, chars));
      attempts++;
    }
    
    setGeneratedDomains(addTlds([...names]));
  };

  // Generate by pattern
  const generateByPattern = () => {
    const names = new Set<string>();
    let attempts = 0;
    const maxAttempts = generateCount * 10;
    
    while (names.size < generateCount && attempts < maxAttempts) {
      names.add(generateFromPattern(pattern));
      attempts++;
    }
    
    setGeneratedDomains(addTlds([...names]));
  };

  // Generate with keyword
  const generateWithKeyword = () => {
    if (!keyword.trim()) return;
    
    const names: string[] = [];
    const kw = keyword.toLowerCase().trim();
    
    for (const addition of keywordAdditions) {
      if (keywordPosition === 'prefix' || keywordPosition === 'both') {
        names.push(`${addition}${kw}`);
        names.push(`${addition}-${kw}`);
      }
      if (keywordPosition === 'suffix' || keywordPosition === 'both') {
        names.push(`${kw}${addition}`);
        names.push(`${kw}-${addition}`);
      }
    }
    
    // Also add standalone
    names.push(kw);
    
    // Add numbers
    for (let i = 1; i <= 9; i++) {
      names.push(`${kw}${i}`);
      names.push(`${i}${kw}`);
    }
    for (const year of ['24', '25', '26', '2024', '2025', '2026']) {
      names.push(`${kw}${year}`);
    }
    
    setGeneratedDomains(addTlds([...new Set(names)]));
  };

  // Generate word combinations
  const generateCombinations = () => {
    const words1 = wordList1.split(/[\s,\n]+/).filter(w => w.trim());
    const words2 = wordList2.split(/[\s,\n]+/).filter(w => w.trim());
    
    if (words1.length === 0) return;
    
    const names: string[] = [];
    
    if (words2.length === 0) {
      // Combine words1 with each other
      for (const w1 of words1) {
        for (const w2 of words1) {
          if (w1 !== w2) {
            names.push(`${w1}${separator}${w2}`);
          }
        }
      }
    } else {
      // Combine words1 with words2
      for (const w1 of words1) {
        for (const w2 of words2) {
          names.push(`${w1}${separator}${w2}`);
          names.push(`${w2}${separator}${w1}`);
        }
      }
    }
    
    setGeneratedDomains(addTlds([...new Set(names.map(n => n.toLowerCase()))]));
  };

  // Parse custom list
  const parseCustomList = () => {
    const names = customList.split(/[\s,\n]+/).filter(w => w.trim()).map(w => w.toLowerCase());
    const domainsWithTld: string[] = [];
    
    for (const name of names) {
      // Check if already has TLD
      if (name.includes('.')) {
        domainsWithTld.push(name);
      } else {
        for (const tld of selectedTlds) {
          domainsWithTld.push(`${name}.${tld}`);
        }
      }
    }
    
    setGeneratedDomains([...new Set(domainsWithTld)]);
  };

  // Generate pronounceable
  const generatePronounceableDomains = () => {
    const names = new Set<string>();
    let attempts = 0;
    const maxAttempts = pronounceableCount * 10;
    
    while (names.size < pronounceableCount && attempts < maxAttempts) {
      names.add(generatePronounceable(pronounceableLength));
      attempts++;
    }
    
    setGeneratedDomains(addTlds([...names]));
  };

  // Generate number domains
  const generateNumberDomains = () => {
    const names: string[] = [];
    const max = Math.pow(10, numberLength);
    const count = Math.min(max, 1000);
    
    for (let i = 0; i < count; i++) {
      const num = Math.floor(Math.random() * max).toString().padStart(numberLength, '0');
      names.push(`${numberPrefix}${num}${numberSuffix}`);
    }
    
    setGeneratedDomains(addTlds([...new Set(names)]));
  };

  // Generate all 2-4 letter combinations
  const generateAllShort = (length: number) => {
    if (length < 2 || length > 4) return;
    
    const names: string[] = [];
    const chars = LETTERS;
    
    const generate = (current: string, remaining: number) => {
      if (remaining === 0) {
        names.push(current);
        return;
      }
      for (const c of chars) {
        generate(current + c, remaining - 1);
      }
    };
    
    generate('', length);
    setGeneratedDomains(addTlds(names));
  };

  // Select/deselect domain
  const toggleDomain = (domain: string) => {
    setSelectedDomains(prev => {
      const next = new Set(prev);
      if (next.has(domain)) {
        next.delete(domain);
      } else {
        next.add(domain);
      }
      return next;
    });
  };

  // Select all
  const selectAll = () => {
    setSelectedDomains(new Set(generatedDomains));
  };

  // Clear selection
  const clearSelection = () => {
    setSelectedDomains(new Set());
  };

  // Copy to clipboard
  const copyToClipboard = () => {
    const text = [...selectedDomains].join('\n');
    navigator.clipboard.writeText(text);
  };

  // Add to monitoring (selected domains)
  const addToMonitoring = async () => {
    if (selectedDomains.size === 0) return;
    await importDomainsWithProgress([...selectedDomains]);
    setSelectedDomains(new Set());
  };

  // Add all generated domains
  const addAllToMonitoring = async () => {
    if (generatedDomains.length === 0) return;
    await importDomainsWithProgress(generatedDomains);
  };

  // Import with batch processing and progress
  const importDomainsWithProgress = async (domains: string[]) => {
    setImporting(true);
    setImportProgress({ current: 0, total: domains.length });
    setImportResult(null);
    
    try {
      const result = await importDomainsInBatches(
        domains,
        50, // batch size
        (current, total) => setImportProgress({ current, total })
      );
      
      setImportResult(result);
      setImportSuccess(true);
      setTimeout(() => {
        setImportSuccess(false);
        setImportResult(null);
      }, 5000);
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to import domains');
    } finally {
      setImporting(false);
      setImportProgress(null);
    }
  };

  // Download as txt
  const downloadTxt = () => {
    const text = generatedDomains.join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'domains.txt';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="min-h-screen bg-muted/30">
      {/* Header */}
      <header className="bg-background border-b">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link to="/">
              <Button variant="ghost" size="sm">
                <ArrowLeft className="h-4 w-4 mr-2" />
                Powrót
              </Button>
            </Link>
            <div className="flex items-center gap-2">
              <div className="bg-primary rounded-lg p-2">
                <Wand2 className="h-5 w-5 text-primary-foreground" />
              </div>
              <h1 className="text-xl font-bold">Generator domen</h1>
            </div>
          </div>
          <span className="text-sm text-muted-foreground">{user?.email}</span>
        </div>
      </header>

      <main className="container mx-auto px-4 py-8">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Generator options */}
          <div className="lg:col-span-2 space-y-6">
            {/* TLD Selection */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Rozszerzenia (TLD)</CardTitle>
                <CardDescription>Wybierz rozszerzenia dla generowanych domen</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex flex-wrap gap-2">
                  {TLDS.filter(t => t.popular).map(tld => (
                    <Badge 
                      key={tld.value}
                      variant={selectedTlds.includes(tld.value) ? 'default' : 'outline'}
                      className="cursor-pointer"
                      onClick={() => toggleTld(tld.value)}
                    >
                      {tld.label}
                    </Badge>
                  ))}
                </div>
                <details className="mt-4">
                  <summary className="text-sm text-muted-foreground cursor-pointer">Więcej rozszerzeń...</summary>
                  <div className="flex flex-wrap gap-2 mt-2">
                    {TLDS.filter(t => !t.popular).map(tld => (
                      <Badge 
                        key={tld.value}
                        variant={selectedTlds.includes(tld.value) ? 'default' : 'outline'}
                        className="cursor-pointer"
                        onClick={() => toggleTld(tld.value)}
                      >
                        {tld.label}
                      </Badge>
                    ))}
                  </div>
                </details>
              </CardContent>
            </Card>

            {/* Generator Types */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Typ generatora</CardTitle>
              </CardHeader>
              <CardContent>
                <Tabs value={activeTab} onValueChange={setActiveTab}>
                  <TabsList className="flex flex-wrap h-auto gap-1">
                    <TabsTrigger value="length">Długość</TabsTrigger>
                    <TabsTrigger value="pattern">Wzorzec</TabsTrigger>
                    <TabsTrigger value="keyword">Słowo kluczowe</TabsTrigger>
                    <TabsTrigger value="combine">Kombinacje</TabsTrigger>
                    <TabsTrigger value="pronounceable">Wymawiane</TabsTrigger>
                    <TabsTrigger value="numbers">Numery</TabsTrigger>
                    <TabsTrigger value="all">Wszystkie krótkie</TabsTrigger>
                    <TabsTrigger value="custom">Własna lista</TabsTrigger>
                  </TabsList>

                  {/* Length-based */}
                  <TabsContent value="length" className="space-y-4 mt-4">
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <Label>Długość domeny</Label>
                        <Input 
                          type="number" 
                          min={1} 
                          max={20}
                          value={domainLength}
                          onChange={e => setDomainLength(parseInt(e.target.value) || 4)}
                        />
                      </div>
                      <div>
                        <Label>Ilość</Label>
                        <Input 
                          type="number" 
                          min={1} 
                          max={10000}
                          value={generateCount}
                          onChange={e => setGenerateCount(parseInt(e.target.value) || 100)}
                        />
                      </div>
                    </div>
                    <div>
                      <Label>Typ znaków</Label>
                      <div className="flex gap-2 mt-2">
                        <Badge 
                          variant={charType === 'letters' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setCharType('letters')}
                        >
                          Tylko litery (a-z)
                        </Badge>
                        <Badge 
                          variant={charType === 'alphanumeric' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setCharType('alphanumeric')}
                        >
                          Litery + cyfry
                        </Badge>
                        <Badge 
                          variant={charType === 'numbers' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setCharType('numbers')}
                        >
                          Tylko cyfry
                        </Badge>
                      </div>
                    </div>
                    <Button onClick={generateByLength} className="w-full">
                      <Shuffle className="h-4 w-4 mr-2" />
                      Generuj losowe
                    </Button>
                  </TabsContent>

                  {/* Pattern-based */}
                  <TabsContent value="pattern" className="space-y-4 mt-4">
                    <div>
                      <Label>Wzorzec</Label>
                      <Input 
                        value={pattern}
                        onChange={e => setPattern(e.target.value.toUpperCase())}
                        placeholder="CVCV"
                      />
                      <p className="text-xs text-muted-foreground mt-1">
                        C = spółgłoska, V = samogłoska, L = dowolna litera, N = cyfra
                      </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('CVCV')}>CVCV</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('CVVC')}>CVVC</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('VCVC')}>VCVC</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('CVCVC')}>CVCVC</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('CVCCV')}>CVCCV</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('LLLL')}>LLLL</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('LLLNN')}>LLLNN</Badge>
                      <Badge variant="outline" className="cursor-pointer" onClick={() => setPattern('CVNN')}>CVNN</Badge>
                    </div>
                    <div>
                      <Label>Ilość</Label>
                      <Input 
                        type="number" 
                        min={1} 
                        max={10000}
                        value={generateCount}
                        onChange={e => setGenerateCount(parseInt(e.target.value) || 100)}
                      />
                    </div>
                    <Button onClick={generateByPattern} className="w-full">
                      <Shuffle className="h-4 w-4 mr-2" />
                      Generuj według wzorca
                    </Button>
                  </TabsContent>

                  {/* Keyword-based */}
                  <TabsContent value="keyword" className="space-y-4 mt-4">
                    <div>
                      <Label>Słowo kluczowe</Label>
                      <Input 
                        value={keyword}
                        onChange={e => setKeyword(e.target.value)}
                        placeholder="np. crypto, shop, tech"
                      />
                    </div>
                    <div>
                      <Label>Pozycja dodatków</Label>
                      <div className="flex gap-2 mt-2">
                        <Badge 
                          variant={keywordPosition === 'prefix' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setKeywordPosition('prefix')}
                        >
                          Prefiks (getshop)
                        </Badge>
                        <Badge 
                          variant={keywordPosition === 'suffix' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setKeywordPosition('suffix')}
                        >
                          Sufiks (shophub)
                        </Badge>
                        <Badge 
                          variant={keywordPosition === 'both' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setKeywordPosition('both')}
                        >
                          Oba
                        </Badge>
                      </div>
                    </div>
                    <div>
                      <Label>Dodatki (rozdziel spacjami)</Label>
                      <Input 
                        value={keywordAdditions.join(' ')}
                        onChange={e => setKeywordAdditions(e.target.value.split(/\s+/).filter(w => w))}
                        placeholder="app hub lab io pro"
                      />
                    </div>
                    <Button onClick={generateWithKeyword} className="w-full">
                      <Wand2 className="h-4 w-4 mr-2" />
                      Generuj warianty
                    </Button>
                  </TabsContent>

                  {/* Word combinations */}
                  <TabsContent value="combine" className="space-y-4 mt-4">
                    <div>
                      <Label>Lista słów 1</Label>
                      <Textarea 
                        value={wordList1}
                        onChange={e => setWordList1(e.target.value)}
                        placeholder="crypto, block, chain, bit, byte"
                        rows={3}
                      />
                    </div>
                    <div>
                      <Label>Lista słów 2 (opcjonalnie)</Label>
                      <Textarea 
                        value={wordList2}
                        onChange={e => setWordList2(e.target.value)}
                        placeholder="hub, lab, pro, app, io"
                        rows={3}
                      />
                      <p className="text-xs text-muted-foreground mt-1">
                        Pozostaw puste aby łączyć słowa z listy 1 ze sobą
                      </p>
                    </div>
                    <div>
                      <Label>Separator</Label>
                      <div className="flex gap-2 mt-2">
                        <Badge 
                          variant={separator === '' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setSeparator('')}
                        >
                          Brak
                        </Badge>
                        <Badge 
                          variant={separator === '-' ? 'default' : 'outline'}
                          className="cursor-pointer"
                          onClick={() => setSeparator('-')}
                        >
                          Myślnik (-)
                        </Badge>
                      </div>
                    </div>
                    <Button onClick={generateCombinations} className="w-full">
                      <Shuffle className="h-4 w-4 mr-2" />
                      Generuj kombinacje
                    </Button>
                  </TabsContent>

                  {/* Pronounceable */}
                  <TabsContent value="pronounceable" className="space-y-4 mt-4">
                    <p className="text-sm text-muted-foreground">
                      Generuje łatwe do wymówienia domeny poprzez naprzemienne samogłoski i spółgłoski.
                    </p>
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <Label>Długość</Label>
                        <Input 
                          type="number" 
                          min={3} 
                          max={12}
                          value={pronounceableLength}
                          onChange={e => setPronounceableLength(parseInt(e.target.value) || 5)}
                        />
                      </div>
                      <div>
                        <Label>Ilość</Label>
                        <Input 
                          type="number" 
                          min={1} 
                          max={10000}
                          value={pronounceableCount}
                          onChange={e => setPronounceableCount(parseInt(e.target.value) || 100)}
                        />
                      </div>
                    </div>
                    <Button onClick={generatePronounceableDomains} className="w-full">
                      <Shuffle className="h-4 w-4 mr-2" />
                      Generuj wymawiane
                    </Button>
                  </TabsContent>

                  {/* Number domains */}
                  <TabsContent value="numbers" className="space-y-4 mt-4">
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <Label>Prefiks</Label>
                        <Input 
                          value={numberPrefix}
                          onChange={e => setNumberPrefix(e.target.value)}
                          placeholder="np. i"
                        />
                      </div>
                      <div>
                        <Label>Długość numeru</Label>
                        <Input 
                          type="number" 
                          min={1} 
                          max={6}
                          value={numberLength}
                          onChange={e => setNumberLength(parseInt(e.target.value) || 3)}
                        />
                      </div>
                      <div>
                        <Label>Sufiks</Label>
                        <Input 
                          value={numberSuffix}
                          onChange={e => setNumberSuffix(e.target.value)}
                          placeholder="np. x"
                        />
                      </div>
                    </div>
                    <Button onClick={generateNumberDomains} className="w-full">
                      <Shuffle className="h-4 w-4 mr-2" />
                      Generuj numery
                    </Button>
                  </TabsContent>

                  {/* All short domains */}
                  <TabsContent value="all" className="space-y-4 mt-4">
                    <p className="text-sm text-muted-foreground">
                      Generuje wszystkie możliwe kombinacje dla wybranej długości.
                      Uwaga: 4 litery = 456,976 kombinacji!
                    </p>
                    <div className="flex gap-2">
                      <Button onClick={() => generateAllShort(2)} variant="outline">
                        2 znaki (676)
                      </Button>
                      <Button onClick={() => generateAllShort(3)} variant="outline">
                        3 znaki (17,576)
                      </Button>
                      <Button onClick={() => generateAllShort(4)} variant="outline">
                        4 znaki (456,976)
                      </Button>
                    </div>
                    <p className="text-xs text-destructive">
                      Generowanie wszystkich 4-znakowych może zająć chwilę!
                    </p>
                  </TabsContent>

                  {/* Custom list */}
                  <TabsContent value="custom" className="space-y-4 mt-4">
                    <div>
                      <Label>Własna lista domen</Label>
                      <Textarea 
                        value={customList}
                        onChange={e => setCustomList(e.target.value)}
                        placeholder="example.com, test, domain.net, mysite"
                        rows={6}
                      />
                      <p className="text-xs text-muted-foreground mt-1">
                        Rozdziel przecinkami, spacjami lub nowymi liniami. Domeny bez TLD dostaną wybrane rozszerzenia.
                      </p>
                    </div>
                    <Button onClick={parseCustomList} className="w-full">
                      <Filter className="h-4 w-4 mr-2" />
                      Przetwórz listę
                    </Button>
                  </TabsContent>
                </Tabs>
              </CardContent>
            </Card>
          </div>

          {/* Results */}
          <div className="space-y-4">
            <Card>
              <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                  <CardTitle className="text-lg">
                    Wyniki ({generatedDomains.length})
                  </CardTitle>
                  {generatedDomains.length > 0 && (
                    <Button variant="ghost" size="sm" onClick={downloadTxt}>
                      <Download className="h-4 w-4" />
                    </Button>
                  )}
                </div>
                {generatedDomains.length > 0 && (
                  <div className="flex gap-2 text-sm">
                    <Button variant="link" size="sm" className="h-auto p-0" onClick={selectAll}>
                      Zaznacz wszystkie
                    </Button>
                    <span>|</span>
                    <Button variant="link" size="sm" className="h-auto p-0" onClick={clearSelection}>
                      Odznacz
                    </Button>
                  </div>
                )}
              </CardHeader>
              <CardContent>
                {generatedDomains.length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    <Wand2 className="h-12 w-12 mx-auto mb-4 opacity-20" />
                    <p>Użyj generatora aby wygenerować domeny</p>
                  </div>
                ) : (
                  <div className="max-h-[500px] overflow-y-auto space-y-1">
                    {generatedDomains.slice(0, 500).map(domain => (
                      <div 
                        key={domain}
                        className={`text-sm px-2 py-1 rounded cursor-pointer hover:bg-muted flex items-center justify-between ${selectedDomains.has(domain) ? 'bg-primary/10' : ''}`}
                        onClick={() => toggleDomain(domain)}
                      >
                        <span className="font-mono">{domain}</span>
                        {selectedDomains.has(domain) && (
                          <Check className="h-4 w-4 text-primary" />
                        )}
                      </div>
                    ))}
                    {generatedDomains.length > 500 && (
                      <p className="text-xs text-muted-foreground text-center py-2">
                        ...i {generatedDomains.length - 500} więcej (pobierz TXT)
                      </p>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Actions */}
            {generatedDomains.length > 0 && (
              <Card>
                <CardContent className="pt-4 space-y-3">
                  {/* Progress indicator */}
                  {importProgress && (
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span>Importowanie...</span>
                        <span>{importProgress.current} / {importProgress.total}</span>
                      </div>
                      <div className="w-full bg-muted rounded-full h-2">
                        <div 
                          className="bg-primary h-2 rounded-full transition-all"
                          style={{ width: `${(importProgress.current / importProgress.total) * 100}%` }}
                        />
                      </div>
                    </div>
                  )}
                  
                  {/* Result message */}
                  {importResult && (
                    <div className="text-sm p-2 bg-primary/10 rounded">
                      Zaimportowano: {importResult.imported}
                      {importResult.errors > 0 && (
                        <span className="text-destructive"> (błędy: {importResult.errors})</span>
                      )}
                    </div>
                  )}
                  
                  {/* Selection info */}
                  {selectedDomains.size > 0 && (
                    <p className="text-sm font-medium">
                      Zaznaczono: {selectedDomains.size}
                    </p>
                  )}
                  
                  {/* Action buttons */}
                  <div className="flex flex-col gap-2">
                    {selectedDomains.size > 0 ? (
                      <Button 
                        onClick={addToMonitoring} 
                        disabled={importing}
                        className="w-full"
                      >
                        {importing ? (
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        ) : importSuccess ? (
                          <Check className="h-4 w-4 mr-2" />
                        ) : (
                          <Plus className="h-4 w-4 mr-2" />
                        )}
                        {importSuccess ? 'Dodano!' : `Dodaj zaznaczone (${selectedDomains.size})`}
                      </Button>
                    ) : (
                      <Button 
                        onClick={addAllToMonitoring} 
                        disabled={importing}
                        className="w-full"
                      >
                        {importing ? (
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        ) : importSuccess ? (
                          <Check className="h-4 w-4 mr-2" />
                        ) : (
                          <Plus className="h-4 w-4 mr-2" />
                        )}
                        {importSuccess ? 'Dodano!' : `Dodaj wszystkie (${generatedDomains.length})`}
                      </Button>
                    )}
                    <Button variant="outline" onClick={copyToClipboard} disabled={selectedDomains.size === 0}>
                      <Copy className="h-4 w-4 mr-2" />
                      Kopiuj zaznaczone
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}
