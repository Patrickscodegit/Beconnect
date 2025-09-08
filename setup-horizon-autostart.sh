#!/bin/bash

# Setup script to make Horizon start automatically on macOS
# This creates a LaunchAgent that will keep Horizon running

echo "🚀 Setting up Horizon to start automatically..."

# Create LaunchAgents directory if it doesn't exist
mkdir -p ~/Library/LaunchAgents

# Copy the plist file
cp com.bconnect.horizon.plist ~/Library/LaunchAgents/

# Load the service
launchctl load ~/Library/LaunchAgents/com.bconnect.horizon.plist

# Enable the service
launchctl enable gui/$(id -u)/com.bconnect.horizon

echo "✅ Horizon LaunchAgent installed successfully!"
echo ""
echo "📋 Useful commands:"
echo "  • Check status: launchctl list | grep bconnect"
echo "  • Stop service: launchctl stop com.bconnect.horizon"
echo "  • Start service: launchctl start com.bconnect.horizon"
echo "  • Unload service: launchctl unload ~/Library/LaunchAgents/com.bconnect.horizon.plist"
echo ""
echo "🎯 Horizon will now start automatically when you log in!"
