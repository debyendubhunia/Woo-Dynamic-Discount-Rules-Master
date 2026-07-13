# Woo Dynamic Discount Rules Master

An enterprise-grade, high-performance dynamic discount engine for WooCommerce. This plugin allows store owners to create complex, targeted discount rules and monitor their performance in real-time via a modern analytics dashboard.

---

## 🚀 Key Features

*   **Diverse Discount Types**: Support for product-level, category-level, cart-level, Buy X Get Y (BOGO), and user-specific (role/VIP/wholesale) rules.
*   **Live Analytics Dashboard**: Beautifully designed UI showing Campaign Revenue, Discounts Distributed, Impacted Orders, Active Rules Ratio, and trend line charts.
*   **Recalculation Protection**: Built-in mechanism to prevent double-discounting and price dilution when WooCommerce recalculates cart totals multiple times.
*   **Localization Support**: Automatically decodes and displays the store's currency symbol.

---

## 📋 Supported Discount Rule Types

The plugin supports 29 distinct discount rule types across 6 logical groups:

### 1. Product-Level Discounts
*   **Fixed Product Discount**: Fixed money amount off per product.
*   **Percentage Product Discount**: Percentage discount off product price.
*   **Product Bundle Discount**: Discount when a defined bundle of products is purchased together.
*   **Product-Specific Pricing**: Custom specific price for designated products.
*   **Product Variation Discount**: Unique discounts for specific product variations.

### 2. Category-Level Discounts
*   **Category Percentage Discount**: Percentage off all products in selected categories.
*   **Category Fixed Discount**: Fixed discount off all products in selected categories.
*   **Category Quantity Discount**: Tiered discounts when purchasing specific quantities within categories.
*   **Multi-Category Rules**: Custom rules spanning multiple combined product categories.

### 3. Cart-Level Discounts
*   **Fixed Cart Discount**: Flat discount amount off the entire cart total.
*   **Percentage Cart Discount**: Percentage discount off the entire cart total.
*   **Cart Quantity Rules**: Discounts triggered by the total number of items in the cart.
*   **Cart Total Rules**: Discounts triggered by the final cart checkout total.
*   **Cart Subtotal Rules**: Discounts triggered by the cart subtotal before taxes/shipping.

### 4. Buy X Get Y (BOGO)
*   **Buy X Get Y Free**: Classic buy one or more, get one or more free rules.
*   **Buy X Get Y Discount**: Buy X, get Y at a defined percentage or fixed discount.
*   **Buy Product X Get Product Y**: Special link rules tying two distinct products.
*   **Buy Category X Get Category Y**: Category-level BOGO promotions.
*   **Quantity-Based Rewards**: Tiered reward rates based on cart quantity thresholds.

### 5. User-Specific Pricing
*   **Role-Based Pricing**: Pricing customized per user roles (e.g. Subscriber, Customer, Administrator).
*   **Customer-Specific Pricing**: Custom rates tied directly to individual WordPress user IDs.
*   **VIP Pricing**: Custom promotions tailored to VIP customer cohorts.
*   **Wholesale Pricing**: Bulk/wholesale customer pricing configurations.

### 6. Advanced Conditional Rules
*   **First Order Discount**: Applied exclusively to new customers' initial checkout.
*   **Repeat Customer Discount**: Promotions for returning customers with order history.
*   **Purchase History Discount**: Custom threshold discounts based on historical lifetime spend.
*   **Scheduled Discount**: Automated time-bounded rules (e.g., weekend or flash sales).
*   **Country Rules**: Location-targeted discounts based on billing or shipping country.
*   **State Rules**: Location-targeted discounts based on billing or shipping state.
*   **Shipping Method Rules**: Discounts tied to selecting a specific shipping rate.
*   **Payment Method Rules**: Discounts tied to selecting a specific payment method (e.g., Cash on Delivery).

---

## 🛠️ Step-by-Step Guide

### Step 1: Installation & Activation
1. Download or clone this plugin folder into your WordPress site's plugins directory:
   `wp-content/plugins/woo-dynamic-discount-rule-master/`
2. Go to your WordPress Admin Dashboard.
3. Navigate to **Plugins** > **Installed Plugins**.
4. Locate **Woo Dynamic Discount Rules Master** and click **Activate**.

### Step 2: Creating a Discount Rule
1. In the WordPress sidebar menu, click on the **Discount Rules** tab.
2. Navigate to the **Rules List** submenu.
3. Click the **+ Add New Rule** button.
4. Fill out the form step-by-step:
    *   **Rule Name**: Give your campaign a descriptive name (e.g. `Buy 1 Get 1 Free Promo`).
    *   **Rule Group & Discount Type**: Choose a category (e.g., *Buy X Get Y*) and a specific discount type (e.g., *Buy X Get Y Free*).
    *   **Discount Value / BOGO Settings**: Define the discount amount, percentages, or the buy/get product settings.
    *   **Filters**: Optionally select specific products or categories that this rule should include or exclude.
    *   **Priority**: Set a priority number (higher priority rules run first).
    *   **Stop Further Rules**: Check this box if you want to prevent subsequent lower-priority rules from executing when this rule matches.
5. Click **Save** to create and activate the rule.

### Step 3: Checking Out an Order (Client Side)
1. Add qualifying items matching your active rule to the WooCommerce cart.
2. The discount will automatically apply as an adjusted item price (e.g., for product/BOGO rules) or a negative fee (e.g., for cart rules).
3. Proceed to the checkout page.
4. Place the order using any payment method (including *Cash on Delivery*).

### Step 4: Tracking Performance (Dashboard & Analytics)
1. Once an order is completed, navigate to **Discount Rules** > **Dashboard** in the WordPress admin panel.
2. The dashboard automatically pulls database metrics for the selected date range:
    *   **Campaign Revenue**: The net revenue generated by orders using discount rules.
    *   **Discounts Distributed**: Total value of direct coupons/rules applied to completed orders.
    *   **Orders Impacted**: The total number of completed orders utilizing active rules.
    *   **Top Rules Performance**: A table showing usages and value generated by each rule.
    *   **Estimated Product/Customer performance tables** and a **Trend History SVG chart**.
3. You can filter the metrics by changing the date range and clicking **Filter**, or download the CSV report by clicking **Export Report**.

---

## 🛡️ Technical Architecture

### Cart Recalculation Resiliency
During the checkout lifecycle, WooCommerce recalculates totals multiple times. The plugin implements a price cache on cart items (`wddrm_original_price`) to reset prices to their database values at the start of every recalculation run. This ensures:
*   No double-discounting or price compounding.
*   Correct discount amount values are captured and saved to the database on checkout completion.

### Database Schema
Data is stored across the following database tables:
*   `{prefix}wddrm_rules`: Stores rule configurations, status, priority, and conditions.
*   `{prefix}wddrm_rule_applications`: Logs each order checkout utilizing a rule, tracking order ID, user ID, discount amount, and original subtotal for analytics.
*   `{prefix}wddrm_user_rule_tracking`: Tracks usage counts per user to enforce rules limits.