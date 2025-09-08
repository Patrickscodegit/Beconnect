#!/bin/bash

# Development server startup script
# This script starts both Laravel development server and Horizon queue processor

echo "🚀 Starting Bconnect Development Environment..."

# Function to cleanup processes on exit
cleanup() {
    echo ""
    echo "🛑 Shutting down services..."
    
    # Kill Laravel server
    if [ ! -z "$SERVER_PID" ]; then
        echo "  Stopping Laravel server (PID: $SERVER_PID)..."
        kill $SERVER_PID 2>/dev/null
    fi
    
    # Kill Horizon
    if [ ! -z "$HORIZON_PID" ]; then
        echo "  Stopping Horizon (PID: $HORIZON_PID)..."
        kill $HORIZON_PID 2>/dev/null
        # Also terminate horizon gracefully
        php artisan horizon:terminate 2>/dev/null
    fi
    
    echo "✅ All services stopped."
    exit 0
}

# Set up signal traps
trap cleanup SIGINT SIGTERM

# Start Laravel development server in background
echo "🌐 Starting Laravel development server on http://127.0.0.1:8000..."
php artisan serve &
SERVER_PID=$!

# Wait a moment for server to start
sleep 2

# Start Horizon in background
echo "⚡ Starting Horizon queue processor..."
php artisan horizon &
HORIZON_PID=$!

# Wait a moment for Horizon to start
sleep 2

# Display status
echo ""
echo "✅ Development environment is running!"
echo ""
echo "📊 Services Status:"
echo "  📱 Laravel Server: http://127.0.0.1:8000 (PID: $SERVER_PID)"
echo "  ⚡ Horizon Queues: Running (PID: $HORIZON_PID)"
echo "  🎛️  Admin Panel: http://127.0.0.1:8000/admin"
echo ""
echo "💡 Useful commands:"
echo "  • Check Horizon: php artisan horizon:status"
echo "  • Monitor queues: Open Horizon dashboard at /horizon"
echo "  • View logs: tail -f storage/logs/laravel.log"
echo ""
echo "Press Ctrl+C to stop all services..."

# Keep script running and monitor processes
while true; do
    # Check if Laravel server is still running
    if ! kill -0 $SERVER_PID 2>/dev/null; then
        echo "❌ Laravel server stopped unexpectedly!"
        cleanup
    fi
    
    # Check if Horizon is still running
    if ! kill -0 $HORIZON_PID 2>/dev/null; then
        echo "❌ Horizon stopped unexpectedly!"
        cleanup
    fi
    
    # Wait before next check
    sleep 5
done
