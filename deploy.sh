#!/bin/bash
# Deploy script for DomainAlert on Plesk
# This script pulls the latest code, deploys it, and sets up Ollama AI

set -e

# Configuration
DEPLOY_DIR="/var/www/vhosts/domainalert.piowaw.com/httpdocs"
REPO_URL="https://github.com/piowaw/DomainAlert.git"
BRANCH="main"
OLLAMA_MODEL="deepseek-r1:1.5b"

echo "╔══════════════════════════════════════════╗"
echo "║     DomainAlert - Full Deploy Script     ║"
echo "╚══════════════════════════════════════════╝"
echo ""
echo "Deploy directory: $DEPLOY_DIR"
echo ""

# ──────────────────────────────────────────
# 1. DEPLOY APPLICATION
# ──────────────────────────────────────────
echo "━━━ [1/5] Deploying application ━━━"

# Check if we're in the deploy directory
if [ ! -d "$DEPLOY_DIR" ]; then
    echo "Error: Deploy directory does not exist: $DEPLOY_DIR"
    exit 1
fi

cd "$DEPLOY_DIR"

# Check if this is a git repository
if [ -d ".git" ]; then
    echo "Pulling latest changes from $BRANCH..."
    git fetch origin
    git reset --hard origin/$BRANCH
else
    echo "Cloning repository..."
    cd ..
    rm -rf httpdocs
    git clone -b $BRANCH $REPO_URL httpdocs
    cd httpdocs
fi

# Copy backend files to public_html root
echo "Copying backend files..."
cp -r backend/* .

# Set permissions
echo "Setting permissions..."
chmod 755 .
chmod 644 *.php 2>/dev/null || true
chmod 755 api 2>/dev/null || true
chmod 644 api/*.php 2>/dev/null || true
chmod 755 services 2>/dev/null || true
chmod 644 services/*.php 2>/dev/null || true
chmod 755 cron 2>/dev/null || true
chmod 644 cron/*.php 2>/dev/null || true
chmod 755 public 2>/dev/null || true

echo "✓ Application deployed"
echo ""

# ──────────────────────────────────────────
# 2. BUILD FRONTEND
# ──────────────────────────────────────────
echo "━━━ [2/5] Building frontend ━━━"

if [ -d "frontend" ]; then
    cd frontend
    
    # Install Node.js if not present
    if ! command -v node &> /dev/null; then
        echo "Node.js not found, installing via nvm..."
        if [ ! -d "$HOME/.nvm" ]; then
            curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
            export NVM_DIR="$HOME/.nvm"
            [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
        fi
        nvm install 20
        nvm use 20
    fi
    
    echo "Node.js version: $(node --version)"
    npm install --silent
    npm run build
    cd ..
    
    # Copy built frontend to public directory
    echo "Copying frontend files..."
    rm -rf public/assets 2>/dev/null || true
    cp -r frontend/dist/* public/
    echo "✓ Frontend built and deployed"
else
    echo "⚠ Frontend directory not found, skipping build"
fi

echo ""

# ──────────────────────────────────────────
# 3. DATABASE & CONFIG
# ──────────────────────────────────────────
echo "━━━ [3/5] Database & configuration ━━━"

# Protect sensitive files
chmod 600 config.php 2>/dev/null || true
chmod 600 database.sqlite 2>/dev/null || true

# Update OLLAMA_MODEL in config.php to llama3.1:8b
if [ -f "config.php" ]; then
    if grep -q "OLLAMA_MODEL.*deepseek" config.php; then
        echo "Updating AI model from deepseek to llama3.1:8b..."
        sed -i "s/define('OLLAMA_MODEL',.*/define('OLLAMA_MODEL', 'llama3.1:8b');/" config.php
        echo "✓ AI model updated to llama3.1:8b"
    else
        echo "✓ AI model already configured: $(grep OLLAMA_MODEL config.php)"
    fi
fi

# Run database migrations
if [ -f "migrate.php" ]; then
    echo "Running database migrations..."
    php migrate.php
