# Konfiguracja automatycznego deploya z GitHub dla Pleska

## Metoda 1: Git w Plesku (Zalecana)

### Krok 1: Przygotowanie repozytorium
1. Upewnij się, że kod jest na GitHub: `https://github.com/piowaw/DomainAlert.git`

### Krok 2: Konfiguracja w Plesku
1. Zaloguj się do Pleska
2. Wybierz domenę
3. Przejdź do **Git** (w sekcji "Files")
4. Kliknij **Add Repository**
5. Wklej URL: `https://github.com/piowaw/DomainAlert.git`
6. Wybierz branch: `main`
7. Ustaw **Deployment path**: `/httpdocs`
8. Zaznacz **Automatically deploy from the repository on Plesk when an update is pushed to the remote repository**
9. Kliknij **OK**

### Krok 3: Webhook (automatyczny deploy przy push)
Po dodaniu repozytorium, Plesk pokaże URL webhooka.
1. Skopiuj URL webhooka z Pleska
2. Idź do GitHub → Settings → Webhooks → Add webhook
3. Wklej URL webhooka
4. Content type: `application/json`
5. Wybierz: "Just the push event"
6. Kliknij **Add webhook**

### Krok 4: Post-deploy w Plesku
W ustawieniach Git w Plesku dodaj **Additional deployment actions**:

```bash
# Kopiuj pliki backendu do głównego katalogu
cp -r backend/* ./
cp -r backend/public/* ./

# Ustaw uprawnienia
chmod 600 config.php
chmod 600 database.sqlite 2>/dev/null || true

# Utwórz bazę danych jeśli nie istnieje
if [ ! -f database.sqlite ]; then
    touch database.sqlite
    chmod 600 database.sqlite
fi
```

---

## Metoda 2: Ręczny deploy przez SSH

### Pierwszy deploy:
```bash
cd /var/www/vhosts/twojadomena.pl/httpdocs
git clone https://github.com/piowaw/DomainAlert.git .
cp -r backend/* ./
cp config.php.example config.php
nano config.php  # Edytuj konfigurację
chmod 600 config.php
```

### Aktualizacja:
```bash
cd /var/www/vhosts/twojadomena.pl/httpdocs
git pull origin main
cp -r backend/* ./
```

---

## Metoda 3: Skrypt deploy.sh

Użyj załączonego skryptu `deploy.sh`:

```bash
# Edytuj zmienne w deploy.sh
nano deploy.sh

# Uruchom
chmod +x deploy.sh
./deploy.sh
```

---

## Konfiguracja po deployu

### 1. Edytuj config.php
```bash
nano config.php
```

Zmień:
- `JWT_SECRET` - unikalny losowy ciąg
- `NTFY_TOPIC` - twój temat ntfy
- SMTP (opcjonalnie) - dane serwera email

### 2. Dodaj cron w Plesku
Przejdź do: Domena → Scheduled Tasks → Add Task

**Zadanie 1 - Sprawdzanie domen (co 5 minut):**
- Command: `php /var/www/vhosts/twojadomena.pl/httpdocs/cron/check_domains.php`
- Schedule: `*/5 * * * *`

**Zadanie 2 - Sprawdzanie wygasających (co minutę, opcjonalnie):**
- Command: `php /var/www/vhosts/twojadomena.pl/httpdocs/cron/check_domains.php`
- Schedule: `* * * * *`

### 3. Zasubskrybuj ntfy
- Pobierz aplikację ntfy (iOS/Android/Web)
- Zasubskrybuj temat z config.php

---

## Struktura plików po deployu

```
httpdocs/
├── api/
│   └── index.php
├── services/
│   ├── WhoisService.php
│   └── NotificationService.php
├── cron/
│   └── check_domains.php
├── public/
│   ├── index.html
│   └── assets/
├── config.php
├── .htaccess
└── database.sqlite
```
