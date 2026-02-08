# Instalacja DomainAlert na Plesk

## 1. Wymagania

- Plesk z PHP 8.0+ i rozszerzeniami:
  - PDO + SQLite
  - cURL
  - JSON
- Node.js 18+ (do budowania frontendu)
- Dostęp SSH (opcjonalnie, do cron jobs)

## 2. Przygotowanie plików

### Zbuduj frontend lokalnie:
```bash
cd frontend
npm install
npm run build
```

To stworzy folder `frontend/dist` z gotowymi plikami.

### Struktura do uploadu:
```
domainalert/
├── api/                    # z backend/api/
│   └── index.php
├── services/               # z backend/services/
│   ├── WhoisService.php
│   └── NotificationService.php
├── cron/                   # z backend/cron/
│   └── check_domains.php
├── config.php              # z backend/config.php
├── .htaccess               # z backend/.htaccess (WAŻNE!)
└── dist/                   # z frontend/dist/ (po npm run build)
    ├── index.html
    └── assets/
```

## 3. Upload na serwer

1. **Utwórz subdomenę lub katalog** w Plesk (np. `domainalert.twojadomena.pl`)

2. **Wgraj pliki** przez File Manager lub FTP:
   - Backend files → do głównego katalogu domeny
   - Frontend `dist/` → rename na `public` lub do głównego katalogu

3. **Ustaw Document Root** w Plesk na katalog z `index.html`

## 4. Konfiguracja w Plesk

### A) Ustawienia PHP
Wejdź do: **Domains** → **twoja-domena** → **PHP Settings**

Włącz rozszerzenia:
- ✅ pdo_sqlite
- ✅ curl
- ✅ json
- ✅ sockets (opcjonalnie, dla WHOIS przez socket)

### B) Ustawienia Apache/Nginx

#### Dla Apache (.htaccess):
Plik `.htaccess` jest już gotowy. Upewnij się, że:
- `AllowOverride All` jest włączone
- `mod_rewrite` jest aktywny

#### Dla Nginx:
Wejdź do: **Domains** → **twoja-domena** → **Apache & Nginx Settings**

Dodaj w "Additional nginx directives":
```nginx
location /api/ {
    try_files $uri $uri/ /api/index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

### C) Uprawnienia katalogów
```bash
chmod 755 /var/www/vhosts/twojadomena.pl/httpdocs
chmod 644 /var/www/vhosts/twojadomena.pl/httpdocs/*.php
chmod 666 /var/www/vhosts/twojadomena.pl/httpdocs/database.sqlite  # po pierwszym uruchomieniu
```

## 5. Konfiguracja aplikacji

### Edytuj `config.php`:

```php
<?php
// Ścieżka do bazy SQLite (musi być zapisywalna!)
define('DB_PATH', __DIR__ . '/database.sqlite');

// NTFY - zmień na swój unikalny temat
define('NTFY_SERVER', 'https://ntfy.sh');
define('NTFY_TOPIC', 'domainalert-TWOJ-UNIKALNY-TEMAT');

// SMTP dla emaili (opcjonalne)
define('SMTP_HOST', 'mail.twojadomena.pl');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@twojadomena.pl');
define('SMTP_PASS', 'twoje-haslo');
define('SMTP_FROM', 'noreply@twojadomena.pl');
define('SMTP_FROM_NAME', 'DomainAlert');

// JWT Secret - ZMIEŃ NA LOSOWY STRING!
define('JWT_SECRET', 'ZMIEN-NA-DLUGI-LOSOWY-STRING-32-ZNAKI');
define('JWT_EXPIRY', 86400 * 7);
```

### Konfiguracja SMTP dla popularnych dostawców:

#### Gmail:
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'twoj-email@gmail.com');
define('SMTP_PASS', 'haslo-aplikacji');  // NIE zwykłe hasło! Wygeneruj w ustawieniach Google
```

#### Plesk Mail:
```php
define('SMTP_HOST', 'mail.twojadomena.pl');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@twojadomena.pl');
define('SMTP_PASS', 'haslo-skrzynki');
```

## 6. Cron Job (automatyczne sprawdzanie)

### Przez Plesk GUI:
1. Wejdź do: **Domains** → **twoja-domena** → **Scheduled Tasks (Cron Jobs)**
2. Kliknij "Add Task"
3. Wypełnij:
   - **Run**: Co 1 minutę (`* * * * *`)
   - **Command**: 
   ```
   /usr/bin/php /var/www/vhosts/twojadomena.pl/httpdocs/cron/check_domains.php
   ```

### Przez SSH:
```bash
crontab -e
# Dodaj linię:
* * * * * /usr/bin/php /var/www/vhosts/twojadomena.pl/httpdocs/cron/check_domains.php >> /var/log/domainalert.log 2>&1
```

## 7. Testowanie

1. **Otwórz stronę** w przeglądarce: `https://domainalert.twojadomena.pl`

2. **Zaloguj się** domyślnymi danymi:
   - Email: `admin@example.com`
   - Hasło: `admin123`

3. **ZMIEŃ HASŁO!** (na razie przez bazę danych lub dodaj nowego admina)

4. **Testuj powiadomienia**:
   - Zainstaluj aplikację ntfy na telefonie
   - Zasubskrybuj temat pokazany w aplikacji
   - Dodaj domenę testową

## 8. Rozwiązywanie problemów

### "WHOIS nie działa"
- Sprawdź czy cURL jest włączony: `php -m | grep curl`
- Wiele hostingów blokuje port 43 - aplikacja używa RDAP API jako fallback
- Sprawdź logi: `/var/log/apache2/error.log`

### "Powiadomienia nie przychodzą"
1. Sprawdź czy ntfy.sh jest dostępny z serwera:
   ```bash
   curl -X POST -d "test" https://ntfy.sh/twoj-temat
   ```
2. Sprawdź logi cron:
   ```bash
   tail -f /var/log/domainalert.log
   ```
3. Upewnij się, że zasubskrybowałeś właściwy temat w aplikacji ntfy

### "500 Internal Server Error"
1. Sprawdź logi Apache: 
   ```bash
   tail -20 /var/log/apache2/error.log
   ```
2. Sprawdź uprawnienia plików
3. Sprawdź czy wszystkie rozszerzenia PHP są włączone

### "Baza danych nie działa"
- Upewnij się, że katalog ma uprawnienia do zapisu:
  ```bash
  chmod 777 /var/www/vhosts/twojadomena.pl/httpdocs/
  ```
- Po utworzeniu bazy zmień uprawnienia:
  ```bash
  chmod 666 database.sqlite
  chmod 755 /var/www/vhosts/twojadomena.pl/httpdocs/
  ```

### "CORS błędy"
- Sprawdź czy `config.php` jest includowany przed jakimkolwiek outputem
- Upewnij się, że nie ma spacji/BOM przed `<?php`

## 9. Bezpieczeństwo

Po instalacji:
1. ✅ Zmień `JWT_SECRET` na losowy string 32+ znaków
2. ✅ Zmień domyślne hasło admina
3. ✅ Ustaw własny `NTFY_TOPIC`
4. ✅ Skonfiguruj HTTPS (Let's Encrypt w Plesk)
5. ✅ Ogranicz dostęp do `config.php` i `database.sqlite`:

```apache
# Dodaj do .htaccess
<FilesMatch "\.(sqlite|db|php)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</FilesMatch>

<Files "index.php">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
</Files>

<Files "api/index.php">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
</Files>
```

## 10. Aktualizacja

1. Zrób backup `database.sqlite`
2. Wgraj nowe pliki PHP
3. Przebuduj frontend: `npm run build`
4. Wgraj nowy `dist/`
