import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { getJobs, deleteJob, processJob, resumeJob, type Job } from '@/lib/api';
import { Loader2, Trash2, CheckCircle, XCircle, Clock, RefreshCw, Cog, Play } from 'lucide-react';
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
  
  const loadJobs = useCallback(async () => {
    try {
      const result = await getJobs();
      setJobs(result.jobs);
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
  
  // Kick off pending jobs and poll active ones for status
  useEffect(() => {
    const activeJobs = jobs.filter(j => j.status === 'pending' || j.status === 'processing');
    if (activeJobs.length === 0) return;
    
    let cancelled = false;
    const kickedOff = new Set<number>();
    
    // Fire-and-forget: kick off processing for pending AND stale processing jobs
    // Backend handles stale detection (>2min since last update = dead process)
    for (const job of activeJobs) {
      if (!kickedOff.has(job.id)) {
        kickedOff.add(job.id);
        processJob(job.id).catch(() => {}); // fire and forget
      }
    }
    
    // Poll for status updates independently
    const interval = setInterval(async () => {
      if (cancelled) return;
      await loadJobs();
    }, 3000);
    
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
  
  async function handleResume(id: number) {
    try {
      const result = await resumeJob(id);
      toast({
        title: 'Wznowiono',
        description: result.message || 'Zadanie zostanie wznowione',
      });
      await loadJobs();
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się wznowić zadania',
        variant: 'destructive',
      });
    }
  }
  
  function getStatusBadge(status: Job['status']) {
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
          <p className="text-muted-foreground">Twoje zadania w tle</p>
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
            const progress = job.total > 0 ? (job.processed / job.total) * 100 : 0;
            const isActive = job.status === 'pending' || job.status === 'processing';
            
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
                    {getStatusBadge(job.status)}
                  </div>
                </CardHeader>
                <CardContent className="space-y-4">
                  {/* Progress bar */}
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span>Postęp: {job.processed} / {job.total}</span>
                      <span>{Math.round(progress)}%</span>
                    </div>
                    <Progress value={progress} className="h-2" />
                  </div>
                  
                  {/* Active job info */}
                  {isActive && (
                    <p className="text-sm text-muted-foreground">
                      {job.status === 'processing' && job.processed < job.total
                        ? `Przetwarzanie... (${job.processed}/${job.total})`
                        : 'Zadanie jest przetwarzane w tle na serwerze...'}
                    </p>
                  )}
                  
                  {/* Error count */}
                  {job.errors > 0 && (
                    <p className="text-sm text-red-500">
                      Błędy: {job.errors}
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
                    {job.status === 'processing' && job.processed < job.total && (
                      <Button size="sm" variant="outline" onClick={() => handleResume(job.id)}>
                        <Play className="h-4 w-4 mr-2" />
                        Wznów
                      </Button>
                    )}
                    {job.status === 'failed' && (
                      <Button size="sm" variant="outline" onClick={() => handleResume(job.id)}>
                        <Play className="h-4 w-4 mr-2" />
                        Ponów
                      </Button>
                    )}
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
