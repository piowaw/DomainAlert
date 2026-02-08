#!/bin/bash
# Install Ollama and DeepSeek model for DomainAlert AI
# Run this on your server (requires root/sudo)

set -e

echo "=== DomainAlert AI Setup ==="
echo ""

# Check if Ollama is already installed
if command -v ollama &> /dev/null; then
    echo "✓ Ollama is already installed"
    ollama --version
else
    echo "Installing Ollama..."
    curl -fsSL https://ollama.com/install.sh | sh
    echo "✓ Ollama installed"
fi

# Start Ollama service
echo ""
echo "Starting Ollama service..."
if systemctl is-active --quiet ollama 2>/dev/null; then
    echo "✓ Ollama service is already running"
else
    sudo systemctl enable ollama 2>/dev/null || true
    sudo systemctl start ollama 2>/dev/null || true
    # Wait for Ollama to start
    sleep 3
    echo "✓ Ollama service started"
fi

# Pull DeepSeek model
echo ""
echo "Pulling DeepSeek R1 1.5B model (this may take a few minutes)..."
ollama pull deepseek-r1:1.5b
echo "✓ Model downloaded"

# Verify
echo ""
echo "Verifying installation..."
if curl -s http://localhost:11434/api/tags | grep -q "deepseek"; then
    echo "✓ Ollama is running and DeepSeek model is available"
else
    echo "⚠ Ollama might need a moment to initialize. Try again in a few seconds."
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Available models:"
ollama list 2>/dev/null || echo "(could not list models)"
echo ""
echo "Tips:"
echo "  - For better results, use a larger model: ollama pull deepseek-r1:7b"
echo "  - Check status: curl http://localhost:11434/api/tags"
echo "  - View logs: journalctl -u ollama -f"
echo "  - Change model in config.php: define('OLLAMA_MODEL', 'deepseek-r1:7b');"
echo ""
echo "To allow remote access (if PHP runs on different server):"
echo "  Edit /etc/systemd/system/ollama.service"
echo "  Add: Environment=\"OLLAMA_HOST=0.0.0.0\""
echo "  Then: sudo systemctl daemon-reload && sudo systemctl restart ollama"
