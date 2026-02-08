import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useToast } from '@/hooks/use-toast';
import { getProfile, updateProfile, type User } from '@/lib/api';
import { Loader2, Save, Mail, Lock } from 'lucide-react';
import { useEffect } from 'react';

export default function SettingsPage() {
  const { toast } = useToast();
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  
  // Email form
  const [newEmail, setNewEmail] = useState('');
  const [emailPassword, setEmailPassword] = useState('');
  
  // Password form
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  
  useEffect(() => {
    loadProfile();
  }, []);
  
  async function loadProfile() {
    try {
      const result = await getProfile();
      setUser(result.user);
      setNewEmail(result.user.email);
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się załadować profilu',
        variant: 'destructive',
      });
    } finally {
      setLoading(false);
    }
  }
  
  async function handleEmailChange(e: React.FormEvent) {
    e.preventDefault();
    
    if (!emailPassword) {
      toast({
        title: 'Błąd',
        description: 'Podaj aktualne hasło',
        variant: 'destructive',
      });
      return;
    }
    
    if (newEmail === user?.email) {
      toast({
        title: 'Informacja',
        description: 'Nowy email jest taki sam jak obecny',
      });
      return;
    }
    
    setSaving(true);
    try {
      const result = await updateProfile({
        email: newEmail,
        current_password: emailPassword,
      });
      setUser(result.user);
      setEmailPassword('');
      toast({
        title: 'Sukces',
        description: 'Email został zmieniony',
      });
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się zmienić emaila',
        variant: 'destructive',
      });
    } finally {
      setSaving(false);
    }
  }
  
  async function handlePasswordChange(e: React.FormEvent) {
    e.preventDefault();
    
    if (!currentPassword) {
      toast({
        title: 'Błąd',
        description: 'Podaj aktualne hasło',
        variant: 'destructive',
      });
      return;
    }
    
    if (newPassword.length < 6) {
      toast({
        title: 'Błąd',
        description: 'Nowe hasło musi mieć minimum 6 znaków',
        variant: 'destructive',
      });
      return;
    }
    
    if (newPassword !== confirmPassword) {
      toast({
        title: 'Błąd',
        description: 'Hasła nie są identyczne',
        variant: 'destructive',
      });
      return;
    }
    
    setSaving(true);
    try {
      await updateProfile({
        password: newPassword,
        current_password: currentPassword,
      });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      toast({
        title: 'Sukces',
        description: 'Hasło zostało zmienione',
      });
    } catch (err) {
      toast({
        title: 'Błąd',
        description: err instanceof Error ? err.message : 'Nie udało się zmienić hasła',
        variant: 'destructive',
      });
    } finally {
      setSaving(false);
    }
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
      <div>
        <h1 className="text-2xl font-bold">Ustawienia</h1>
        <p className="text-muted-foreground">Zarządzaj swoim kontem</p>
      </div>
      
      <div className="grid gap-6 md:grid-cols-2">
        {/* Email Change */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Mail className="h-5 w-5" />
              Zmień email
            </CardTitle>
            <CardDescription>
              Aktualny email: {user?.email}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleEmailChange} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="new-email">Nowy email</Label>
                <Input
                  id="new-email"
                  type="email"
                  value={newEmail}
                  onChange={(e) => setNewEmail(e.target.value)}
                  placeholder="nowy@email.com"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="email-password">Aktualne hasło</Label>
                <Input
                  id="email-password"
                  type="password"
                  value={emailPassword}
                  onChange={(e) => setEmailPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                />
              </div>
              <Button type="submit" disabled={saving} className="w-full">
                {saving ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Save className="h-4 w-4 mr-2" />
                )}
                Zapisz email
              </Button>
            </form>
          </CardContent>
        </Card>
        
        {/* Password Change */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Lock className="h-5 w-5" />
              Zmień hasło
            </CardTitle>
            <CardDescription>
              Hasło musi mieć minimum 6 znaków
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handlePasswordChange} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="current-password">Aktualne hasło</Label>
                <Input
                  id="current-password"
                  type="password"
                  value={currentPassword}
                  onChange={(e) => setCurrentPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="new-password">Nowe hasło</Label>
                <Input
                  id="new-password"
                  type="password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="confirm-password">Potwierdź nowe hasło</Label>
                <Input
                  id="confirm-password"
                  type="password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                />
              </div>
              <Button type="submit" disabled={saving} className="w-full">
                {saving ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Save className="h-4 w-4 mr-2" />
                )}
                Zmień hasło
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
      
      {/* Account Info */}
      <Card>
        <CardHeader>
          <CardTitle>Informacje o koncie</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <dt className="text-muted-foreground">Email</dt>
              <dd className="font-medium">{user?.email}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Rola</dt>
              <dd className="font-medium">{user?.is_admin ? 'Administrator' : 'Użytkownik'}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">ID użytkownika</dt>
              <dd className="font-medium">{user?.id}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Data utworzenia</dt>
              <dd className="font-medium">
                {user?.created_at ? new Date(user.created_at).toLocaleDateString('pl-PL') : '-'}
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>
    </div>
  );
}
