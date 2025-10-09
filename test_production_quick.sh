#!/bin/bash

echo "🧪 QUICK PRODUCTION TEST"
echo "════════════════════════════════════════════════════════════════════════════════"
echo ""

echo "📊 Testing Production Site Accessibility..."
echo "URL: http://bconnect.64.226.120.45.nip.io/schedules"
echo ""

# Test if site is accessible
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://bconnect.64.226.120.45.nip.io/schedules)

echo "Response Code: $HTTP_CODE"
echo ""

if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ Site is accessible and responding"
elif [ "$HTTP_CODE" = "302" ]; then
    echo "✅ Site is accessible (redirecting to login - as expected)"
else
    echo "❌ Site returned unexpected status: $HTTP_CODE"
fi

echo ""
echo "════════════════════════════════════════════════════════════════════════════════"
echo ""
echo "📋 MANUAL TEST INSTRUCTIONS:"
echo ""
echo "1. Open your browser and go to:"
echo "   http://bconnect.64.226.120.45.nip.io/schedules"
echo ""
echo "2. Log in with:"
echo "   Email: patrick@belgaco.be"
echo "   Password: password"
echo ""
echo "3. Test schedule search:"
echo "   - POL: Antwerp, Belgium (ANR)"
echo "   - POD: Conakry, Guinea (CKY)"
echo "   - Click 'Search Schedules'"
echo ""
echo "4. Verify:"
echo "   ✓ Schedules display"
echo "   ✓ Frequency shows 'Bi-weekly service' (not '1.5x/month')"
echo "   ✓ Navigation buttons work"
echo "   ✓ Dates are formatted correctly"
echo ""
echo "════════════════════════════════════════════════════════════════════════════════"
echo ""
echo "📄 Full test checklist saved to: PRODUCTION_TEST_CHECKLIST.md"
echo ""
