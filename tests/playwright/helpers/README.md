# Playwright Test Helpers

This directory contains shared utilities and page object models for Playwright E2E tests.

## Directory Structure

```
helpers/
├── page-objects/          # Page Object Models (POM)
│   ├── LoginPage.ts       # Login page interactions
│   ├── DashboardPage.ts   # Dashboard page interactions
│   ├── OldDatasetsPage.ts # Old datasets page interactions
│   ├── CurationPage.ts    # Curation form interactions
│   ├── ResourcesPage.ts   # Resources management interactions
│   ├── SettingsPage.ts    # Settings pages interactions
│   └── index.ts           # Export all page objects
├── test-helpers.ts        # Common test utility functions
└── README.md              # This file
```

## Page Object Models (POM)

Page Objects encapsulate page-specific logic and locators, making tests:
- **More maintainable**: Changes to UI only require updating the page object
- **More readable**: Tests express user intent, not implementation details
- **More reusable**: Common actions are defined once and used everywhere

### Usage Example

```typescript
import { test } from '@playwright/test';
import { loginAsTestUser } from './helpers/test-helpers';
import { DashboardPage, CurationPage } from './helpers/page-objects';

test('user can navigate from dashboard to curation', async ({ page }) => {
  // Login using helper
  await loginAsTestUser(page);
  
  // Use page objects for interactions
  const dashboard = new DashboardPage(page);
  await dashboard.verifyOnDashboard();
  await dashboard.navigateTo('Curation');
  
  const curation = new CurationPage(page);
  await curation.verifyOnCurationPage();
});
```

## Available Page Objects

### LoginPage
Handles authentication flow:
- `goto()` - Navigate to login page
- `login(email, password, rememberMe?)` - Perform login
- `loginAndWaitForDashboard(email, password)` - Login and wait for redirect
- `verifyOnLoginPage()` - Verify we're on the login page
- `verifyErrorDisplayed(errorText?)` - Check for error messages

### DashboardPage
Handles dashboard interactions:
- `goto()` - Navigate to dashboard
- `verifyOnDashboard()` - Verify we're on dashboard
- `uploadXmlFile(filePath)` - Upload XML via dropzone
- `navigateTo(pageName)` - Navigate via main menu
- `verifyNavigationVisible()` - Check navigation menu

### OldDatasetsPage
Handles legacy datasets overview:
- `goto()` - Navigate to old datasets
- `verifyOnOldDatasetsPage()` - Verify we're on the page
- `search(searchTerm)` - Apply search filter
- `applyFilters(filters)` - Apply multiple filters
- `sortBy(field)` - Sort by column
- `loadAuthors(index)` - Load authors into curation form
- `loadDates(index)` - Load dates into curation form
- `loadDescriptions(index)` - Load descriptions into curation form
- `verifyDatabaseError()` - Check for database connection error

### CurationPage
Handles curation form:
- `goto()` - Navigate to curation
- `gotoWithParams(params)` - Navigate with query parameters
- `openAccordion(section)` - Open accordion section
- `addAuthor()` - Add author row
- `fillAuthor(index, data)` - Fill author details
- `addTitle()` - Add title row
- `searchVocabulary(term)` - Search controlled vocabularies
- `selectVocabularyKeyword(keyword)` - Select vocabulary keyword
- `save()` - Save the form
- `verifyFormPopulatedFromUrl(data)` - Verify URL parameters loaded

### ResourcesPage
Handles resources management:
- `goto()` - Navigate to resources
- `verifyOnResourcesPage()` - Verify we're on the page
- `search(searchTerm)` - Search resources
- `createResource()` - Create new resource
- `editResource(index)` - Edit existing resource
- `deleteResource(index, confirm?)` - Delete resource
- `verifyResourceExists(doi)` - Check if resource exists

### SettingsPage
Handles user settings:
- `goto()` - Navigate to settings
- `gotoSection(section)` - Navigate to specific section
- `updateProfile(name, email?)` - Update profile information
- `changePassword(current, new, confirm?)` - Change password
- `changeTheme(theme)` - Change theme (light/dark/system)
- `changeLanguage(language)` - Change language
- `verifySuccess(message?)` - Check for success message
- `verifyError(message?)` - Check for error message

## Test Helper Functions

### Authentication
- `loginAsTestUser(page, email?, password?)` - Quick login as test user
- `logout(page)` - Perform logout

### UI Interactions
- `waitForAccordionState(accordionButton, expanded)` - Wait for accordion state
- `waitForNavigation(page, urlPattern, timeout?)` - Wait for navigation
- `waitForDebounce(page, ms?)` - Wait for debounced actions

### File Utilities
- `resolveDatasetExample(fileName)` - Get path to dataset example file

### Storage
- `clearLocalStorage(page)` - Clear local storage
- `clearSessionStorage(page)` - Clear session storage

### Debugging
- `takeScreenshot(page, name)` - Take full-page screenshot

## Best Practices

### 1. Use Page Objects in Tests
❌ **Bad**: Direct locator usage in tests
```typescript
test('fill author', async ({ page }) => {
  await page.getByLabel('Last name').fill('Doe');
  await page.getByLabel('First name').fill('John');
});
```

✅ **Good**: Use page object methods
```typescript
test('fill author', async ({ page }) => {
  const curation = new CurationPage(page);
  await curation.fillAuthor(0, {
    firstName: 'John',
    lastName: 'Doe',
  });
});
```

### 2. Keep Page Objects Focused
- One page object per page/component
- Methods should represent user actions
- Don't expose internal locators

### 3. Use Helper Functions for Common Tasks
❌ **Bad**: Repeat login in every test
```typescript
test.beforeEach(async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL('/dashboard');
});
```

✅ **Good**: Use helper function
```typescript
test.beforeEach(async ({ page }) => {
  await loginAsTestUser(page);
});
```

### 4. Return Page Objects for Chaining
```typescript
class CurationPage {
  async save(): Promise<ResourcesPage> {
    await this.saveButton.click();
    await this.page.waitForURL(/\/resources/);
    return new ResourcesPage(this.page);
  }
}

// Usage
test('create and verify resource', async ({ page }) => {
  const curation = new CurationPage(page);
  await curation.fillAuthor(0, { lastName: 'Doe' });
  
  const resources = await curation.save();
  await resources.verifyResourceExists('10.1234/example');
});
```

## Adding New Page Objects

1. Create new file in `page-objects/` directory
2. Export class with constructor taking `page: Page`
3. Define locators as `readonly` properties
4. Implement methods for user actions
5. Export from `page-objects/index.ts`

Example template:

```typescript
import { expect, type Locator, type Page } from '@playwright/test';

export class MyPage {
  readonly page: Page;
  readonly heading: Locator;
  readonly submitButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: 'My Page' });
    this.submitButton = page.getByRole('button', { name: 'Submit' });
  }

  async goto() {
    await this.page.goto('/my-page');
  }

  async verifyOnPage() {
    await expect(this.page).toHaveURL(/\/my-page/);
    await expect(this.heading).toBeVisible();
  }

  async submit() {
    await this.submitButton.click();
  }
}
```

## Maintenance

- **Update locators** when UI changes (only in page objects, not in tests)
- **Add methods** when new user actions are needed
- **Deprecate methods** with JSDoc `@deprecated` before removing
- **Keep documentation** in sync with implementation

