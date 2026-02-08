# DomainAlert

Aplikacja do monitorowania wygasających domen z powiadomieniami push przez ntfy.

## Funkcje

- **Dodawanie domen** - pojedynczo lub import z listy (po przecinku, spacjach lub nowych liniach)
- **Automatyczne sprawdzanie WHOIS** - pobiera datę wygaśnięcia domeny
- **Sortowanie po dacie wygaśnięcia** - najszybciej wygasające domeny na górze
- **Powiadomienia push** - przez ntfy.sh gdy domena staje się dostępna
- **System użytkowników** - wspólna baza domen, tylko admin może zapraszać nowych użytkowników

## Wymagania

- PHP 8.0+ z rozszerzeniami: PDO, SQLite, cURL, sockets
- Node.js 18+
- Composer (opcjonalnie)

## Instalacja

### Backend (PHP)

1. Przejdź do katalogu backend:
```bash
cd backend
```

2. Uruchom wbudowany serwer PHP:
```bash
php -S localhost:8000 -t .
```

Aby API routing działał poprawnie, utwórz plik `router.php`:
```bash
php -S localhost:8000 router.php
```

### Frontend (React)

1. Przejdź do katalogu frontend:
```bash
cd frontend
```

2. Zainstaluj zależności:
```bash
npm install
```

3. Uruchom serwer deweloperski:
```bash
npm run dev
```

4. Otwórz http://localhost:5173

### Cron Job (automatyczne sprawdzanie)

Dodaj do crontab aby sprawdzać domeny co minutę:
```bash
* * * * * php /path/to/domainalert/backend/cron/check_domains.php >> /var/log/domainalert.log 2>&1
```

## Domyślne dane logowania

- **Email:** admin@example.com
- **Hasło:** admin123

**WAŻNE:** Zmień hasło po pierwszym logowaniu!

## Konfiguracja

### Backend (config.php)

- `NTFY_SERVER` - Serwer ntfy (domyślnie https://ntfy.sh)
- `NTFY_TOPIC` - Temat ntfy (automatycznie generowany)
- `JWT_SECRET` - Sekret JWT (zmień na produkcji!)
- `JWT_EXPIRY` - Czas ważności tokenu (domyślnie 7 dni)

### Powiadomienia Push

1. Zainstaluj aplikację ntfy na telefonie:
   - [Android (Google Play)](https://play.google.com/store/apps/details?id=io.heckel.ntfy)
   - [iOS (App Store)](https://apps.apple.com/app/ntfy/id1625396347)

2. W aplikacji DomainAlert kliknij "Powiadomienia" i skopiuj temat ntfy

3. W aplikacji ntfy dodaj subskrypcję z tym tematem

## API Endpoints

### Autoryzacja
- `POST /api/auth/login` - Logowanie
- `POST /api/auth/register` - Rejestracja (wymaga zaproszenia)
- `GET /api/auth/me` - Aktualny użytkownik

### Domeny
- `GET /api/domains` - Lista domen
- `POST /api/domains` - Dodaj domenę
- `POST /api/domains/import` - Importuj domeny
- `POST /api/domains/check` - Sprawdź domenę (WHOIS)
- `DELETE /api/domains/:id` - Usuń domenę

### Użytkownicy (admin)
- `GET /api/users` - Lista użytkowników
- `DELETE /api/users/:id` - Usuń użytkownika

### Zaproszenia (admin)
- `GET /api/invitations` - Lista zaproszeń
- `POST /api/invitations` - Utwórz zaproszenie
- `POST /api/invitations/verify` - Sprawdź zaproszenie
- `DELETE /api/invitations/:id` - Usuń zaproszenie

### Powiadomienia
- `GET /api/notifications` - Informacje o subskrypcji ntfy

## Struktura projektu

```
domainalert/
├── backend/
│   ├── api/
│   │   └── index.php       # Główny endpoint API
│   ├── services/
│   │   ├── WhoisService.php    # Serwis WHOIS
│   │   └── NotificationService.php  # Serwis powiadomień ntfy
│   ├── cron/
│   │   └── check_domains.php   # Skrypt cron
│   ├── config.php          # Konfiguracja i funkcje pomocnicze
│   └── .htaccess          # Routing Apache
├── frontend/
│   ├── src/
│   │   ├── components/ui/  # Komponenty shadcn/ui
│   │   ├── pages/         # Strony aplikacji
│   │   ├── hooks/         # React hooks
│   │   ├── lib/           # Biblioteki (API, utils)
│   │   └── App.tsx        # Główny komponent
│   └── package.json
└── README.md
```

## Licencja

MIT
