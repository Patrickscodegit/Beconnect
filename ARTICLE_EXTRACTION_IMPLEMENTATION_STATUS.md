# Article Extraction System - Implementation Status

## Progress Summary

### ✅ **Phase 1 - Database Schema (COMPLETED)**

Successfully created all required database tables:

1. **robaws_articles_cache** - Enhanced with:
   - article_code (BWFCLIMP, BWA-FCL, etc.)
   - customer_type (FORWARDERS, GENERAL, CIB, PRIVATE)
   - min/max_quantity for tier pricing (1-4 pack)
   - tier_label ("2 pack", "3 pack", etc.)
   - unit_type (LM, unit, shipment, car, container)
   - pricing_formula (JSON for CONSOL formulas)
   - profit_margins (JSON per customer role)
   - is_parent_article, is_surcharge flags
   - requires_manual_review flag

2. **article_children** - NEW table for parent-child relationships:
   - parent_article_id → child_article_id
   - sort_order, is_required, is_conditional
   - conditions (JSON for conditional inclusion)

3. **quotation_request_articles** - NEW pivot table:
   - Links articles to quotation requests
   - parent_article_id for child tracking
   - item_type (parent, child, standalone)
   - formula_inputs for CONSOL calculation
   - calculated_price for formula results
   - unit_price, selling_price, subtotal

4. **offer_templates** - NEW table for intro/end texts:
   - template_code, template_name
   - template_type (intro, end, slot)
   - service_type, customer_type
   - content with ${variables}
   - available_variables (JSON)

5. **quotation_requests** - Enhanced with:
   - customer_role, customer_type
   - subtotal, discount_amount, discount_percentage
   - total_excl_vat, vat_amount, vat_rate, total_incl_vat
   - pricing_currency
   - intro_template_id, end_template_id
   - intro_text, end_text (rendered)
   - template_variables (JSON)

### 🚧 **Phase 2 - Models & Relationships (IN PROGRESS)**

Next steps:
1. Update RobawsArticleCache model with new fields and relationships
2. Create QuotationRequestArticle model
3. Create OfferTemplate model
4. Update QuotationRequest model with pricing methods

### 📋 **Phase 3 - Article Extraction Service (PENDING)**

Will implement:
1. RobawsArticleProvider enhancements:
   - detectParentChildRelationships()
   - parseArticleCode()
   - mapToServiceType()
   - parseQuantityTier()
   - detectPricingFormula()
   - parseCarrierFromDescription()
   - inferCategoryFromDescription()

2. Extract from /api/v2/offers endpoint (accessible)
3. Process 500 offers in batches of 50
4. Build article bundles (GANRLAC + surcharges)
5. Store ~5,000 unique articles

### 📋 **Phase 4 - Template Service (PENDING)**

Will implement:
1. OfferTemplateService for rendering
2. Variable substitution (POL, POD, CARGO, etc.)
3. Template seeder with default intro/end texts

### 📋 **Phase 5 - Configuration (PENDING)**

Will add to config/quotation.php:
1. profit_margins by role
2. customer_types mapping
3. service_types mapping
4. vat_rate configuration
5. article_extraction settings

## Business Requirements Covered

✅ Parent-child article bundles (e.g., GANRLAC + surcharges)
✅ Service type mapping (RORO, FCL, LCL, BB)
✅ Quantity tier pricing (1-4 pack)
✅ Formula-based pricing (CONSOL)
✅ Customer type filtering
✅ Role-based profit margins
✅ Offer templates with variables
✅ Total calculations with VAT
✅ Carrier parsing from descriptions

## Testing Plan

Once implementation is complete:
1. Test parent-child detection with GANRLAC example
2. Test quantity tier filtering (1-4 pack containers)
3. Test formula pricing for CONSOL services
4. Test customer type filtering
5. Test auto-inclusion of surcharges
6. Test template rendering with variables
7. Verify profit margin calculations per role
8. Validate total calculations with VAT

## Next Action

Continue with Phase 2: Update models with new relationships and business logic.

