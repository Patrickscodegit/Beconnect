# Customer Duplicate Management - Quick Visual Guide

## 🎯 What You'll See in Filament

### 1. Duplicate Badge in Table

```
┌────────────────────────────────────────────────────────────────┐
│ Name            │ Role      │ Intakes │ Duplicates            │
├────────────────────────────────────────────────────────────────┤
│ Nancy Deckers   │ FORWARDER │ 3       │ ⚠️ 9 total            │
│ Unknown         │ UNKNOWN   │ 0       │ ⚠️ 31 total           │
│ John Smith      │ POV       │ 5       │ -                     │
└────────────────────────────────────────────────────────────────┘
```

### 2. Header Actions

```
┌─────────────────────────────────────────────────────────────────┐
│  [🔄 Find Duplicates]  [⬇️ Export to CSV]  [🔄 Sync All]      │
└─────────────────────────────────────────────────────────────────┘
```

### 3. Filter Panel

```
Filters:
☐ Has Email
☐ Has Phone
☑️ Has Duplicates  ← New filter!
```

### 4. Bulk Actions (when rows selected)

```
Selected: 9 customers

Actions: [🔀 Merge Duplicates] [🗑️ Delete]
```

## 📋 Step-by-Step: Merge 9 "Nancy Deckers" Duplicates

### Step 1: Find Duplicates
Click **"Find Duplicates"** button

**Result:**
```
⚠️ Duplicates Found!
Found 15 duplicate groups (127 total records). 
Table filtered to show duplicates only. 
Select duplicates and use 'Merge Duplicates' bulk action.
```

### Step 2: Select All "Nancy Deckers"
Table shows only duplicates. Sort by name, select all 9 rows:

```
☑️ Nancy Deckers  │ nancy@example.com    │ Amsterdam  │ NL-12345
☑️ Nancy Deckers  │ nancy.d@company.com  │ Rotterdam  │ NL-67890
☑️ Nancy Deckers  │ -                    │ Amsterdam  │ NEW_abc123
☑️ Nancy Deckers  │ contact@nancy.nl     │ -          │ NL-99999
... (5 more)
```

### Step 3: Click "Merge Duplicates"

**Modal appears:**

```
┌──────────────────────────────────────────────────────────────┐
│ Merge Duplicate Customers                                    │
├──────────────────────────────────────────────────────────────┤
│ Select which record to keep as primary customer.             │
│ All other selected records will be merged into it and        │
│ deleted.                                                     │
│                                                              │
│ Keep this record as primary:                                 │
│                                                              │
│ ● Nancy Deckers • nancy@example.com • Amsterdam • NL-12345   │
│   (Suggested - most complete data)                           │
│                                                              │
│ ○ Nancy Deckers • nancy.d@company.com • Rotterdam • NL-67890 │
│ ○ Nancy Deckers • Amsterdam • NEW_abc123                     │
│ ○ Nancy Deckers • contact@nancy.nl • NL-99999               │
│ ... (5 more)                                                 │
│                                                              │
│ What will happen:                                            │
│ • 8 duplicate(s) will be merged and deleted                  │
│ • 12 intake(s) will be preserved                             │
│ • Non-null fields will be merged into primary record         │
│                                                              │
│        [Cancel]              [Merge] ←                       │
└──────────────────────────────────────────────────────────────┘
```

### Step 4: Confirm Merge

**Success notification:**
```
✅ Customers Merged Successfully
Successfully merged 8 duplicate(s) into Nancy Deckers. 
12 intake(s) preserved.
```

### Step 5: Verify Result

Table now shows only 1 "Nancy Deckers":
```
☑️ Nancy Deckers  │ FORWARDER │ nancy@example.com │ 12  │ -
```

**No more duplicate badge!** ✨

## 🛡️ Protected Delete Example

Try to delete customer with intakes:

```
❌ Cannot delete 'Nancy Deckers' (ID: NL-12345): 
has 12 related intake(s). 
Please merge or reassign intakes first.
```

## 💡 Pro Tips

### Tip 1: Sort by Name
```
Click "Name" column header → duplicates group together
```

### Tip 2: Use Search
```
Search: "nancy"
→ Shows all Nancy entries
→ Select and merge
```

### Tip 3: Check Intakes First
```
Sort by "Intakes" column descending
→ Records with intakes at top
→ Those should be your primary records
```

### Tip 4: Bulk Select
```
[Checkbox in header] → Select all visible
[Shift + Click] → Select range
```

### Tip 5: Export Before Cleanup
```
Click "Export to CSV" 
→ Backup before major cleanup
```

## ⚡ Quick Actions Reference

| Action | Where | What It Does |
|--------|-------|--------------|
| **Find Duplicates** | Header | Filters table to show only duplicates |
| **Has Duplicates** | Filter panel | Toggle duplicate filter on/off |
| **Merge Duplicates** | Bulk action | Merge selected customers |
| **Delete** | Bulk action | Delete (blocks if has intakes) |
| **Duplicate Badge** | Table cell | Shows duplicate count |

## 🎨 Badge Colors

- ⚠️ **Orange/Warning**: Has duplicates
- 🟢 **Green**: No issues
- 🔴 **Red**: Blacklisted or tourist

## 📊 Current Statistics

Your Production Data:
- Total Customers: **4,017**
- Customers with Duplicates: **~200** (estimate)
- Biggest Duplicate Group: **"Unknown" (31 entries)**
- Intakes to Preserve: **All** (automatic)

## 🚀 Recommended Cleanup Order

1. **"Unknown" entries** (31) - Delete ones without intakes
2. **"Nancy Deckers"** (9) - Merge all into one
3. **Other name duplicates** - Work through list
4. **Export final clean list** - Backup

---

**Ready to start?** 
1. Go to Robaws Customers page
2. Click "Find Duplicates"
3. Start merging! 🎉

