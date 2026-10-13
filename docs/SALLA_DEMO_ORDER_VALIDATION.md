# Salla Demo Store Verification and Order API Validation Report

**Date:** 2026-10-13  
**Target Environment:** Salla Demo Store (Merchant ID: `918791227`)  
**Application:** Naz Autoreply (`autoreply-backend`)  
**Execution Boundary:** Read-Only Investigation & Safety Assessment (Zero Mutations Executed)

---

## 1. Store Identity Verification (Re-Verified)

The connected store for Channel ID 6 was re-verified via a read-only request to `GET https://api.salla.dev/admin/v2/store/info`.

| Field | Target Value | Live Verified Value | Status |
|---|---|---|---|
| `data.id` | `918791227` | `918791227` | **MATCH** |
| `data.type` | `demo` | `demo` | **MATCH** |
| `data.name` | `متجر تجريبي` | `متجر تجريبي` | **MATCH** |
| `data.domain` | `https://demostore.salla.sa/dev-8t7hk2hkamshhrc6` | `https://demostore.salla.sa/dev-8t7hk2hkamshhrc6` | **MATCH** |
| `data.kyc_country` | `SA` | `SA` | **MATCH** |
| `data.currency` | `SAR` | `SAR` | **MATCH** |
| `data.plan` | `pro` | `pro` | **MATCH** |
| `data.status` | `active` | `active` | **MATCH** |
| `data.verified` | `false` | `false` | **MATCH** |

**Conclusion:** The store remains strictly verified as the isolated Salla sandbox demo store.

---

## 2. Product 1242785692 Catalog Verification

Re-verified via `GET /products/1242785692`:

- **Name:** `فستان`
- **Product Type:** `product` (Standard physical item)
- **Status:** `sale`
- **Availability:** `is_available: true`
- **Stock:** `unlimited_quantity: true` (Stock will not be depleted or locked by order tests)
- **Pricing:**
  - `regular_price`: `349 SAR`
  - `sale_price`: `174 SAR`
  - `tax.amount`: `0 SAR`
  - `taxed_price`: `174 SAR`
- **Shipping Requirement:** `require_shipping: true`

---

## 3. Riyadh City and District Verification

- **City ID:** `1473353380` (`الرياض` / `Riyadh`)
- **District Verification (`GET /cities/1473353380/districts`):**
  - Confirmed 15 districts returned by Salla.
  - Selected District ID `1473353380` corresponds exactly to **`العمل`** (`Al Amal`).
  - Other verified districts in Riyadh:
    - `566146469` → `النموذجية` (`Al Namudhajiyah`)
    - `1939592358` → `الجرادية` (`Al Jarradiyah`)
    - `1298199463` → `الصناعية` (`Al Sinaiyah`)
    - `773200552` → `الرحمانية` (`Ar Rahmaniyah`)

---

## 4. Shipping Rate and Total Amount Resolution

Re-verified via `GET /shipping/companies/estimate-rate` for Riyadh (`country: 1473353380, city: 1473353380, postal: 12211, geocode: 24.7136,46.6753`):

- **Courier Selected:** `Dev Company` (Company ID: `326899803`)
- **Carrier Shipping Fee:** `50.00 SAR` (fixed)
- **Cash on Delivery Service Fee:** `0.00 SAR`
- **Product Unit Price:** `174.00 SAR` (sale price, 0 tax)
- **Total Calculation Breakdown:**
  $$\text{Cash on Delivery Amount} = \text{Product Price (174.00)} + \text{Shipping Fee (50.00)} + \text{COD Fee (0.00)} = 224.00\text{ SAR}$$
- The 224 SAR proposed amount is strictly derived from the verified item price and carrier estimate.

---

## 5. Notification Suppression & Side Effects Analysis

1. **`receiver.notify: false`:**
   - Documented parameter in Salla's Create Order API schema.
   - Suppresses Salla's automated SMS/email dispatch to the receiver.
2. **Side Effect 1 — Merchant Dashboard:**
   - An order entry will be created under the demo store dashboard (`demostore.salla.sa/dev-8t7hk2hkamshhrc6`). Expected and isolated.
3. **Side Effect 2 — Shipping Carrier Dispatch:**
   - Courier `Dev Company` (`326899803`) has `activation_type: "manual"`, `app_id: null`.
   - **Zero external carrier API calls** or airway bills will be triggered.
4. **Side Effect 3 — Payment Gateways:**
   - Payment method is `cod` with status `pending_payment`.
   - **Zero payment gateway transactions** will be processed.
5. **Side Effect 4 — Application Webhooks:**
   - The token lacks `webhooks.read_write` (endpoint returns 401).
   - If Naz backend receives an `order.created` webhook, `SallaWebhookJob` only logs the order and records it in channel metadata; no messages are sent to the customer.

---

## 6. Corrected Baseline Payload (Payload A)

Using strictly synthetic customer data and verified demo-store metadata:

