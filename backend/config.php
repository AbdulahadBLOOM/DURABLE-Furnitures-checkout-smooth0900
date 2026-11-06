<?php
/**
 * Durable Furnitures – backend configuration.
 * Do NOT edit secrets here on a live server: create backend/config.local.php
 * that returns an array with only the keys you want to override.
 */
$config = [
    // ── Database (MySQL / MariaDB)
    'db_host' => getenv('DF_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DF_DB_PORT') ?: '3306',
    'db_name' => getenv('DF_DB_NAME') ?: 'durable_furnitures',
    'db_user' => getenv('DF_DB_USER') ?: 'root',
    'db_pass' => getenv('DF_DB_PASS') ?: '',

    // ── Store details (shown on invoice / emails)
    'store_name'    => 'Durable Furnitures',
    'store_email'   => 'hello@durablefurnitures.pk',   // also receives new-order alerts
    'store_phone'   => '+92 300 1234567',
    'store_address' => '14, Furniture Market, Karachi – 75600',
    'currency'      => 'Rs.',
    'timezone'      => 'Asia/Karachi',

    // ── Pricing rules
    'delivery_fee'      => 0,      // flat fee in Rs. (site promises free delivery)
    'reviews_auto_approve' => true,   // false = every new review waits for admin approval
    'customer_cancel_statuses' => ['pending', 'confirmed'],   // order statuses in which a customer may cancel online
    // Membership tiers shown on the customer dashboard: [name, lifetime spend (Rs.) needed]
    'loyalty_tiers' => [['Bronze', 0], ['Silver', 50000], ['Gold', 150000], ['Platinum', 400000]],
    'free_delivery_over'=> 0,      // 0 = no threshold; e.g. 50000 = free above Rs.50,000
    'max_qty_per_item'  => 10,

    // ── Payment methods offered at checkout (key => label / instructions)
    'payment_methods' => [
        'cod'  => ['label' => 'Cash on Delivery', 'note' => 'Pay in cash when your furniture is delivered.'],
        'bank' => ['label' => 'Bank Transfer / Easypaisa / JazzCash',
                   'note'  => 'Transfer to: Durable Furnitures – Meezan Bank A/C 0000-0000000000 (edit in config.php). Send the receipt on WhatsApp +92 300 1234567 quoting your order number.'],
    ],

    // ── Email (uses PHP mail(); every message is also logged to data/mail.log)
    'send_email' => true,

    // ── Anti-spam
    'orders_per_ip_per_10min' => 5,

    // ── Session cookie names (admin and customer are fully separate logins)
    'admin_session'    => 'df_admin',
    'customer_session' => 'df_customer',

    // ── Admin sign-up protection
    // The very first admin can always sign up freely. After that, creating a new admin
    // account requires this secret code. Leave empty to CLOSE admin sign-up entirely.
    // Set it in config.local.php, never commit a real code:  'admin_signup_code' => 'my-long-secret'
    'admin_signup_code' => '',

    // ── Login / sign-up throttling (per IP)
    'max_failed_logins_per_15min' => 10,
    'max_signups_per_hour'        => 10,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, (array) require $local);
}
date_default_timezone_set($config['timezone']);
return $config;
