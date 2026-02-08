import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { getUsers, deleteUser, createUser, getInvitations, createInvitation, deleteInvitation, updateUserRole, type User, type Invitation } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { Plus, Trash2, Copy, Check, Loader2 } from 'lucide-react';

export default function AdminPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [users, setUsers] = useState<User[]>([]);
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [loading, setLoading] = useState(true);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteLoading, setInviteLoading] = useState(false);
  const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [newInviteLink, setNewInviteLink] = useState<string | null>(null);
  
  // User creation
  const [userDialogOpen, setUserDialogOpen] = useState(false);
  const [newUserEmail, setNewUserEmail] = useState('');
  const [newUserLoading, setNewUserLoading] = useState(false);
  const [createdUserPassword, setCreatedUserPassword] = useState<string | null>(null);
  const [createdUserEmail, setCreatedUserEmail] = useState<string | null>(null);
  const [updatingRoleId, setUpdatingRoleId] = useState<number | null>(null);

  useEffect(() => {
    if (!user?.is_admin) {
      navigate('/');
      return;
    }
    loadData();
  }, [user, navigate]);

  const loadData = async () => {
    try {
      const [usersRes, invitesRes] = await Promise.all([
        getUsers(),
        getInvitations(),
      ]);
      setUsers(usersRes.users);
      setInvitations(invitesRes.invitations);
    } catch (err) {
      console.error('Failed to load data:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateInvitation = async (e: React.FormEvent) => {
    e.preventDefault();
    setInviteLoading(true);

    try {
      const result = await createInvitation(inviteEmail || undefined);
      const inviteLink = `${window.location.origin}/register?invite=${result.invitation.token}`;
      setNewInviteLink(inviteLink);
      setInviteEmail('');
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to create invitation');
    } finally {
      setInviteLoading(false);
    }
  };

  const handleDeleteUser = async (id: number) => {
    if (!confirm('Czy na pewno chcesz usunąć tego użytkownika?')) return;
    
    try {
      await deleteUser(id);
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to delete user');
    }
  };

  const handleRoleChange = async (id: number, isAdmin: boolean) => {
    setUpdatingRoleId(id);
    try {
      await updateUserRole(id, isAdmin);
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to update role');
    } finally {
      setUpdatingRoleId(null);
    }
  };

  const handleCreateUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUserEmail) return;
    
    setNewUserLoading(true);
    try {
      const result = await createUser(newUserEmail);
      setCreatedUserEmail(result.user.email);
      setCreatedUserPassword(result.password);
      setNewUserEmail('');
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to create user');
    } finally {
      setNewUserLoading(false);
    }
  };

  const handleDeleteInvitation = async (id: number) => {
    try {
      await deleteInvitation(id);
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to delete invitation');
    }
  };

  const copyToClipboard = async (text: string, id: number) => {
    await navigator.clipboard.writeText(text);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('pl-PL');
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Panel Administratora</h1>
        <p className="text-muted-foreground">Zarządzaj użytkownikami i zaproszeniami</p>
      </div>

      <div className="space-y-8">
        {/* Invitations */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Zaproszenia</CardTitle>
              <CardDescription>
                Twórz zaproszenia dla nowych użytkowników
              </CardDescription>
            </div>
            <Dialog open={inviteDialogOpen} onOpenChange={(open) => {
              setInviteDialogOpen(open);
              if (!open) setNewInviteLink(null);
            }}>
              <DialogTrigger asChild>
                <Button>
                  <Plus className="h-4 w-4 mr-2" />
                  Nowe zaproszenie
                </Button>
              </DialogTrigger>
              <DialogContent>
                {newInviteLink ? (
                  <>
                    <DialogHeader>
                      <DialogTitle>Zaproszenie utworzone!</DialogTitle>
                      <DialogDescription>
                        Skopiuj link i wyślij go nowemu użytkownikowi
                      </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                      <Label>Link zaproszenia</Label>
                      <div className="flex gap-2 mt-2">
                        <Input value={newInviteLink} readOnly className="text-xs" />
                        <Button
                          onClick={() => navigator.clipboard.writeText(newInviteLink)}
                        >
                          <Copy className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                    <DialogFooter>
                      <Button onClick={() => {
                        setNewInviteLink(null);
                        setInviteDialogOpen(false);
                      }}>
                        Zamknij
                      </Button>
                    </DialogFooter>
                  </>
                ) : (
                  <form onSubmit={handleCreateInvitation}>
                    <DialogHeader>
                      <DialogTitle>Nowe zaproszenie</DialogTitle>
                      <DialogDescription>
                        Opcjonalnie podaj email dla dedykowanego zaproszenia
                      </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                      <Label htmlFor="email">Email (opcjonalny)</Label>
                      <Input
                        id="email"
                        type="email"
                        placeholder="user@example.com"
                        value={inviteEmail}
                        onChange={(e) => setInviteEmail(e.target.value)}
                        className="mt-2"
                      />
                    </div>
                    <DialogFooter>
                      <Button type="submit" disabled={inviteLoading}>
                        {inviteLoading ? (
                          <>
                            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                            Tworzenie...
                          </>
                        ) : (
                          'Utwórz zaproszenie'
                        )}
                      </Button>
                    </DialogFooter>
                  </form>
                )}
              </DialogContent>
            </Dialog>
          </CardHeader>
          <CardContent>
            {invitations.length === 0 ? (
              <div className="text-center py-8 text-muted-foreground">
                Brak zaproszeń
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Token</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Utworzone przez</TableHead>
                    <TableHead>Data</TableHead>
                    <TableHead className="text-right">Akcje</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invitations.map((invite) => (
                    <TableRow key={invite.id}>
                      <TableCell className="font-mono text-xs">
                        {invite.token.substring(0, 16)}...
                      </TableCell>
                      <TableCell>{invite.email || '-'}</TableCell>
                      <TableCell>
                        <Badge variant={invite.used ? 'secondary' : 'success'}>
                          {invite.used ? 'Użyte' : 'Aktywne'}
                        </Badge>
                      </TableCell>
                      <TableCell>{invite.created_by_email}</TableCell>
                      <TableCell>{formatDate(invite.created_at)}</TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          {!invite.used && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => copyToClipboard(
                                `${window.location.origin}/register?invite=${invite.token}`,
                                invite.id
                              )}
                            >
                              {copiedId === invite.id ? (
                                <Check className="h-4 w-4 text-green-600" />
                              ) : (
                                <Copy className="h-4 w-4" />
                              )}
                            </Button>
                          )}
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleDeleteInvitation(invite.id)}
                          >
                            <Trash2 className="h-4 w-4 text-destructive" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>

        {/* Users */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Użytkownicy</CardTitle>
              <CardDescription>
                Zarządzaj użytkownikami systemu
              </CardDescription>
            </div>
            <Dialog open={userDialogOpen} onOpenChange={(open) => {
              setUserDialogOpen(open);
              if (!open) {
                setCreatedUserPassword(null);
                setCreatedUserEmail(null);
              }
            }}>
              <DialogTrigger asChild>
                <Button>
                  <Plus className="h-4 w-4 mr-2" />
                  Dodaj użytkownika
                </Button>
              </DialogTrigger>
              <DialogContent>
                {createdUserPassword ? (
                  <>
                    <DialogHeader>
                      <DialogTitle>Użytkownik utworzony!</DialogTitle>
                      <DialogDescription>
                        Zapisz hasło - nie będzie można go ponownie wyświetlić
                      </DialogDescription>
                    </DialogHeader>
                    <div className="py-4 space-y-4">
                      <div>
                        <Label>Email</Label>
                        <Input value={createdUserEmail || ''} readOnly className="mt-2" />
                      </div>
                      <div>
                        <Label>Hasło</Label>
                        <div className="flex gap-2 mt-2">
                          <Input value={createdUserPassword} readOnly className="font-mono" />
                          <Button
                            onClick={() => navigator.clipboard.writeText(createdUserPassword)}
                          >
                            <Copy className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    </div>
                    <DialogFooter>
                      <Button onClick={() => {
                        setCreatedUserPassword(null);
                        setCreatedUserEmail(null);
                        setUserDialogOpen(false);
                      }}>
                        Zamknij
                      </Button>
                    </DialogFooter>
                  </>
                ) : (
                  <form onSubmit={handleCreateUser}>
                    <DialogHeader>
                      <DialogTitle>Nowy użytkownik</DialogTitle>
                      <DialogDescription>
                        Hasło zostanie wygenerowane automatycznie
                      </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                      <Label htmlFor="newUserEmail">Email</Label>
                      <Input
                        id="newUserEmail"
                        type="email"
                        placeholder="user@example.com"
                        value={newUserEmail}
                        onChange={(e) => setNewUserEmail(e.target.value)}
                        className="mt-2"
                        required
                      />
                    </div>
                    <DialogFooter>
                      <Button type="submit" disabled={newUserLoading}>
                        {newUserLoading ? (
                          <>
                            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                            Tworzenie...
                          </>
                        ) : (
                          'Utwórz użytkownika'
                        )}
                      </Button>
                    </DialogFooter>
                  </form>
                )}
              </DialogContent>
            </Dialog>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Email</TableHead>
                  <TableHead>Rola</TableHead>
                  <TableHead>Administrator</TableHead>
                  <TableHead>Data rejestracji</TableHead>
                  <TableHead className="text-right">Akcje</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {users.map((u) => (
                  <TableRow key={u.id}>
                    <TableCell className="font-medium">{u.email}</TableCell>
                    <TableCell>
                      <Badge variant={u.is_admin ? 'default' : 'secondary'}>
                        {u.is_admin ? 'Admin' : 'Użytkownik'}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {u.id !== user?.id ? (
                        <div className="flex items-center gap-2">
                          <Switch
                            checked={u.is_admin}
                            disabled={updatingRoleId === u.id}
                            onCheckedChange={(checked) => handleRoleChange(u.id, checked)}
                          />
                          {updatingRoleId === u.id && (
                            <Loader2 className="h-4 w-4 animate-spin" />
                          )}
                        </div>
                      ) : (
                        <span className="text-muted-foreground text-sm">-</span>
                      )}
                    </TableCell>
                    <TableCell>{u.created_at ? formatDate(u.created_at) : '-'}</TableCell>
                    <TableCell className="text-right">
                      {u.id !== user?.id && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleDeleteUser(u.id)}
                        >
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
