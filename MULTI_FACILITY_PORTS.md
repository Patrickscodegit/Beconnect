# Multi-Facility Port Modeling (Mode-Aware Pattern)

## Overview

This document explains how the system models cities that have multiple transport facilities (airport + seaport) sharing the same UN/LOCODE, using a mode-aware resolution pattern.

## Problem Statement

Some cities have both an airport and a seaport that share the same UN/LOCODE. For example:
- **Jeddah** has both King Abdulaziz International Airport (IATA: JED) and Jeddah Islamic Port (seaport)
- Both facilities use the UN/LOCODE **SAJED** (Saudi Arabia, Jeddah)

The challenge is to:
1. Model both facilities correctly without creating fake UN/LOCODEs
2. Resolve port searches correctly based on transport mode (AIR vs SEA)
3. Eliminate ambiguity in pricing flows where mode is known

## Solution: `city_unlocode` + Mode-Aware Resolution

### Database Schema

**New Column**: `city_unlocode` (VARCHAR 5, nullable, indexed)
- **Purpose**: Canonical city UN/LOCODE for grouping facilities
- **Relationship to `unlocode`**:
  - `unlocode` = facility UN/LOCODE (may be city-level)
  - `city_unlocode` = canonical city UN/LOCODE used to group facilities
  - For single-facility cities: `unlocode == city_unlocode`
  - For multi-facility cities: all facilities share the same `city_unlocode`

### Data Model Example: Jeddah

```
Port 1 (Airport):
  - code: JED
  - name: Jeddah
  - port_category: AIRPORT
  - unlocode: SAJED
  - city_unlocode: SAJED
  - iata_code: JED
  - icao_code: OEJN
  - display_name: "Jeddah – Airport (JED)"

Port 2 (Seaport):
  - code: SAJED-SEA (internal code, NOT an official UN/LOCODE)
  - name: Jeddah
  - port_category: SEA_PORT
  - unlocode: SAJED
  - city_unlocode: SAJED
  - iata_code: null
  - icao_code: null
  - display_name: "Jeddah – Seaport"
```

**Important Rules**:
- Do NOT invent fake UN/LOCODEs (e.g., no "JEDP" as UN/LOCODE)
- Keep official codes (IATA, ICAO, UN/LOCODE) intact
- Internal codes like "SAJED-SEA" are acceptable for facility distinction

## Mode-Aware Resolution

### Service Type → Mode Mapping

The system maps `service_type` to port resolution mode:

```php
public static function getModeFromServiceType(?string $serviceType): ?string
{
    if (!$serviceType) {
        return null;
    }
    
    // Air services
    if (in_array($serviceType, ['AIRFREIGHT_EXPORT', 'AIRFREIGHT_IMPORT'])) {
        return 'AIR';
    }
    
    // All other services are sea (RORO, FCL, LCL, BB, etc.)
    return 'SEA';
}
```

### PortResolutionService::resolveOne() Behavior

The `resolveOne(string $input, ?string $mode = null)` method uses mode-aware logic:

1. **IATA Code Match (3 letters)**:
   - Returns AIRPORT where `iata_code` matches
   - **Even if mode is SEA, still returns airport** (IATA is explicit)

2. **UN/LOCODE Match (5 chars)**:
   - Queries active ports where `unlocode` matches
   - If count=1: return it
   - If multiple:
     - `mode='SEA'` => prefer SEA_PORT
     - `mode='AIR'` => prefer AIRPORT
     - `mode=null` => prefer SEA_PORT (default convention)

3. **City/Name/Alias Match**:
   - Resolves via alias or name search
   - If multiple facilities share same `city_unlocode`:
     - `mode='SEA'` => return SEA_PORT for that city
     - `mode='AIR'` => return AIRPORT for that city
     - `mode=null` => return null (ambiguous)

### Examples

| Input | Mode | Result |
|-------|------|--------|
| "JED" | AIR | Jeddah Airport |
| "JED" | SEA | Jeddah Airport (IATA is explicit) |
| "SAJED" | SEA | Jeddah Seaport |
| "SAJED" | AIR | Jeddah Airport |
| "Jeddah" | SEA | Jeddah Seaport |
| "Jeddah" | AIR | Jeddah Airport |
| "Jeddah" | null | null (ambiguous) |

## Pricing Flow Integration

### Key Insight

Our pricing UI already forces `service_type` first:
- `AIRFREIGHT_EXPORT` / `AIRFREIGHT_IMPORT` => airport selection
- All other service types (RORO/FCL/LCL/BB/…) => seaport selection

**Port selection in pricing must be MODE-FILTERED and never ambiguous.**

### Implementation

1. **Filament QuotationRequestResource**:
   - Replaced `config('airports')` with `Port::forAirports()`
   - Port selects determine mode from `service_type` via `getModeFromServiceType()`
   - Apply category filters:
     - `mode='AIR'` => `port_category=AIRPORT`
     - `mode='SEA'` => `port_category=SEA_PORT` + existing scopes
   - Use `PortResolutionService::resolveOne($search, $mode)` for exact matches
   - Display labels with `$port->getDisplayName()`

