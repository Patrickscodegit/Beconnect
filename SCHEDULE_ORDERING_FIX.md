# Schedule Ordering & Initial View Fix

## Issue Summary
Schedules were being displayed in random order (closest to today first), making it difficult to browse chronologically. The initial view always started at the first schedule instead of the most relevant one (closest to today).

## User Requirement

### Desired Behavior
1. **Chronological Order:** Display schedules from oldest to latest departure
   - Schedule 1: Earliest ETS
   - Schedule 2: Next ETS
   - Schedule 3: Later ETS
   - Schedule N: Latest ETS

2. **Smart Initial View:** Start viewing the schedule with ETS closest to today's date
   - If today is Oct 8 and schedules are: Sep 2, Sep 21, Oct 3, Oct 14, Nov 7
   - Initial view should show: **Oct 3** (schedule #3)
   - User can click "Previous" to see Sep 21, Sep 2 (past sailings)
   - User can click "Next" to see Oct 14, Nov 7 (future sailings)

## Previous Behavior

### Backend ✅
```php
// ScheduleController.php Line 150
$schedules = $query->orderBy('ets_pol', 'asc')->get();
```
Backend was already correct - sorted by ETS ascending (oldest first)

### Frontend ❌
```javascript
// index.blade.php Lines 456-467
allSchedules.sort((a, b) => {
    // Calculate days from today
    const daysA = Math.abs((dateA - today) / (1000 * 60 * 60 * 24));
    const daysB = Math.abs((dateB - today) / (1000 * 60 * 60 * 24));
    
    return daysA - daysB;  // Closest to today first
});

currentScheduleIndex = 0;  // Always start at first
```

**Problem:** 
- Frontend re-sorted to show closest to today FIRST
- Always started at index 0 (which after sorting was the closest date)
- Made it confusing to browse past vs future sailings

### Example Issue

**5 Antwerp → Durban schedules (today is Oct 8):**

Backend order (oldest first):
```
1. Piranha (Sep 2)    ← Oldest
2. Ocean Breeze (Sep 21)
3. Silver Sun (Oct 3)  ← Closest to today
4. Silver Glory (Oct 14)
5. Piranha (Nov 7)     ← Latest
```

Frontend re-sorted to:
```
1. Silver Sun (Oct 3)     ← Closest (5 days away)
2. Silver Glory (Oct 14)  ← 6 days away
3. Ocean Breeze (Sep 21)  ← 17 days away
4. Piranha (Nov 7)        ← 30 days away
5. Piranha (Sep 2)        ← 36 days away (oldest becomes last!)
```

**Result:** User sees Oct 3 first, but clicking "Next" shows Oct 14, then Sep 21 (going backwards in time!) - Very confusing!

## Solution Implemented

### 1. Removed Frontend Re-Sorting
**File:** `resources/views/schedules/index.blade.php` (Lines 456-467)

**OLD:**
```javascript
// Sort schedules by ETS date (closest to today first)
allSchedules.sort((a, b) => {
    const daysA = Math.abs((dateA - today) / (1000 * 60 * 60 * 24));
    const daysB = Math.abs((dateB - today) / (1000 * 60 * 60 * 24));
    return daysA - daysB;
});

currentScheduleIndex = 0;
```

**NEW:**
```javascript
// Schedules are already sorted chronologically by backend (oldest to latest)
// No need to re-sort - just find the schedule closest to today for initial view

// Find the schedule with ETS closest to today's date
currentScheduleIndex = findClosestScheduleIndex(allSchedules);
```

### 2. Added Smart Index Finder
**File:** `resources/views/schedules/index.blade.php` (New function before displayCurrentSchedule)

```javascript
// Find the schedule index with ETS closest to today's date
function findClosestScheduleIndex(schedules) {
    if (schedules.length === 0) return 0;
    
    const today = new Date();
    today.setHours(0, 0, 0, 0); // Normalize to start of day
    
    let closestIndex = 0;
    let smallestDiff = Infinity;
    
    schedules.forEach((schedule, index) => {
        const etsDate = new Date(schedule.ets_pol);
        etsDate.setHours(0, 0, 0, 0); // Normalize to start of day
        
        const diff = Math.abs(etsDate - today);
        
        if (diff < smallestDiff) {
            smallestDiff = diff;
            closestIndex = index;
        }
    });
    
    return closestIndex;
}
```

**How it works:**
1. Iterates through all schedules in their backend order
2. Calculates absolute difference between each ETS and today
3. Finds the schedule with smallest difference
4. Returns that schedule's index
5. Sets `currentScheduleIndex` to that index

## New Behavior

### Example: Antwerp → Durban (today is Oct 8)

**Schedules in order (oldest to latest):**
```
Index 0: Piranha (Sep 2)      ← -36 days (past)
Index 1: Ocean Breeze (Sep 21) ← -17 days (past)
Index 2: Silver Sun (Oct 3)    ← -5 days (CLOSEST!)
Index 3: Silver Glory (Oct 14) ← +6 days (future)
Index 4: Piranha (Nov 7)       ← +30 days (future)
```

**Initial view:** `currentScheduleIndex = 2` (Silver Sun, Oct 3)

**Display:**
```
[← Previous] [Next →]
3 of 5

Vessel: Silver Sun (25SU09)
ETS: Oct 3, 2025
```

**Navigation:**
- Click "Previous" → Shows Ocean Breeze (Sep 21) - schedule 2
- Click "Previous" → Shows Piranha (Sep 2) - schedule 1
- Click "Previous" → Disabled (at first schedule)
- Go back to Silver Sun, click "Next" → Shows Silver Glory (Oct 14) - schedule 4
- Click "Next" → Shows Piranha (Nov 7) - schedule 5
- Click "Next" → Disabled (at last schedule)

**Benefits:**
✅ Chronological order maintained (Sep → Oct → Nov)  
✅ Initial view shows most relevant schedule (closest to today)  
✅ "Previous" always goes to earlier dates  
✅ "Next" always goes to later dates  
✅ Intuitive navigation flow  

## Test Cases

### Test Case 1: All Future Sailings
**Schedules:** Oct 15, Oct 20, Nov 1, Nov 15  
**Today:** Oct 8  
**Expected:** Start at Oct 15 (index 0, closest future sailing)

### Test Case 2: All Past Sailings
**Schedules:** Sep 1, Sep 10, Sep 20, Sep 30  
**Today:** Oct 8  
**Expected:** Start at Sep 30 (index 3, most recent past sailing)

### Test Case 3: Mix of Past and Future
**Schedules:** Sep 2, Sep 21, Oct 3, Oct 14, Nov 7  
**Today:** Oct 8  
**Expected:** Start at Oct 3 (index 2, 5 days before today)

### Test Case 4: Today's Sailing
**Schedules:** Oct 1, Oct 8, Oct 15, Oct 22  
**Today:** Oct 8  
**Expected:** Start at Oct 8 (index 1, exact match)

## Implementation Details

### Date Normalization
```javascript
today.setHours(0, 0, 0, 0);
etsDate.setHours(0, 0, 0, 0);
```
- Removes time component
- Ensures accurate day-level comparison
- Prevents timezone issues

### Absolute Difference
```javascript
const diff = Math.abs(etsDate - today);
```
- Uses absolute value so past and future dates are compared fairly
- Past sailing 5 days ago = same distance as future sailing 5 days ahead
- If equidistant, earlier schedule wins (first found in loop)

### Index-Based Navigation
- Maintains backend's chronological order
- `currentScheduleIndex` acts as position in timeline
- Decrement = go backwards in time
- Increment = go forwards in time

## Files Modified

1. **`resources/views/schedules/index.blade.php`**
   - Removed re-sorting logic (Lines 456-467)
   - Added `findClosestScheduleIndex()` function (Lines 467-490)
   - Changed `currentScheduleIndex` initialization to use smart finder

## Verification Steps

1. **Hard refresh browser:** `Cmd+Shift+R`
2. **Search route:** Antwerp → Durban
3. **Verify:** Should show Silver Sun (Oct 3) initially
4. **Click Previous:** Should show Ocean Breeze (Sep 21)
5. **Click Previous:** Should show Piranha (Sep 2)
6. **Click Next 3 times:** Should return to Oct 3, then Oct 14, then Nov 7

## Benefits

| Aspect | Before | After |
|--------|--------|-------|
| Display order | Closest first (random) | Chronological (oldest→latest) |
| Initial view | Always first in list | Closest to today |
| Previous button | Random direction | Always earlier dates |
| Next button | Random direction | Always later dates |
| User experience | Confusing | Intuitive |

---

**Status:** ✅ **COMPLETED**  
**Impact:** High - Improves UX significantly  
**Test Status:** Logic verified with Antwerp → Durban example

