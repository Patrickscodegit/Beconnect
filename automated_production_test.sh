#!/bin/bash

echo "🧪 AUTOMATED PRODUCTION TEST"
echo "════════════════════════════════════════════════════════════════════════════════"
echo ""

BASE_URL="http://bconnect.64.226.120.45.nip.io"
COOKIE_FILE="/tmp/prod_test_cookies.txt"

# Clean up old cookies
rm -f $COOKIE_FILE

echo "📊 Step 1: Testing site accessibility..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/schedules")
echo "   Response: $HTTP_CODE"

if [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
    echo "   ✅ Site is accessible"
else
    echo "   ❌ Site returned unexpected status: $HTTP_CODE"
    exit 1
fi

echo ""
echo "📊 Step 2: Getting CSRF token..."

# Get login page and extract CSRF token
LOGIN_PAGE=$(curl -s -c $COOKIE_FILE "$BASE_URL/login")
CSRF_TOKEN=$(echo "$LOGIN_PAGE" | grep -o 'name="_token"[^>]*value="[^"]*"' | grep -o 'value="[^"]*"' | cut -d'"' -f2)

if [ -z "$CSRF_TOKEN" ]; then
    echo "   ⚠️  Could not extract CSRF token (might be using different auth)"
    echo "   Trying alternative method..."
else
    echo "   ✅ CSRF token obtained"
fi

echo ""
echo "📊 Step 3: Attempting login..."

# Attempt login
LOGIN_RESPONSE=$(curl -s -w "\n%{http_code}" -b $COOKIE_FILE -c $COOKIE_FILE \
    -X POST "$BASE_URL/login" \
    -d "_token=$CSRF_TOKEN" \
    -d "email=patrick@belgaco.be" \
    -d "password=password" \
    -d "remember=on" \
    -L)

LOGIN_CODE=$(echo "$LOGIN_RESPONSE" | tail -1)
echo "   Response: $LOGIN_CODE"

if [ "$LOGIN_CODE" = "200" ]; then
    echo "   ✅ Login successful"
elif [ "$LOGIN_CODE" = "302" ]; then
    echo "   ✅ Login redirect (likely successful)"
else
    echo "   ⚠️  Login status: $LOGIN_CODE"
fi

echo ""
echo "📊 Step 4: Testing schedules API..."

# Test schedules search API
SEARCH_RESPONSE=$(curl -s -w "\n%{http_code}" -b $COOKIE_FILE \
    "$BASE_URL/schedules/search?pol_code=ANR&pod_code=CKY&service_type=BREAKBULK")

SEARCH_CODE=$(echo "$SEARCH_RESPONSE" | tail -1)
SEARCH_BODY=$(echo "$SEARCH_RESPONSE" | head -n -1)

echo "   Response: $SEARCH_CODE"

if [ "$SEARCH_CODE" = "200" ]; then
    echo "   ✅ Schedules API is accessible"
    
    # Try to parse JSON response
    if echo "$SEARCH_BODY" | python3 -m json.tool > /dev/null 2>&1; then
        echo "   ✅ Valid JSON response received"
        
        # Check if we have schedules
        SCHEDULE_COUNT=$(echo "$SEARCH_BODY" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data.get('count', 0))" 2>/dev/null)
        
        if [ ! -z "$SCHEDULE_COUNT" ] && [ "$SCHEDULE_COUNT" -gt 0 ]; then
            echo "   ✅ Found $SCHEDULE_COUNT schedule(s)"
            
            # Try to extract frequency display
            echo ""
            echo "📋 Schedule Details:"
            echo "$SEARCH_BODY" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    carriers = data.get('carriers', {})
    for carrier_code, carrier_data in carriers.items():
        print(f'   Carrier: {carrier_data.get(\"name\", \"Unknown\")}')
        schedules = carrier_data.get('schedules', [])
        for i, schedule in enumerate(schedules[:3], 1):  # Show first 3
            vessel = schedule.get('vessel_name', 'Unknown')
            voyage = schedule.get('voyage_number', '')
            freq = schedule.get('accurate_frequency_display', schedule.get('frequency_display', 'N/A'))
            print(f'   Schedule {i}: {vessel} ({voyage})')
            print(f'      Frequency: {freq}')
            print(f'      Transit: {schedule.get(\"transit_days\", \"N/A\")} days')
except Exception as e:
    print(f'   Error parsing: {e}')
" 2>/dev/null
        else
            echo "   ⚠️  No schedules found (count: $SCHEDULE_COUNT)"
        fi
    else
        echo "   ⚠️  Response is not valid JSON"
    fi
else
    echo "   ❌ Schedules API returned: $SEARCH_CODE"
fi

echo ""
echo "════════════════════════════════════════════════════════════════════════════════"
echo ""
echo "🎯 TEST SUMMARY:"
echo ""
echo "✅ Site Accessibility: PASS"
echo "✅ Login Process: $([ "$LOGIN_CODE" = "200" ] || [ "$LOGIN_CODE" = "302" ] && echo "PASS" || echo "PARTIAL")"
echo "✅ Schedules API: $([ "$SEARCH_CODE" = "200" ] && echo "PASS" || echo "FAIL")"
echo ""
echo "════════════════════════════════════════════════════════════════════════════════"

# Cleanup
rm -f $COOKIE_FILE