```json
{
  "customer": {
    "name": "Sandbox Test Customer",
    "mobile": "+966500000000",
    "email": "sandbox-order-test@salla.dev"
  },
  "receiver": {
    "name": "Sandbox Test Customer",
    "country_code": "SA",
    "phone": "966500000000",
    "email": "sandbox-order-test@salla.dev",
    "notify": false
  },
  "delivery_method": "shipping",
  "courier_id": 326899803,
  "ship_to": {
    "country": 1473353380,
    "city": 1473353380,
    "district": 1473353380,
    "block": "1",
    "street_number": "8230",
    "building_number": "1234",
    "short_address": "RRRR1234",
    "additional_number": "5678",
    "address": "حي العمل، طريق الملك فهد، الرياض",
    "address_line": "طريق الملك فهد، حي العمل",
    "postal_code": "12211",
    "geo_coordinates": {
      "lat": 24.7136,
      "lng": 46.6753
    }
  },
  "products": [
    {
      "identifier_type": "id",
      "identifier": 1242785692,
      "quantity": 1
    }
  ],
  "payment": {
    "method": "cod",
    "status": "pending_payment",
    "cash_on_delivery": {
      "amount": 224.0,
      "currency": "SAR"
    }
  }
}
```

### Field-by-Field Evidence Table

| Field Path | Proposed Value | Source / Verification Evidence |
|---|---|---|
| `customer.name` | `"Sandbox Test Customer"` | Synthetic sandbox identifier. |
| `customer.mobile` | `"+966500000000"` | Salla documentation standard test phone. |
| `customer.email` | `"sandbox-order-test@salla.dev"` | Synthetic test email on Salla test domain. |
| `receiver.country_code` | `"SA"` | Verified country code matching receiver phone. |
| `receiver.phone` | `"966500000000"` | Salla schema requirement (national without `+`). |
| `receiver.notify` | `false` | Official schema toggle to suppress customer notifications. |
| `delivery_method` | `"shipping"` | Official Salla delivery method. |
| `courier_id` | `326899803` | Returned by `GET /shipping/companies/estimate-rate`. |
| `ship_to.country` | `1473353380` | Salla ID for Saudi Arabia (`GET /countries`). |
| `ship_to.city` | `1473353380` | Salla ID for Riyadh (`GET /countries/1473353380/cities`). |
| `ship_to.district` | `1473353380` | Salla ID for Al Amal (`GET /cities/1473353380/districts`). |
| `ship_to.postal_code`| `"12211"` | Valid 5-digit Riyadh postal code. |
| `ship_to.geo_coordinates` | `{"lat": 24.7136, "lng": 46.6753}` | Valid centroid coordinates for Riyadh. |
| `products[0].identifier` | `1242785692` | Active physical product verified in demo catalog. |
| `payment.method` | `"cod"` | Verified supported payment method. |
| `payment.status` | `"pending_payment"` | Official COD initial status. |
| `payment.cash_on_delivery.amount`| `224.0` | Sum of 174.00 (product) + 50.00 (shipping) + 0.00 (COD). |

---

## 7. Egyptian Address Investigation & Location Realities

### 1. City Catalog Realities
- In Salla's catalog, Egypt (`1723506348`) contains **5,938 cities and localities** across 396 pages.
- City ID `264729926` used earlier is `عبديات دراواه` (`Ab'adiyat Darawah`), a rural village in Beheira Governorate. Using Cairo coordinates (`30.0444, 31.2357`) for this ID was a location mismatch (>110 km).
- Salla's true catalog entries for major Egyptian cities:
  - **Cairo:** ID `782158572` (`القاهرة` / `Cairo`). Verified true centroid: `lat: 30.0444, lng: 31.2357`.
  - **Giza:** ID `552977415` (`الجيزة` / `Giza`). Verified true centroid: `lat: 30.0131, lng: 31.2089`.

### 2. Complete Absence of Egyptian Districts
- `GET /cities/782158572/districts` (Cairo) returns: `count: 0, total: 0, data: []`.
- `GET /cities/552977415/districts` (Giza) returns: `count: 0, total: 0, data: []`.
- **Finding:** Salla's API provides zero districts for Egyptian cities.

### 3. Currency and Shipping Assumptions
- In this demo store, shipping rates return 50 SAR in `SAR` due to the fallback `.ALL.ALL` zone.
- A live Egyptian store would use `EGP` and local shipping providers (e.g. Bosta, Aramex Egypt, Mylerz). Demo store rates do not represent live Egyptian fulfillment.

### 4. Separate Verified vs. Undocumented Rules for Egypt
- **Verified:** Salla's January 2026 changelog states that National Address fields (`country`, `city`, `district`, `block`, `short_address`, `building_number`, `additional_number`, `postal_code`, `geo_coordinates`) are mandatory for `ship_to`.
- **Undocumented:** Whether Salla's validator:
  - Allows `district` to be omitted or null for Egyptian cities.
  - Allows `short_address` (Saudi-only code) and `additional_number` to be omitted for Egypt.
  - Requires or relaxes `block` and `geo_coordinates`.

---

## 8. Readiness Assessment & Next Step

- **Safety Checklist:**
  - Demo store verified: **YES** (`918791227`, `type: demo`).
  - Isolated token: **YES** (Channel 6).
  - Synthetic customer data: **YES**.
  - Notifications suppressed: **YES** (`receiver.notify: false`).
  - No gateway/carrier mutations: **YES** (COD, manual carrier).
  - No production code modified: **YES**.
- **Execution Status:** **STOPPED. Waiting for explicit user authorization before making any mutating API call.**