fi

echo "✓ Database ready"
echo ""

# ──────────────────────────────────────────
# 4. INSTALL OLLAMA + AI MODEL
# ──────────────────────────────────────────
echo "━━━ [4/5] Setting up Ollama AI ━━━"

install_ollama() {
    # Check if Ollama is already installed
    if command -v ollama &> /dev/null; then
        echo "✓ Ollama already installed: $(ollama --version 2>/dev/null || echo 'version unknown')"
    else
        echo "Installing Ollama..."
        
        # Detect OS
        if [ -f /etc/os-release ]; then
            . /etc/os-release
            OS=$ID
        else
            OS=$(uname -s | tr '[:upper:]' '[:lower:]')
        fi
        
        echo "Detected OS: $OS"
        
        # Install dependencies
        case $OS in
            ubuntu|debian)
                apt-get update -qq
                apt-get install -y -qq curl ca-certificates >/dev/null 2>&1
                ;;
            centos|rhel|almalinux|rocky|fedora)
                yum install -y -q curl ca-certificates >/dev/null 2>&1 || dnf install -y -q curl ca-certificates >/dev/null 2>&1
                ;;
        esac
        
        # Install Ollama
        curl -fsSL https://ollama.com/install.sh | sh
        
        if command -v ollama &> /dev/null; then
            echo "✓ Ollama installed successfully"
        else
            echo "✗ Ollama installation failed"
            return 1
        fi
    fi
}

setup_ollama_service() {
    # Check if systemd is available
    if ! command -v systemctl &> /dev/null; then
        echo "⚠ systemd not found, will run Ollama manually"
        return
    fi
    
    # Create systemd service if not exists
    if [ ! -f /etc/systemd/system/ollama.service ]; then
        echo "Creating Ollama systemd service..."
        cat > /etc/systemd/system/ollama.service << 'SERVICEEOF'
[Unit]
Description=Ollama AI Server
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/ollama serve
Environment="OLLAMA_HOST=127.0.0.1:11434"
Restart=always
RestartSec=3
User=root
Group=root
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
SERVICEEOF
        systemctl daemon-reload
    fi
    
    # Enable and start service
    systemctl enable ollama >/dev/null 2>&1 || true
    
    if systemctl is-active --quiet ollama; then
        echo "✓ Ollama service already running"
    else
        echo "Starting Ollama service..."
        systemctl start ollama
        # Wait for it to be ready
        echo -n "Waiting for Ollama to start"
        for i in $(seq 1 15); do
            if curl -s http://localhost:11434/api/tags >/dev/null 2>&1; then
                echo ""
                echo "✓ Ollama service started"
                return 0
            fi
            echo -n "."
            sleep 2
        done
        echo ""
        echo "⚠ Ollama taking long to start, continuing anyway..."
    fi
}

pull_ai_model() {
    echo "Checking AI model: $OLLAMA_MODEL"
    
    # Wait briefly if Ollama just started
    if ! curl -s http://localhost:11434/api/tags >/dev/null 2>&1; then
        echo "Waiting for Ollama API..."
        sleep 5
    fi
    
    # Check if model already exists
    if curl -s http://localhost:11434/api/tags 2>/dev/null | grep -q "$(echo $OLLAMA_MODEL | cut -d: -f1)"; then
        echo "✓ Model $OLLAMA_MODEL already downloaded"
    else
        echo "Downloading model $OLLAMA_MODEL (this may take a few minutes)..."
        ollama pull $OLLAMA_MODEL
        
        if [ $? -eq 0 ]; then
            echo "✓ Model $OLLAMA_MODEL downloaded"
        else
            echo "✗ Failed to download model"
            return 1
        fi
    fi
    
    # List available models
    echo ""
    echo "Available models:"
    ollama list 2>/dev/null || echo "  (could not list)"
}

# Run installation (needs root for systemd/packages)
if [ "$(id -u)" -eq 0 ]; then
    install_ollama
    setup_ollama_service
    pull_ai_model
