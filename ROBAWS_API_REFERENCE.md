# Robaws API & Webhooks Reference

## 📚 Table of Contents

1. [API Overview](#api-overview)
2. [Authentication](#authentication)
3. [API Documentation Links](#api-documentation-links)
4. [Rate Limiting](#rate-limiting)
5. [Webhooks](#webhooks)
6. [Articles API](#articles-api)
7. [Filtering, Paging & Sorting](#filtering-paging--sorting)
8. [Updating Data (PUT vs PATCH)](#updating-data-put-vs-patch)
9. [Common Endpoints](#common-endpoints)
10. [Event Types](#event-types)

---

## API Overview

### Two Types of Integrations

1. **Custom Integration** - Built for one specific customer
   - Authentication: HTTP Basic Authentication
   - Create a separate "API only" user (free of charge)
   - Best practice: Create separate role with minimal required permissions

2. **Marketplace Integration** - Standard integration available to all Robaws customers
   - Authentication: OAuth2 Authorization Code flow
   - Requirements:
     - Do not refresh access tokens until they are (almost) expired
     - Build against Robaws test tenant first
     - Demo video required for production
     - Use webhooks instead of polling
     - Provide URL for OAuth2 flow initiation

### Test Tenants

- Available free of charge for 14 days
- Period can be extended if needed
- Contact: support@robaws.be

---

## Authentication

### HTTP Basic Authentication (Custom Integrations)

```http
Authorization: Basic xxxxxxxxxx
```

**Best Practice:**
- Create a separate user in the customer's Robaws environment
- Check the "API only" box (free of charge)
- Create a dedicated role with minimal required permissions

### OAuth2 (Marketplace Integrations)

- OAuth2 Authorization Code flow
- Client ID & Client Secret required
- Contact support@robaws.be to get started

---

## API Documentation Links

- **Main API Reference**: https://app.robaws.com/public/api-docs/robaws
- **Support Portal**: https://support.robaws.com
- **API Changelog**: https://support.robaws.com/nl/article/api-changelog-1894zg9/

---

## Rate Limiting

- HTTP 429 errors are monitored
- If 429 errors occur too often, Robaws team will temporarily block the integration
- Must implement proper rate limiting handling
- Check response headers for rate limiting information

**Best Practices:**
- Implement exponential backoff on 429 responses
- Use webhooks instead of polling
- Cache responses when appropriate

---

## Webhooks

### Registration

**Endpoint**: `POST /api/v2/webhook-endpoints`

**Requirements:**
- Only admin users can register endpoints
- Registration currently only available via API (no UI)
- Store the secret returned on endpoint creation (needed for signature verification)

### Webhook Request Format

```http
POST https://your-host/your-webhook-endpoint
Content-Type: application/json
Robaws-Signature: t=1674742714,v1=signature

{
  "id": "37de8552-0007-4269-a76c-06a9415c8b65",
  "event": "client.updated",
  "data": {
    ...
  }
}
```

### Signature Verification

**IMPORTANT**: Always verify the `Robaws-Signature` header to ensure the request is from Robaws.

#### Step 1: Extract timestamp and signature

```php
// Split header by ','
$elements = explode(',', $header);

// Extract values
foreach ($elements as $element) {
    [$prefix, $value] = explode('=', $element);
    if ($prefix === 't') {
        $timestamp = $value;
    } elseif ($prefix === 'v1') {
        $signature = $value;
    }
}
```

#### Step 2: Prepare signed_payload string

```php
$signed_payload = $timestamp . '.' . $request_body;
```

#### Step 3: Calculate expected signature

```php
$expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);
```

#### Step 4: Compare signatures

```php
// Use constant-time comparison to protect against timing attacks
if (hash_equals($expected_signature, $signature)) {
    // Verify timestamp is within tolerance
    $tolerance = 300; // 5 minutes
    if (abs(time() - $timestamp) <= $tolerance) {
        // Valid webhook
    }
}
```

### Webhook Behavior

- **Success Response**: Return any 2XX status code
- **Retry Logic**: Robaws will retry several times if endpoint fails to respond successfully
- **Timeouts**:
  - Connection timeout: 2 seconds
  - Read timeout: 2 seconds
- **Suspension**: If no successful responses are ever received, webhook may be suspended without warning
- **Testing**: Use https://webhook.site for easy testing

### Supported Event Types

#### Clients
- `client.created`
- `client.updated`

#### Sales Invoices
- `sales-invoice.created`
- `sales-invoice.updated`

#### Offers
- `offer.created`
- `offer.updated`
- `offer.recalculated`

#### Settlements
- `settlement.created`
- `settlement.updated`
- `settlement.recalculated`

#### Projects
- `project.created`
- `project.updated`

#### Articles ⭐
- `article.created`
- `article.updated`
- `article.stock-changed`

#### Employees
- `employee.created`
- `employee.updated`

#### Planning Items
- `planning-item.created`
- `planning-item.updated`

#### Work Orders
- `work-order.created`
- `work-order.updated`

#### Materials
- `material.created`
- `material.updated`

#### Installations
- `installation.created`
- `installation.updated`

#### Documents
- `document.created`
- `document.updated`

#### Comments
- `comment.created`
- `comment.updated`

#### Sales Orders
- `sales-order.created`
- `sales-order.updated`

**Note**: If you need another event type, contact support@robaws.be

---

## Articles API

### Get All Articles

```http
GET /api/v2/articles
```

### Get Single Article

```http
GET /api/v2/articles/{id}
```

### Article Fields

Based on our implementation and API structure:

#### Standard Fields
- `id` - Unique article ID
- `name` - Article name
- `saleName` - Sales name/description
- `description` - Full description
- `brand` - Brand name
- `articleNumber` - Article number
- `barcode` - Barcode
- `vatTariffId` - VAT tariff ID
- `articleGroupId` - Article group ID
- `unitType` - Unit type (piece, kg, etc.)

#### Pricing
- `salePrice` - Sale price
- `costPrice` - Cost price
- `salePriceStrategy` - Pricing strategy (FIXED_PRICE, etc.)
- `costPriceStrategy` - Cost pricing strategy
- `margin` - Profit margin percentage

#### Stock & Operations
- `stockArticle` - Whether article tracks stock (boolean)
- `stockPlace` - Stock location
- `availableStock` - Available stock quantity
- `reservedStock` - Reserved stock quantity
- `backorderStock` - Backorder stock quantity
- `totalStock` - Total stock quantity
- `targetStock` - Target stock level
- `minimumStock` - Minimum stock level

#### Product Attributes
- `weightKg` - Weight in kilograms
- `timeOperation` - Time-based operation flag (boolean)
- `installation` - Installation required flag (boolean)
- `wappy` - Wappy integration flag (boolean)
- `imageId` - Image reference ID

#### Additional Data
- `additionalItems` - Additional/composite items (may not be available via API)
- `extraFields` - Custom fields defined in Robaws
- `createdAt` - Creation timestamp
- `updatedAt` - Last update timestamp

#### Relationships
- `group` - Article group
- `articleGroup` - Article group details
- `articleSuppliers` - Supplier information
- `image` - Image data
- `stockLocations` - Stock location details
- `salesGLAccount` - Sales GL account
- `purchaseGLAccount` - Purchase GL account
- `activity` - Activity details
- `vatTariff` - VAT tariff details
- `projectSupplyRoute` - Project supply route

### Synchronizing Article Data

**Use Case**: Keep local database in sync with Robaws articles

#### Incremental Sync Pattern

```http
GET /api/v2/articles?updatedFrom=2020-12-17T13:35:00.000Z&include=availableStock&page=0&size=100
```

**Parameters:**
- `updatedFrom` - ISO 8601 timestamp (fetches all entities with `updatedAt` >= this value)
- `include` - Include related resources (e.g., `availableStock`)
- `page` - Page number (0-based)
- `size` - Page size (max 100, default 10)

**Flow:**
1. Store the last sync timestamp
2. Fetch articles updated since last sync
3. Use paging to get all results
4. Update local cache
5. Store new sync timestamp

#### Filtering by Custom Fields

Filter articles by extra/custom fields:

```http
GET /api/v2/articles?extraFields[parent_item]=true
```

**Note**: The `[` and `]` characters must be URL encoded!

---

## Filtering, Paging & Sorting

### Filtering on Standard Fields

Each root entity collection resource has specific filter parameters.

**Example:**
```http
GET /api/v2/articles?articleGroupId=123
```

### Filtering on Extra/Custom Fields

**URL Syntax**: `extraFields[FieldName]=value`

**⚠️ Important**: URL encode `[` and `]` characters!

#### Field Type Examples

**Text, Link, Multiple Choice:**
```http
GET /api/v2/articles?extraFields[YourFieldName]=TextValue
```

**Boolean:**
```http
GET /api/v2/articles?extraFields[YourFieldName]=true
GET /api/v2/articles?extraFields[YourFieldName]=false
```

**Integer and Decimal:**
```http
GET /api/v2/articles?extraFields[YourFieldName]=90
GET /api/v2/articles?extraFields[YourFieldName]=3.67
```

**Date:**
```http
GET /api/v2/articles?extraFields[YourFieldName]=2021-07-08
```

### Including Related Resources

Use the `include` parameter to fetch nested associations in one request:

```http
GET /api/v2/employees?include=employer,employer.contacts,certificates
```

**Benefits:**
- Reduces number of API calls
- Improves performance
- Gets related data in single response

### Paging

**Parameters:**
- `page` - Page number (0-based)
- `size` - Page size (max 100, default 10)

**Examples:**
```http
GET /api/v2/employees?page=0&size=10  # First page
GET /api/v2/employees?page=1&size=10  # Second page
```

**Default Sorting**: If no sorting is provided, a default sorting is added for predictable results.

### Sorting

**Parameter**: `sort`

**Format**: `field:direction,field2:direction`

**Example:**
```http
GET /api/v2/employees?sort=surname:asc,name:desc
```

---

## Updating Data (PUT vs PATCH)

### ⚠️ Rule of Thumb

**When in doubt, always prefer PATCH to PUT!**

### PUT - Complete Replacement

Replaces the entire entity with the provided payload.

**Not recommended** for big entities with many properties.

```http
PUT /api/v2/employees/8
Content-Type: application/json
Authorization: Basic xxxxxxxxxx

{
  "name": "John",
  "surname": "Van Robaeys",
  "email": "john@example.com",
  ... (all other fields required)
}
```

### PATCH - Partial Update

Two variants available:

#### 1. JSON Merge Patch (Recommended for Simple Updates)

Only define the properties you want to update.

```http
PATCH /api/v2/employees/8
Content-Type: application/merge-patch+json
Authorization: Basic xxxxxxxxxx

{
  "surname": "Van Robaeys"
}
```

**Use Case**: Simple field updates

#### 2. JSON Patch (More Powerful)

Use JSON Patch operations for complex updates.

```http
PATCH /api/v2/employees/8
Content-Type: application/json-patch+json
Authorization: Basic xxxxxxxxxx

[
  {
    "op": "replace",
    "path": "/surname",
    "value": "Van Robaeys"
  },
  {
    "op": "add",
    "path": "/otherCertificates",
    "value": "Rijbewijs"
  }
]
```

**Operations Available:**
- `add` - Add a value
- `remove` - Remove a value
- `replace` - Replace a value
- `move` - Move a value
- `copy` - Copy a value
- `test` - Test a value

**Use Case**: Complex updates, array manipulations, conditional updates

---

## Common Endpoints

### Articles
- `GET /api/v2/articles` - List articles
- `GET /api/v2/articles/{id}` - Get single article
- `POST /api/v2/articles` - Create article
- `PATCH /api/v2/articles/{id}` - Update article (preferred)
- `PUT /api/v2/articles/{id}` - Replace article (not recommended)

### Clients
- `GET /api/v2/clients` - List clients
- `GET /api/v2/clients/{id}` - Get single client
- `POST /api/v2/clients` - Create client
- `PATCH /api/v2/clients/{id}` - Update client

### Employees
- `GET /api/v2/employees` - List employees
- `GET /api/v2/employees/{id}` - Get single employee
- `POST /api/v2/employees` - Create employee
- `PATCH /api/v2/employees/{id}` - Update employee

### Offers
- `GET /api/v2/offers` - List offers
- `GET /api/v2/offers/{id}` - Get single offer
- `POST /api/v2/offers` - Create offer
- `PATCH /api/v2/offers/{id}` - Update offer

### Sales Invoices
- `GET /api/v2/sales-invoices` - List sales invoices
- `GET /api/v2/sales-invoices/{id}` - Get single sales invoice
- `POST /api/v2/sales-invoices` - Create sales invoice
- `PATCH /api/v2/sales-invoices/{id}` - Update sales invoice

### Purchase Invoices
- `GET /api/v2/purchase-invoices` - List purchase invoices
- `GET /api/v2/purchase-invoices/{id}` - Get single purchase invoice
- `POST /api/v2/purchase-invoices` - Create purchase invoice
- `PATCH /api/v2/purchase-invoices/{id}` - Update purchase invoice
- `POST /api/v2/purchase-invoices/{id}/approve` - Forcibly approve (Added: 2024-02-08)
- `POST /api/v2/purchase-invoices/{id}/start-approval-request` - Start approval request (Added: 2024-04-03)

### Webhook Endpoints
- `POST /api/v2/webhook-endpoints` - Register webhook endpoint
- `GET /api/v2/webhook-endpoints` - List webhook endpoints
- `DELETE /api/v2/webhook-endpoints/{id}` - Delete webhook endpoint

---

## Event Types

### Our Current Implementation

**Webhook Endpoint**: `POST /api/webhooks/robaws/articles`

**Events We Handle:**
- `article.created`
- `article.updated`

**Event Structure:**
```json
{
  "id": "webhook-event-id",
  "event": "article.updated",
  "data": {
    "id": "1834",
    "name": "Article Name",
    "saleName": "Sales Name",
    "extraFields": {
      "C965754A-4523-4916-A127-3522DE1A7001": {
        "booleanValue": true
      }
    },
    ... (all article fields)
  }
}
```

**Note**: Custom fields appear in `extraFields` with their UUID as the key.

---

## API Changelog Highlights

### 2024-04-03
- Added possibility to start approval request for purchase invoice
  - `POST /api/v2/purchase-invoices/{id}/start-approval-request`

### 2024-02-08
- Added `documentId` field to purchase invoice API model
- Added forcibly approve endpoint for purchase invoices
  - `POST /api/v2/purchase-invoices/{id}/approve`
- Payment condition API extended with full functionality (matching UI)

---

## Best Practices

### 1. Webhooks Over Polling
- Always use webhooks instead of polling for real-time updates
- Polling is discouraged by Robaws

### 2. Signature Verification
- Always verify `Robaws-Signature` header
- Use constant-time comparison to prevent timing attacks
- Validate timestamp to prevent replay attacks

### 3. Rate Limiting
- Implement exponential backoff on 429 responses
- Monitor rate limiting headers
- Cache responses when appropriate

### 4. Incremental Sync
- Use `updatedFrom` parameter for efficient syncing
- Store last sync timestamp
- Implement paging for large datasets

### 5. Error Handling
- Return 2XX status codes for successful webhook processing
- Log all webhook events for debugging
- Implement retry logic for failed operations

### 6. Testing
- Use test tenants (free for 14 days)
- Test with https://webhook.site
- Build against test tenant before production

### 7. Updates
- Prefer PATCH over PUT
- Use JSON Merge Patch for simple updates
- Use JSON Patch for complex operations

### 8. Including Related Data
- Use `include` parameter to reduce API calls
- Only include necessary relationships
- Be mindful of response size

---

## Contact & Support

**Email**: support@robaws.be

**Documentation**: https://support.robaws.com

**API Reference**: https://app.robaws.com/public/api-docs/robaws

---

## Quick Reference: Our Implementation

### Environment Variables
```env
ROBAWS_API_URL=https://app.robaws.com/api/v2
ROBAWS_API_TOKEN=your-api-token
ROBAWS_WEBHOOK_SECRET=your-webhook-secret
ROBAWS_WEBHOOKS_ENABLED=true
```

### Our Webhook Handler
- **File**: `app/Http/Controllers/Api/RobawsWebhookController.php`
- **Route**: `POST /api/webhooks/robaws/articles`
- **Middleware**: `throttle:60,1` (60 requests per minute)

### Our Sync Service
- **File**: `app/Services/Quotation/RobawsArticlesSyncService.php`
- **Methods**:
  - `sync()` - Full sync
  - `processArticleFromWebhook()` - Process webhook event
  - `processArticle()` - Process single article
  - `extractParentItemFromArticle()` - Extract custom Parent Item field
  - `extractCompositeItems()` - Extract composite items as JSON

### Our Artisan Commands
- `php artisan robaws:register-webhook` - Register webhook with Robaws
- `php artisan robaws:sync-articles` - Sync all articles
- `php artisan robaws:sync-articles --incremental` - Incremental sync
- `php artisan robaws:sync-articles --full` - Full sync
- `php artisan robaws:test-webhook` - Test webhook endpoint
- `php artisan robaws:check-webhook-health` - Check webhook health

### Our Database Tables
- `robaws_articles_cache` - Cached article data
- `robaws_webhook_logs` - Webhook event logs

### Scheduled Tasks
- Incremental sync: Every 2 hours
- Webhook health check: Hourly
- Full sync: Weekly (Sunday 2 AM)

---

*Last Updated: October 21, 2025*

