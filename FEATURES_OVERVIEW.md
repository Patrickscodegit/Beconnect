# 🚢 Bconnect - Complete Features Overview

**Version:** 2.0 (Production)  
**Last Updated:** October 15, 2025  
**Tech Stack:** Laravel 11, Filament 3, Livewire 3, PostgreSQL

---

## 📋 Table of Contents

1. [System Overview](#system-overview)
2. [User Roles & Access Levels](#user-roles--access-levels)
3. [Core Features](#core-features)
4. [Quotation System](#quotation-system)
5. [Schedule Management](#schedule-management)
6. [Admin Panel (Filament)](#admin-panel-filament)
7. [Integrations](#integrations)
8. [Technical Architecture](#technical-architecture)
9. [Configuration](#configuration)

---

## 🎯 System Overview

**Bconnect** is a comprehensive **freight forwarding quotation and schedule management platform** built for **Belgaco** to streamline:

- **Public quotation requests** (prospects)
- **Customer portal** (authenticated clients)
- **Admin quotation processing** (Filament panel)
- **Schedule visibility** (public & customer)
- **Robaws API integration** (pricing & articles sync)

### Key Objectives

✅ **Automate quotation requests** from prospects and customers  
✅ **Real-time schedule visibility** for European → Africa routes  
✅ **Multi-commodity support** (vehicles, machinery, general cargo, boats)  
✅ **Seamless Robaws integration** for pricing and article management  
✅ **Admin efficiency** with Filament-powered quotation processing  

---

## 👥 User Roles & Access Levels

### 1. **Prospects (Public Users)**
- ❌ No login required
- ✅ Access: Public quotation form, public schedules
- ✅ Can submit quotation requests
- ✅ Can track quotation status via email link
- ❌ No access to customer portal or admin panel

### 2. **Customers (Authenticated)**
- ✅ Login required (`/customer` portal)
- ✅ Access: Customer dashboard, quotations, schedules
- ✅ Can create detailed quotations
- ✅ View quotation history and status
- ✅ Filter schedules by POL/POD
- ❌ No admin access

### 3. **Admin Users (Team)**
- ✅ Login required (`/admin` Filament panel)
- ✅ Access: All quotation requests, article management, templates
- ✅ Process and quote requests
- ✅ Sync Robaws articles
- ✅ Manage offer templates
- ✅ Can access customer portal from admin menu (two-way navigation)

---

## 🚀 Core Features

### 1. **Quotation System** (Public & Customer)

#### **Quick Quote Mode**
- **Single commodity type** selection (vehicles, machinery, general cargo, boat)
- **Simple cargo description** (text field)
- **Fast submission** for basic requests
- **Ideal for:** Prospects, simple shipments

#### **Detailed Quote Mode** (Recommended)
- **Multi-commodity item repeater** (add multiple items)
- **Dynamic fields per commodity type:**
  - **Vehicles:** Make, Type, Fuel, Condition, Dimensions, Weight, Wheelbase (airfreight), Extra Info
  - **Machinery:** Make, Type, Fuel (incl. Hybrid), Dimensions, Weight, Parts (checkbox + description), Extra Info
  - **General Cargo:** Cargo Type (Packed/Palletized/Unpacked), Forkliftable, Hazardous, ISPM15 Wood, Bruto/Netto Weight, Dimensions, Extra Info
  - **Boat:** Dimensions, Weight, Trailer (checkbox), Wooden/Iron Cradle, Extra Info
- **Real-time CBM calculation** (metric/US format toggle)
- **File uploads per item** (JSON-stored in main table)
- **Unit conversion** (metric ↔ US format)

#### **Service Types** (17 Options)
1. **RORO Export**
2. **RORO Import**
3. **FCL Export**
4. **FCL Import**
5. **FCL Export Vehicle Consol** (2-pack/3-pack)
6. **FCL Import Vehicle Consol** (2-pack/3-pack)
7. **LCL Export**
8. **LCL Import**
9. **BB Export** (Break Bulk)
10. **BB Import**
11. **Airfreight Export**
12. **Airfreight Import**
13. **Crosstrade**
14. **Road Transport**
15. **Customs**
16. **Port Forwarding**
17. **Other**

**Trade Direction** is auto-calculated from service type (no manual input).

---

### 2. **Schedule Management**

#### **Public Schedules** (`/schedules`)
- ✅ View **active schedules** (European origins → PODs with active sailings)
- ✅ Filter by **POL** (European origins only)
- ✅ Filter by **POD** (only ports with active schedules - reduced from 69 to ~12)
- ✅ **No authentication required**
- ✅ Link to quotation form for selected schedule

#### **Customer Schedules** (`/customer/schedules`)
- ✅ Same filtering logic as public
- ✅ **Enhanced UI** with customer branding
- ✅ Access to detailed schedule information
- ✅ Correct filter form submission (fixed route)

#### **Schedule Data Fields**
- Vessel name, voyage number
- Carrier
- POL, POD (with UN/LOCODE support)
- ETS (Estimated Time of Sailing) from POL
- ETA (Estimated Time of Arrival) at POD
- Transhipment ports
- Next sailing date
- Transit time (days)
- Sailing frequency

#### **Dynamic POD Filtering**
Only shows **PODs with active schedules** to reduce clutter and improve UX.

---

## 🛠️ Admin Panel (Filament)

### **Quotation Request Management**

#### **Quotation List View**
- ✅ View all quotation requests (prospects, customers, intake-generated)
- ✅ Filter by: Status, Source, Service Type, Date Range
- ✅ Search by: Request Number, Email, Company
- ✅ Bulk actions available

#### **Quotation Detail/Edit View**
- ✅ **Customer Information** (requester details)
- ✅ **Service & Route** (service type, POL, POD, schedule selection)
- ✅ **Cargo Information** (commodity items repeater)
- ✅ **Article Selection** (Robaws articles with smart filtering)
- ✅ **Pricing Calculator** (auto-calculate with profit margins)
- ✅ **Offer Templates** (intro/end text with variables)
- ✅ **Status Management** (pending → processing → quoted → accepted/rejected)
- ✅ **Email Notifications** (auto-send on status changes)

#### **Commodity Items Repeater (Admin)**
- Same fields as customer-facing form
- **Robaws field integration** for cargo/dimension fields
- Dynamic validation based on service type
- CBM auto-calculation

#### **Article Management (`/admin/robaws-articles`)**
- ✅ View synced Robaws articles cache
- ✅ Filter by: Category, Service Type, Customer Type, Active status
- ✅ Search by: Article Code, Name
- ✅ View parent-child article relationships
- ✅ Manual activation/deactivation
- ✅ Last sync timestamp

#### **Article Sync Widget**
- ✅ **Last Sync** timestamp (human-readable)
- ✅ **Synced Today** indicator
- ✅ **Total Active Articles** count
- ✅ **Manual Sync** button (triggers `php artisan robaws:sync-articles`)

#### **Sync Logs (`/admin/robaws-sync-logs`)**
- ✅ View all sync attempts
- ✅ Track: Sync type, items synced, duration, errors
- ✅ Filterable by date and status

#### **Offer Templates (`/admin/offer-templates`)**
- ✅ Manage intro/end templates
- ✅ Template variables support (`${POL}`, `${CARGO}`, etc.)
- ✅ Service type & customer type filtering
- ✅ Active/inactive toggle

### **Dashboard Widgets**
- **Quotation Overview** (pending, processing, quoted counts)
- **Quotation Stats** (recent activity, conversion rates)
- **Article Sync Status** (last sync, active articles)

---

## 🔗 Integrations

### 1. **Robaws API Integration**

#### **Articles Sync**
- **Endpoint:** Robaws Articles API
- **Frequency:** Hourly (configurable via cron: `0 * * * *`)
- **Method:** `php artisan robaws:sync-articles`
- **Cached in:** `robaws_articles_cache` table
- **Sync Logs:** `robaws_sync_logs` table

#### **Article Features**
- ✅ Parent-child relationships (auto-include surcharges)
- ✅ Service type filtering (RORO, FCL, LCL, etc.)
- ✅ Customer type pricing (FORWARDERS, GENERAL, CIB, PRIVATE)
- ✅ Quantity tier pricing (1-pack, 2-pack, 3-pack, 4-pack)
- ✅ Formula-based pricing (for CONSOL services)
- ✅ Profit margin calculation by customer role

#### **Webhook Support** (Future)
- **Endpoint:** `/api/webhooks/robaws`
- **Controller:** `RobawsWebhookController`
- **Events:** Offer updates, article changes
- **Status:** Pending Robaws approval

### 2. **Email Notifications**

#### **Notification Types**
1. **Quotation Submitted** (to team)
   - Triggered on new prospect quotation
   - Recipient: `MAIL_TEAM_ADDRESS`
   
2. **Quotation Quoted** (to customer)
   - Triggered when admin marks as "quoted"
   - Includes pricing details and PDF (future)
   
3. **Status Changed** (to customer)
   - Triggered on status updates
   - Includes tracking link

#### **Email Settings**
- **Test Mode:** `MAIL_TEST_MODE=false` (production)
- **Team Email:** `MAIL_TEAM_ADDRESS=info@belgaco.be`
- **Templates:** Blade-based with Tailwind styling

---

## 🏗️ Technical Architecture

### **Tech Stack**
- **Backend:** Laravel 11
- **Admin Panel:** Filament 3
- **Frontend:** Livewire 3, Alpine.js, Tailwind CSS
- **Database:** PostgreSQL
- **Queue:** Laravel Horizon (Redis)
- **Deployment:** Laravel Forge

### **Database Schema**

#### **Core Tables**
1. **`quotation_requests`** - Main quotation data
2. **`quotation_commodity_items`** - Multi-commodity items
3. **`quotation_request_articles`** - Selected Robaws articles (pivot)
4. **`quotation_request_files`** - Uploaded files
5. **`offer_templates`** - Intro/end templates
6. **`robaws_articles_cache`** - Synced Robaws articles
7. **`article_children`** - Parent-child article relationships
8. **`schedule_offer_links`** - Schedule-to-offer links
9. **`robaws_sync_logs`** - Sync history
10. **`robaws_webhook_logs`** - Webhook events
11. **`shipping_schedules`** - Schedule data
12. **`ports`** - Port master data (with UN/LOCODE)

### **Key Services**
- **`RobawsArticleProvider`** - Article fetching and caching
- **`RobawsArticlesSyncService`** - Sync orchestration
- **`ArticleSelectionService`** - Smart article filtering
- **`OfferTemplateService`** - Template rendering with variables
- **`RobawsFieldGenerator`** - Dynamic Robaws field mapping
- **`ScheduleExtractionPipeline`** - Schedule data processing

### **Observers**
- **`QuotationRequestObserver`** - Auto-generate request numbers, send notifications
- **`IntakeObserver`** - Auto-create quotations from intakes

### **Livewire Components**
- **`CommodityItemsRepeater`** - Multi-commodity form repeater
- Dynamic commodity forms: `vehicles.blade.php`, `machinery.blade.php`, `general_cargo.blade.php`, `boat.blade.php`

---

## ⚙️ Configuration

### **Environment Variables**

#### **Quotation System**
```env
QUOTATION_SYSTEM_ENABLED=true
QUOTATION_AUTO_CREATE_FROM_INTAKE=true
QUOTATION_DEFAULT_MARGIN=15
QUOTATION_VAT_RATE=21.00
QUOTATION_DEFAULT_CURRENCY=EUR
```

#### **Email Notifications**
```env
MAIL_TEST_MODE=false
MAIL_TEAM_ADDRESS=info@belgaco.be
```

#### **Robaws API**
```env
ROBAWS_API_URL=https://api.robaws.com
ROBAWS_API_KEY=your-api-key
ROBAWS_SYNC_METHOD=polling
ROBAWS_WEBHOOKS_ENABLED=false
```

### **Service Type Configuration**
Defined in `config/quotation.php`:
- 17 service types with direction, unit, tier pricing
- Schedule requirement flags
- Formula pricing flags (for CONSOL)

### **Commodity Types**
Defined in `config/quotation.php`:
- **Vehicles:** 10 categories (motorcycle, car, SUV, van, truck, etc.)
- **Machinery:** 7 types (excavator, forklift, crane, etc.)
- **General Cargo:** 3 types (packed, palletized, unpacked)
- **Boat:** With trailer/cradle options

### **Customer Roles** (15 types)
- RORO, POV, CONSIGNEE, FORWARDER, HOLLANDICO, etc.
- Each with specific profit margin percentage

### **Customer Types** (6 types)
- FORWARDERS, GENERAL, CIB, PRIVATE, HOLLANDICO, OLDTIMER
- Used for article filtering and pricing

---

## 🔐 Security Features

### **Authentication**
- ✅ Laravel Breeze for customer authentication
- ✅ Filament authentication for admin panel
- ✅ Session-based authentication
- ✅ Password reset functionality

### **Authorization**
- ✅ Role-based access control (admin vs customer)
- ✅ Middleware protection on routes
- ✅ Filament policies for resource access

### **Data Protection**
- ✅ CSRF protection on all forms
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ XSS protection (Blade escaping)
- ✅ Rate limiting on API endpoints
- ✅ Environment variable protection (`.env` not in git)

### **Git Security**
- ✅ Pre-commit hooks for dangerous DB commands
- ✅ GitHub secret scanning enabled
- ✅ `.env` files excluded from version control

---

## 📊 Workflow Examples

### **Prospect Quotation Flow**
1. Prospect visits `/quotations/create`
2. Selects service type (auto-calculates direction)
3. Chooses Quick Quote or Detailed Quote
4. Fills commodity details (with CBM calculation)
5. Optionally selects schedule
6. Submits quotation
7. Receives confirmation with tracking link
8. Admin receives email notification
9. Admin processes in Filament (`/admin/quotation-requests`)
10. Admin marks as "quoted" → customer receives email

### **Customer Quotation Flow**
1. Customer logs in to `/customer`
2. Navigates to "Request Quote"
3. Uses Detailed Quote mode (recommended)
4. Adds multiple commodity items
5. Uploads files per item
6. Submits quotation
7. Views status in "My Quotations"
8. Receives email when quoted

### **Schedule Discovery Flow**
1. User visits `/schedules` (public) or `/customer/schedules`
2. Filters by POL (European origins only)
3. Filters by POD (active schedules only - ~12 ports)
4. Views schedule details
5. Clicks "Request Quote for this Schedule"
6. Pre-filled quotation form with schedule selected

### **Admin Article Sync Flow**
1. Admin opens Filament dashboard
2. Views "Article Sync Status" widget
3. Clicks "Sync Now" or waits for cron
4. System calls `php artisan robaws:sync-articles`
5. Fetches latest articles from Robaws API
6. Updates `robaws_articles_cache` table
7. Logs sync in `robaws_sync_logs`
8. Widget updates with new timestamp

---

## 📈 Future Enhancements

### **Planned Features**
- [ ] PDF quotation generation (attach to emails)
- [ ] Customer self-service quotation acceptance
- [ ] Real-time Robaws webhooks (pending approval)
- [ ] Multi-language support (EN, FR, NL)
- [ ] Mobile app (React Native)
- [ ] Advanced reporting & analytics
- [ ] Quotation versioning & revisions
- [ ] Integration with accounting software

### **Under Consideration**
- [ ] Live chat support
- [ ] Automated price negotiation workflow
- [ ] Customer portal enhancements (document vault)
- [ ] API for external integrations
- [ ] Advanced schedule prediction (AI-based)

---

## 📞 Support & Maintenance

### **Manual Sync Commands**
```bash
# Sync Robaws articles
php artisan robaws:sync-articles

# Clear all caches
php artisan optimize:clear

# Cache routes (production)
php artisan route:cache

# Cache config (production)
php artisan config:cache
```

### **Deployment Checklist**
See: `PRODUCTION_DEPLOYMENT.md` for full deployment guide

### **Monitoring**
- **Laravel Horizon** - Queue monitoring
- **Laravel Telescope** (dev) - Debug & profiling
- **Log monitoring** - `storage/logs/laravel.log`

### **Backup Strategy**
- **Database:** Daily automated backups (Forge)
- **Files:** Weekly backups
- **Code:** Git version control

---

## 🎉 Success Metrics

### **Performance**
- ✅ POD filtering reduced from **69 ports → ~12 active ports**
- ✅ Schedule sync successful (normalized date handling)
- ✅ Form submission working (Livewire + parent form integration)
- ✅ Production deployment successful

### **Features Delivered**
- ✅ Multi-commodity quotation system
- ✅ Dynamic POD filtering
- ✅ Unit conversion (metric ↔ US)
- ✅ Real-time CBM calculation
- ✅ File uploads per commodity item
- ✅ Robaws article sync & caching
- ✅ Email notification system
- ✅ Admin quotation processing
- ✅ Two-way admin navigation
- ✅ Customer portal with schedules
- ✅ Public quotation & schedule access

---

## 📝 Documentation Links

- **Quotation System README:** `docs/QUOTATION_SYSTEM_README.md`
- **Environment Variables:** `docs/QUOTATION_SYSTEM_ENV_VARIABLES.md`
- **Deployment Guide:** `PRODUCTION_DEPLOYMENT.md`
- **Database Protection:** `database-protection.md`
- **Testing Guide:** `TESTING_GUIDE.md`

---

**🚀 Built with ❤️ for Belgaco by the Bconnect Team**  
*Last Updated: October 15, 2025*

