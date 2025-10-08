# Schedule Fields Implementation

## Overview
Added DEPARTURE_TERMINAL, POL, and POD fields to the shipping schedule detail card and copy-to-clipboard functionality.

## Implementation Details

### Fields Added

#### 1. DEPARTURE_TERMINAL
- **Value:** `332` (hardcoded)
- **Scope:** Sallaum Lines only
- **Display:** Only shown for `carrier.code === 'SALLAUM'`
- **Other Carriers:** Hidden (will be configured later per user request)

#### 2. POL (Port of Loading)
- **Value:** `${schedule.pol_port.code} - ${schedule.pol_port.name}`
- **Example:** `ANR - Antwerp`
- **Scope:** All carriers
- **Source:** Existing `pol_port` relationship data

#### 3. POD (Port of Discharge)
- **Value:** `${schedule.pod_port.code} - ${schedule.pod_port.name}`
- **Example:** `CKY - Conakry`
- **Scope:** All carriers
- **Source:** Existing `pod_port` relationship data

### Frontend Changes

#### File: `resources/views/schedules/index.blade.php`

**Lines 551-566:** Added new fields to schedule detail card
```javascript
${schedule.carrier.code === 'SALLAUM' ? `
<div class="detail-row">
    <span class="label">DEPARTURE_TERMINAL:</span>
    <span class="value">332</span>
</div>
` : ''}

<div class="detail-row">
    <span class="label">POL:</span>
    <span class="value">${schedule.pol_port.code} - ${schedule.pol_port.name}</span>
</div>

<div class="detail-row">
    <span class="label">POD:</span>
    <span class="value">${schedule.pod_port.code} - ${schedule.pod_port.name}</span>
</div>
```

**Lines 628-630:** Added new fields to copy-to-clipboard
```javascript
${schedule.carrier.code === 'SALLAUM' ? 'DEPARTURE_TERMINAL: 332' : ''}
POL: ${schedule.pol_port.code} - ${schedule.pol_port.name}
POD: ${schedule.pod_port.code} - ${schedule.pod_port.name}
```

### Field Positioning

The new fields are positioned in the schedule detail card as follows:

1. Transit Time
2. Next Sailing
3. **DEPARTURE_TERMINAL** (Sallaum only)
4. **POL** (All carriers)
5. **POD** (All carriers)
6. Vessel
7. ETS
8. ETA

### Copy-to-Clipboard Format

**For Sallaum Lines:**
```
Service: Europe to Africa
Frequency: 4.0x/month
DEPARTURE_TERMINAL: 332
POL: ANR - Antwerp
POD: CKY - Conakry
Transit Time: 10 days
Next Sailing: Sep 2, 2025
Vessel: Piranha (25PA09)
ETS: Sep 2, 2025
ETA: Sep 12, 2025
```

**For Other Carriers:**
```
Service: Europe to Africa
Frequency: 4.0x/month
POL: ANR - Antwerp
POD: CKY - Conakry
Transit Time: 10 days
Next Sailing: Sep 2, 2025
Vessel: Vessel Name (Voyage123)
ETS: Sep 2, 2025
ETA: Sep 12, 2025
```

### Data Source

The implementation uses existing database relationships:

- **POL Data:** `schedule.pol_port.code` and `schedule.pol_port.name`
- **POD Data:** `schedule.pod_port.code` and `schedule.pod_port.name`
- **Carrier Data:** `schedule.carrier.code` (for conditional DEPARTURE_TERMINAL display)

### Conditional Logic

```javascript
// Only show DEPARTURE_TERMINAL for Sallaum Lines
${schedule.carrier.code === 'SALLAUM' ? `
    <div class="detail-row">
        <span class="label">DEPARTURE_TERMINAL:</span>
        <span class="value">332</span>
    </div>
` : ''}
```

### Benefits

1. **Enhanced Information:** Users can now see POL and POD details directly in the schedule card
2. **Carrier-Specific Data:** DEPARTURE_TERMINAL only shows for Sallaum Lines as requested
3. **Consistent Format:** Both display and copy-to-clipboard use the same format
4. **Future-Ready:** Easy to add DEPARTURE_TERMINAL for other carriers when needed
5. **No Database Changes:** Uses existing relationship data, no migration required

### Testing

**Verified:**
- ✅ Sallaum schedules show DEPARTURE_TERMINAL: 332
- ✅ All schedules show POL: Code - Name
- ✅ All schedules show POD: Code - Name
- ✅ Copy-to-clipboard includes all new fields
- ✅ Conditional logic works correctly
- ✅ No linter errors

### Future Enhancements

When other carriers are added:
1. Update conditional logic to include their carrier codes
2. Add their specific DEPARTURE_TERMINAL values
3. Example: `schedule.carrier.code === 'GRIMALDI' ? 'DEPARTURE_TERMINAL: 123' : ''`

---

**Status:** ✅ **COMPLETED**  
**Files Modified:** `resources/views/schedules/index.blade.php`  
**No Database Changes Required:** Uses existing relationship data