else
    echo "⚠ Not running as root. Trying with sudo..."
    if sudo -n true 2>/dev/null; then
        # Has passwordless sudo
        sudo bash -c "$(declare -f install_ollama setup_ollama_service pull_ai_model); OLLAMA_MODEL='$OLLAMA_MODEL'; install_ollama && setup_ollama_service && pull_ai_model"
    else
        echo ""
        echo "⚠ Cannot install Ollama without root access."
        echo "  Run manually as root:"
        echo "    sudo bash setup-ai.sh"
        echo "  Or install Ollama manually:"
        echo "    curl -fsSL https://ollama.com/install.sh | sudo sh"
        echo "    ollama pull $OLLAMA_MODEL"
    fi
fi

echo ""

# ──────────────────────────────────────────
# 5. SETUP CRON JOBS
# ──────────────────────────────────────────
echo "━━━ [5/5] Setting up cron jobs ━━━"

CRON_DIR="$DEPLOY_DIR/cron"
WORKER="$DEPLOY_DIR/worker.php"

# Check if cron jobs already exist
EXISTING_CRON=$(crontab -l 2>/dev/null || echo "")

setup_cron() {
    local CRON_UPDATED=false
    local NEW_CRON="$EXISTING_CRON"
    
    # Add worker cron (every minute)
    if ! echo "$EXISTING_CRON" | grep -q "worker.php"; then
        echo "Adding worker cron job..."
        NEW_CRON="$NEW_CRON
* * * * * php $WORKER >> /var/log/domainalert-worker.log 2>&1"
        CRON_UPDATED=true
    else
        echo "✓ Worker cron already exists"
    fi
    
    # Add daily WHOIS check cron
    if [ -f "$CRON_DIR/check_domains.php" ] && ! echo "$EXISTING_CRON" | grep -q "check_domains.php"; then
        echo "Adding daily WHOIS check cron job..."
        NEW_CRON="$NEW_CRON
0 3 * * * php $CRON_DIR/check_domains.php >> /var/log/domainalert-cron.log 2>&1"
        CRON_UPDATED=true
    else
        echo "✓ WHOIS check cron already configured"
    fi
    
    if [ "$CRON_UPDATED" = true ]; then
        echo "$NEW_CRON" | crontab -
        echo "✓ Cron jobs configured"
    fi
}

setup_cron

echo ""

# ──────────────────────────────────────────
# VERIFY INSTALLATION
# ──────────────────────────────────────────
echo "╔══════════════════════════════════════════╗"
echo "║         Installation Summary             ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# Check application
if [ -f "$DEPLOY_DIR/public/index.html" ]; then
    echo "  ✓ Frontend deployed"
else
    echo "  ✗ Frontend missing"
fi

if [ -f "$DEPLOY_DIR/api/index.php" ]; then
    echo "  ✓ Backend API ready"
else
    echo "  ✗ Backend API missing"
fi

if [ -f "$DEPLOY_DIR/database.sqlite" ]; then
    echo "  ✓ Database exists"
else
    echo "  ⚠ Database will be created on first request"
fi

# Check Ollama
if curl -s http://localhost:11434/api/tags >/dev/null 2>&1; then
    echo "  ✓ Ollama AI running"
    MODEL_COUNT=$(curl -s http://localhost:11434/api/tags | grep -o '"name"' | wc -l)
    echo "    Models: $MODEL_COUNT available"
else
    echo "  ✗ Ollama not running"
fi

# Check PHP extensions
echo ""
echo "PHP extensions:"
for EXT in curl pdo_sqlite json mbstring; do
    if php -m 2>/dev/null | grep -qi "$EXT"; then
        echo "  ✓ $EXT"
    else
        echo "  ✗ $EXT (required!)"
    fi
done

echo ""
echo "━━━ Deploy Complete! ━━━"
echo ""
echo "Your app: https://domainalert.piowaw.com"
echo ""
