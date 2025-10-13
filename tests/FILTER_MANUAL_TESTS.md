# Filter Tests - Manual Verification Guide

This document describes manual test scenarios for the Old Datasets filter functionality.
These tests should be performed with the development server running against real database data.

## Prerequisites

Start the development server:
```bash
composer run dev
```

Navigate to: http://127.0.0.1:8000/old-datasets

## Test Scenarios

### 1. Single Filter - Status (Pending)

**Steps:**
1. Open the Status dropdown
2. Select "Pending"

**Expected Results:**
- Only datasets with status "pending" should be shown in the table
- The count at top right should match the number of displayed rows
- Filter badge should show "Status: Pending" with an X button

### 2. Single Filter - Curator (Admin)

**Steps:**
1. Clear all filters (click X on any active filter badges)
2. Open the Curator dropdown
3. Select "admin"

**Expected Results:**
- Only datasets curated by "admin" should be shown
- Count should match displayed rows
- Filter badge should show "Curator: admin"

### 3. Single Filter - Search

**Steps:**
1. Clear all filters
2. Type "database" into the search field (min. 3 characters)
3. Wait 500ms for debounce

**Expected Results:**
- Only datasets with "database" in title or DOI should be shown
- Count should match displayed rows
- Filter badge should show "Search: database"
- Search should not trigger on every keystroke (debounced)

### 4. Combined Filters - The Critical Test

**Scenario:** search="database" + curator="admin" + status="pending"

**Steps:**
1. Clear all filters
2. Type "database" into search field, wait for debounce
3. Select "admin" from Curator dropdown
4. Select "Pending" from Status dropdown

**Expected Results:**
- THREE filter badges should be visible:
  - "Search: database"
  - "Curator: admin"
  - "Status: Pending"
- ALL displayed datasets must satisfy ALL three conditions:
  - Title or DOI contains "database" (case-insensitive)
  - Curator is exactly "admin"
  - Status is exactly "pending"
- The count at top right must EXACTLY match the number of rows displayed
- Example: If count says "2 datasets total", exactly 2 rows should be visible in the table

**This is the bug reported by the user - verify it's fixed!**

### 5. Filter + Sort Combination

**Steps:**
1. Apply filters: status="released"
2. Click on "Title" column header to sort
3. Verify sorting changes from ↑ to ↓

**Expected Results:**
- Filtered results should be sorted by title
- Count should remain the same as before sorting
- All displayed datasets should still have status="released"

### 6. Clear Filters

**Steps:**
1. Apply multiple filters (e.g., search + curator + status)
2. Click the X button on each filter badge

**Expected Results:**
- Clicking X on a badge should remove that specific filter
- Other filters should remain active
- After removing all filters, full dataset list should be shown

### 7. Resource Type Filter

**Steps:**
1. Clear all filters
2. Select "Dataset" from Resource Type dropdown

**Expected Results:**
- Only items with resourcetypegeneral="Dataset" shown
- Count matches displayed rows

### 8. Empty Results

**Steps:**
1. Enter search term: "xyznonexistent123456"

**Expected Results:**
- No datasets shown
- Count shows "0 datasets total"
- No error messages
- Table shows empty state gracefully

## Verification Checklist

For the combined filter test (#4), verify these specific points:

- [ ] Count at top matches exactly the number of table rows
- [ ] All visible datasets have curator="admin" (check Curator column)
- [ ] All visible datasets have status="pending" (check Status column)  
- [ ] All visible datasets have "database" in title (check Title column)
- [ ] No datasets are shown that don't match ALL criteria
- [ ] Scrolling to load more doesn't break the filters
- [ ] Page refresh maintains the filtered state

## Debug Information

If filters don't work correctly:

1. Open browser DevTools (F12)
2. Go to Network tab
3. Filter requests to: `load-more`
4. Look at the request parameters
5. Verify these parameters are sent as arrays:
   - `status[0]=pending`
   - `curator[0]=admin`
6. Check the Response tab for actual returned data
7. Compare `pagination.total` with `datasets.length`

## Expected URL Parameters

When all three filters are active, the URL should contain:
```
/old-datasets/load-more?
page=1
&per_page=50
&sort_key=updated_at
&sort_direction=desc
&search=database
&curator[0]=admin
&status[0]=pending
```

Note: The `[0]` index in the array parameters is important!

## Success Criteria

✅ All filter badges display correctly  
✅ Count matches displayed rows in ALL scenarios  
✅ Filters apply to both counting AND display  
✅ Search is debounced (500ms, 3 char minimum)  
✅ Status dropdown only shows "Pending" and "Released"  
✅ Combined filters work together (AND logic)  
✅ Removing a filter updates results immediately  
✅ No console errors appear  
✅ No toast notification spam  

## Known Database Values

Based on the metaworks database inspection:

**Status Values (publicstatus column):**
- `pending` - Draft/unpublished datasets
- `released` - Published datasets

**No other status values exist in the database!**

If you see "Published", "Draft", "Review", or "Archived" in the dropdown, that's a bug!