2. **Livewire QuotationCreator**:
   - Replaced empty collection with `Port::forAirports()` for air services
   - POD logic also uses `Port::forAirports()` for air service types

3. **ProspectQuotationController**:
   - Replaced `config('airports')` with `Port::forAirports()`

## Port Model Enhancements

### New Scopes

```php
// Find all facilities for a city
Port::byCityUnlocode('SAJED')->get();

// Get active airports
Port::forAirports()->get();

// Get active seaports
Port::forSeaports()->get();
```

### New Methods

```php
// Get all facilities for the same city
$port->getCityFacilities();

// Get display name with facility type
$port->getDisplayName();
// Returns:
// - "Jeddah – Airport (JED)" for airports with IATA
// - "Jeddah – Seaport" for seaports
// - Falls back to formatFull() for others
```

## Aliases

### Jeddah-Specific Aliases

- "Jeddah airport" → JED (AIRPORT)
- "Jeddah port" → SAJED-SEA (SEA_PORT)
- "Jeddah seaport" → SAJED-SEA (SEA_PORT)
- "Jeddah Islamic Port" → SAJED-SEA (SEA_PORT)

**Critical Rule**:
- In SEA mode, searching "Jeddah" resolves to the seaport if both exist
- In AIR mode, searching "Jeddah" resolves to the airport
- Mode-aware resolver handles this automatically

## Other Cities with Multiple Facilities

This pattern can be applied to other cities:

- **Dubai**: DXB (airport) + AEDXB (seaport) - both share AEDXB
- **Doha**: DOH (airport) + QADOH (seaport) - both share QADOH
- **Antwerp**: ANR (seaport) + potentially airport
- **Hamburg**: HAM (seaport) + potentially airport
- **Singapore**: SIN (airport) + SGSIN (seaport)

## Migration Guide

### For Existing Data

1. Run migration: `php artisan migrate`
   - Adds `city_unlocode` column
   - Backfills: `city_unlocode = unlocode` where `unlocode` is not null

2. Run seeder: `php artisan db:seed --class=CreateJeddahSeaportSeeder`
   - Creates/updates Jeddah airport (JED)
   - Creates Jeddah seaport (SAJED-SEA)

3. Run aliases seeder: `php artisan db:seed --class=PortAliasesSeeder`
   - Adds Jeddah-specific aliases

### For New Multi-Facility Cities

1. Create/update airport facility:
   - Set `port_category = 'AIRPORT'`
   - Set `unlocode` and `city_unlocode` to city UN/LOCODE
   - Set `iata_code` and `icao_code` if available

2. Create seaport facility:
   - Set `port_category = 'SEA_PORT'`
   - Set `unlocode` and `city_unlocode` to city UN/LOCODE
   - Use internal code like `{UNLOCODE}-SEA` for distinction
   - Do NOT create fake UN/LOCODEs

3. Add aliases:
   - Add facility-specific aliases (e.g., "City airport", "City port")
   - Mode-aware resolver will handle city name searches

## Testing

### Unit Tests

- `resolveOne("JED", "AIR")` returns airport
- `resolveOne("JED", "SEA")` returns airport (IATA is explicit)
- `resolveOne("SAJED", "SEA")` returns seaport
- `resolveOne("SAJED", "AIR")` returns airport
- `resolveOne("Jeddah", "SEA")` returns seaport
- `resolveOne("Jeddah", "AIR")` returns airport
- `resolveOne("Jeddah", null)` returns null if multiple facilities exist
- `resolveByCity("Jeddah")` returns both
- `getModeFromServiceType` mappings correct
- `Port::getDisplayName()` formats correctly

### Integration Tests

- Filament port select in pricing flow filters by mode (AIR shows airports only, SEA shows seaports only)
- Filament port select shows single facility when searching "JED" (regardless of mode, but IATA is airport)
- Backward compatibility: existing "Jeddah" references still work (with mode context)
- Port model replaces `config('airports')` usage

## Benefits

1. **Standards-Compliant**: Uses official UN/LOCODE, IATA, ICAO codes
2. **Scalable**: Pattern works for any city with multiple facilities
3. **Mode-Aware**: Eliminates ambiguity in pricing flows
4. **Unified Model**: All ports (airports + seaports) in single Port model
5. **Backward Compatible**: Existing references work with mode context

## Notes

- Internal codes like "SAJED-SEA" are acceptable for facility distinction
- Do NOT create fake UN/LOCODEs (e.g., no "JEDP" as UN/LOCODE)
- Keep official codes (IATA, ICAO, UN/LOCODE) intact
- `city_unlocode` enables grouping without changing existing `unlocode` values
- **Mode-aware resolution eliminates ambiguity in pricing flows** - this is the key improvement
- **Service type mapping**: `AIRFREIGHT_EXPORT`/`AIRFREIGHT_IMPORT` → 'AIR', all others → 'SEA'

