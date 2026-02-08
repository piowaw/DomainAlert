import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { getJobs, deleteJob, processJob, type Job } from '@/lib/api';
import { Loader2, Trash2, CheckCircle, XCircle, Clock, RefreshCw, Cog } from 'lucide-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';

export default function TasksPage() {
  const { toast } = useToast();
  const [jobs, setJobs] = useState<Job[]>([]);
  const [loading, setLoading] = useState(true);
  // Real-time counters updated by workers (faster than polling)
  const [liveProgress, setLiveProgress] = useState<Record<number, { processed: number; errors: number; status: string }>>({});
  
  const loadJobs = useCallback(async () => {
    try {
      const result = await getJobs();
      setJobs(result.jobs);
      // Sync live progress from server data
      const lp: Record<number, { processed: number; errors: number; status: string }> = {};
      for (const j of result.jobs) {
        lp[j.id] = { processed: j.processed, errors: j.errors, status: j.status };
      }
      setLiveProgress(lp);
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się załadować zadań',
        variant: 'destructive',
      });
    } finally {
      setLoading(false);
    }
  }, [toast]);
  
  useEffect(() => {
    loadJobs();
  }, []);
  
  // 6 parallel workers × 100 batch × 30 concurrent RDAP = 18,000 simultaneous lookups
  // Fewer workers = less SQLite write contention, bigger batches = more work per lock
  const NUM_WORKERS = 6;
  const BATCH_SIZE = 100;
  
  useEffect(() => {
    const activeJobs = jobs.filter(j => j.status === 'pending' || j.status === 'processing');
    if (activeJobs.length === 0) return;
    
    let cancelled = false;
    
    // Worker: loops continuously, updates live counter on every response
    async function worker(jobId: number) {
      let consecutiveErrors = 0;
      while (!cancelled) {
        try {
          const result = await processJob(jobId, BATCH_SIZE);
          consecutiveErrors = 0; // reset on success
          // Update live counter immediately (no waiting for poll)
          setLiveProgress(prev => ({
            ...prev,
            [jobId]: {
              processed: result.job.processed,
              errors: result.job.errors,
              status: result.job.status,
            }
          }));
          if (result.job.status === 'completed' || (result as Record<string, unknown>).message === 'completed') {
            break;
          }
        } catch {
          consecutiveErrors++;
          // Exponential backoff: 500ms, 1s, 2s, 4s, max 8s
          const delay = Math.min(500 * Math.pow(2, consecutiveErrors - 1), 8000);
          await new Promise(r => setTimeout(r, delay));
          // Give up after 20 consecutive errors
          if (consecutiveErrors >= 20) break;
        }
      }
    }
    
    // Launch workers per active job
    const allWorkers: Promise<void>[] = [];
    for (const job of activeJobs) {
      for (let i = 0; i < NUM_WORKERS; i++) {
        allWorkers.push(worker(job.id));
      }
    }
    
    // Full refresh every 5s (live counters handle real-time updates)
    const interval = setInterval(async () => {
      if (cancelled) return;
      await loadJobs();
    }, 5000);
    
    Promise.all(allWorkers).then(() => { if (!cancelled) loadJobs(); });
    
    return () => { cancelled = true; clearInterval(interval); };
  }, [jobs.filter(j => j.status === 'pending' || j.status === 'processing').map(j => j.id).join(',')]); // eslint-disable-line react-hooks/exhaustive-deps
  
  async function handleDelete(id: number) {
    try {
      await deleteJob(id);
      setJobs(prev => prev.filter(j => j.id !== id));
      toast({
        title: 'Sukces',
        description: 'Zadanie usunięte',
      });
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się usunąć zadania',
        variant: 'destructive',
      });
    }
  }
  
  function getStatusBadge(status: string) {
    switch (status) {
      case 'pending':
        return <Badge variant="secondary"><Clock className="h-3 w-3 mr-1" /> Oczekuje</Badge>;
      case 'processing':
        return <Badge variant="default"><Cog className="h-3 w-3 mr-1 animate-spin" /> Przetwarzanie</Badge>;
      case 'completed':
        return <Badge variant="default" className="bg-green-600"><CheckCircle className="h-3 w-3 mr-1" /> Zakończone</Badge>;
      case 'failed':
        return <Badge variant="destructive"><XCircle className="h-3 w-3 mr-1" /> Błąd</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  }
  
  function getJobTypeLabel(type: string) {
    switch (type) {
      case 'import':
        return 'Import domen';
      case 'whois_check':
        return 'Sprawdzanie WHOIS';
      default:
        return type;
    }
  }
  
  function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleString('pl-PL', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }
  
  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }
  
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Zadania</h1>
          <p className="text-muted-foreground">Zadania w tle ({NUM_WORKERS} workerów, batch={BATCH_SIZE})</p>
        </div>
        <Button variant="outline" onClick={loadJobs}>
          <RefreshCw className="h-4 w-4 mr-2" />
          Odśwież
        </Button>
      </div>
      
      {jobs.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-muted-foreground">
            Brak zadań do wyświetlenia
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-4">
          {jobs.map(job => {
            // Use live progress if available (updated by workers in real-time)
            const live = liveProgress[job.id];
            const currentProcessed = live ? live.processed : job.processed;
            const currentErrors = live ? live.errors : job.errors;
            const currentStatus = live ? live.status : job.status;
            const progress = job.total > 0 ? (currentProcessed / job.total) * 100 : 0;
            
            return (
              <Card key={job.id}>
                <CardHeader className="pb-2">
                  <div className="flex items-center justify-between">
                    <div className="space-y-1">
                      <CardTitle className="text-base">
                        {getJobTypeLabel(job.type)}
                      </CardTitle>
                      <CardDescription>
                        Utworzono: {formatDate(job.created_at)}
                      </CardDescription>
                    </div>
                    {getStatusBadge(currentStatus)}
                  </div>
                </CardHeader>
                <CardContent className="space-y-4">
                  {/* Progress bar with real-time counter */}
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className="font-mono font-bold text-base">
                        {currentProcessed.toLocaleString('pl-PL')} / {job.total.toLocaleString('pl-PL')}
                      </span>
                      <span className="font-mono font-bold text-base">{Math.round(progress)}%</span>
                    </div>
                    <Progress value={progress} className="h-3" />
                  </div>
                  
                  {/* Error count */}
                  {currentErrors > 0 && (
                    <p className="text-sm text-red-500">
                      Błędy: {currentErrors}
                    </p>
                  )}
                  
                  {/* Result */}
                  {job.result && (
                    <div className="text-sm bg-muted p-2 rounded-md">
                      <strong>Wynik:</strong>
                      <pre className="whitespace-pre-wrap text-xs mt-1">
                        {job.result.length > 200 ? job.result.slice(0, 200) + '...' : job.result}
                      </pre>
                    </div>
                  )}
                  
                  {/* Actions */}
                  <div className="flex gap-2">
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button size="sm" variant="destructive">
                          <Trash2 className="h-4 w-4 mr-2" />
                          Usuń
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>Usunąć zadanie?</AlertDialogTitle>
                          <AlertDialogDescription>
                            Ta akcja jest nieodwracalna. Zadanie zostanie trwale usunięte.
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>Anuluj</AlertDialogCancel>
                          <AlertDialogAction onClick={() => handleDelete(job.id)}>
                            Usuń
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}
