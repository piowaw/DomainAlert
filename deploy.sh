#!/bin/bash
# Deploy script for DomainAlert on Plesk
# This script pulls the latest code from GitHub and deploys it

set -e

# Configuration
DEPLOY_DIR="/var/www/vhosts/yourdomain.com/httpdocs"
REPO_URL="https://github.com/piowaw/DomainAlert.git"
BRANCH="main"

echo "=== DomainAlert Deploy Script ==="
echo "Deploy directory: $DEPLOY_DIR"
echo ""

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

# Protect sensitive files
chmod 600 config.php 2>/dev/null || true
chmod 600 database.sqlite 2>/dev/null || true

echo ""
echo "=== Deploy Complete ==="
echo ""
echo "Post-deploy steps:"
echo "1. Edit config.php with your settings"
echo "2. Set up cron job in Plesk"
echo "3. Subscribe to ntfy topic"
