import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { getJobs, processJob, deleteJob, type Job } from '@/lib/api';
import { Loader2, Play, Trash2, CheckCircle, XCircle, Clock, RefreshCw } from 'lucide-react';
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
  const [processingJobs, setProcessingJobs] = useState<Set<number>>(new Set());
  
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
    
    // Auto-refresh every 5 seconds if there are running jobs
    const interval = setInterval(() => {
      if (jobs.some(j => j.status === 'pending' || j.status === 'running')) {
        loadJobs();
      }
    }, 5000);
    
    return () => clearInterval(interval);
  }, [loadJobs, jobs]);
  
  async function handleProcess(job: Job) {
    if (processingJobs.has(job.id)) return;
    
    setProcessingJobs(prev => new Set(prev).add(job.id));
    
    try {
      // Process in batches until completed
      let currentJob = job;
      while (currentJob.status === 'pending' || currentJob.status === 'running') {
        const result = await processJob(job.id);
        currentJob = result.job;
        
        // Update job in list
        setJobs(prev => prev.map(j => j.id === job.id ? currentJob : j));
        
        if (currentJob.status === 'completed' || currentJob.status === 'failed') {
          break;
        }
        
        // Small delay between batches
        await new Promise(resolve => setTimeout(resolve, 200));
      }
      
      toast({
        title: 'Sukces',
        description: `Zadanie zakończone: ${currentJob.processed} przetworzonych`,
      });
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Błąd przetwarzania',
        variant: 'destructive',
      });
    } finally {
      setProcessingJobs(prev => {
        const next = new Set(prev);
        next.delete(job.id);
        return next;
      });
      loadJobs();
    }
  }
  
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
  
  function getStatusBadge(status: Job['status']) {
    switch (status) {
      case 'pending':
        return <Badge variant="secondary"><Clock className="h-3 w-3 mr-1" /> Oczekuje</Badge>;
      case 'running':
        return <Badge variant="default"><Loader2 className="h-3 w-3 mr-1 animate-spin" /> W trakcie</Badge>;
      case 'completed':
        return <Badge variant="default" className="bg-green-600"><CheckCircle className="h-3 w-3 mr-1" /> Zakończone</Badge>;
      case 'failed':
        return <Badge variant="destructive"><XCircle className="h-3 w-3 mr-1" /> Błąd</Badge>;
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
            const isProcessing = processingJobs.has(job.id);
            
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
                    {(job.status === 'pending' || job.status === 'running') && (
                      <Button
                        size="sm"
                        onClick={() => handleProcess(job)}
                        disabled={isProcessing}
                      >
                        {isProcessing ? (
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        ) : (
                          <Play className="h-4 w-4 mr-2" />
                        )}
                        {isProcessing ? 'Przetwarzanie...' : 'Kontynuuj'}
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
