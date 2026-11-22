<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;

/**
 * Single source of truth for the schema. The same definitions generate the
 * MariaDB DDL used in production and the SQLite DDL used by the demo/tests, so
 * the two can never drift apart. `bin/console schema:dump` writes the MariaDB
 * version to database/schema.mariadb.sql for review.
 *
 * Column spec grammar: "type[:argument] [null] [unique] [index] [default:value]"
 * Types: pk, string:N, text, mediumtext, int, bigint, bool, ts, json.
 */
final class Schema
{
    /** @return array<string,array<string,mixed>> */
    public static function tables(): array
    {
        return [
            'migrations' => [
                'columns' => [
                    'id' => 'pk',
                    'migration' => 'string:190 unique',
                    'batch' => 'int default:1',
                    'ran_at' => 'ts',
                ],
            ],
            'sequences' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:60 unique',
                    'value' => 'int default:0',
                    'updated_at' => 'ts null',
                ],
            ],
            'users' => [
                'columns' => [
                    'id' => 'pk',
                    'username' => 'string:64 unique',
                    'email_enc' => 'text',
                    'email_index' => 'string:64 unique',
                    'password_hash' => 'string:255',
                    'role' => "string:20 default:'customer'",
                    'status' => "string:20 default:'active'",
                    'email_verified_at' => 'ts null',
                    'totp_secret_enc' => 'text null',
                    'totp_enabled' => 'bool default:0',
                    'recovery_codes_enc' => 'text null',
                    'country' => 'string:2 null',
                    'company_enc' => 'text null',
                    'vat_number_enc' => 'text null',
                    'vat_number_index' => 'string:64 null',
                    'address_enc' => 'text null',
                    'city_enc' => 'text null',
                    'postal_code_enc' => 'text null',
                    'phone_enc' => 'text null',
                    'locale' => "string:5 default:'en'",
                    'timezone' => "string:64 default:'Europe/Rome'",
                    'currency' => "string:3 default:'EUR'",
                    'referral_code' => 'string:16 null unique',
                    'referred_by' => 'int null',
                    'credit_cents' => 'bigint default:0',
                    'loyalty_points' => 'int default:0',
                    'failed_logins' => 'int default:0',
                    'locked_until' => 'ts null',
                    'last_login_at' => 'ts null',
                    'last_login_ip' => 'string:45 null',
                    'notes' => 'text null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                    'deleted_at' => 'ts null',
                ],
                'indexes' => [['status'], ['role']],
            ],
            'user_sessions' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'session_id' => 'string:128 index',
                    'ip' => 'string:45 null',
                    'user_agent' => 'string:255 null',
                    'last_seen_at' => 'ts',
                    'revoked_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'password_resets' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'token_hash' => 'string:64 index',
                    'expires_at' => 'ts',
                    'used_at' => 'ts null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'email_verifications' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'token_hash' => 'string:64 index',
                    'expires_at' => 'ts',
                    'used_at' => 'ts null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'audit_logs' => [
                'columns' => [
                    'id' => 'pk',
                    'actor_id' => 'int null',
                    'actor_type' => "string:20 default:'user'",
                    'action' => 'string:120',
                    'subject_type' => 'string:60 null',
                    'subject_id' => 'int null',
                    'ip' => 'string:45 null',
                    'meta_json' => 'json null',
                    'created_at' => 'ts',
                ],
                'indexes' => [['action'], ['created_at']],
            ],
            'settings' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120 unique',
                    'value_json' => 'text null',
                    'updated_at' => 'ts null',
                ],
            ],
            'feature_flags' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:80 unique',
                    'enabled' => 'bool default:0',
                    'description' => 'string:255 null',
                ],
            ],
            'categories' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120',
                    'slug' => 'string:120 unique',
                    'description' => 'text null',
                    'sort' => 'int default:0',
                    'visible' => 'bool default:1',
                ],
            ],
            'products' => [
                'columns' => [
                    'id' => 'pk',
                    'category_id' => 'int',
                    'name' => 'string:160',
                    'slug' => 'string:160 unique',
                    'description' => 'text null',
                    'image' => 'string:255 null',
                    'tier' => "string:20 default:'standard'",
                    'sort' => 'int default:0',
                    'visible' => 'bool default:1',
                    'nest_id' => 'int null',
                    'egg_id' => 'int null',
                    'docker_image' => 'string:190 null',
                    'startup' => 'text null',
                    'min_ram_mb' => 'int default:1024',
                    'max_ram_mb' => 'int default:16384',
                    'ram_step_mb' => 'int default:1024',
                    'allowed_versions_json' => 'json null',
                    'meta_json' => 'json null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['category_id' => 'categories'],
                'indexes' => [['visible']],
            ],
            'product_prices' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'int',
                    'cycle' => 'string:20',
                    'price_cents' => 'bigint default:0',
                    'setup_cents' => 'bigint default:0',
                ],
                'foreign' => ['product_id' => 'products'],
                'indexes' => [['product_id', 'cycle']],
            ],
            'resource_options' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'int null',
                    'resource' => 'string:20',
                    'min_value' => 'int default:0',
                    'max_value' => 'int default:0',
                    'step' => 'int default:1',
                    'included' => 'int default:0',
                    'unit_cents' => 'bigint default:0',
                    'unit' => "string:20 default:'unit'",
                    'sort' => 'int default:0',
                ],
                'foreign' => ['product_id' => 'products'],
            ],
            'addons' => [
                'columns' => [
                    'id' => 'pk',
                    'code' => 'string:60 unique',
                    'name' => 'string:120',
                    'description' => 'text null',
                    'price_cents' => 'bigint default:0',
                    'cycle' => "string:20 default:'monthly'",
                    'type' => "string:30 default:'extra'",
                    'meta_json' => 'json null',
                    'visible' => 'bool default:1',
                ],
            ],
            'product_addons' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'int',
                    'addon_id' => 'int',
                    'enabled' => 'bool default:1',
                ],
                'foreign' => ['product_id' => 'products', 'addon_id' => 'addons'],
                'indexes' => [['product_id', 'addon_id']],
            ],
            'locations' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120',
                    'slug' => 'string:120 unique',
                    'country' => 'string:2',
                    'pterodactyl_location_id' => 'int null',
                    'ddos_note' => 'string:255 null',
                    'visible' => 'bool default:1',
                    'maintenance' => 'bool default:0',
                    'sort' => 'int default:0',
                ],
            ],
            'nodes' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120',
                    'location_id' => 'int',
                    'pterodactyl_node_id' => 'int',
                    'memory_mb' => 'int default:0',
                    'disk_mb' => 'int default:0',
                    'cpu_percent' => 'int default:0',
                    'overallocation_pct' => 'int default:100',
                    'monthly_cost_cents' => 'bigint default:0',
                    'maintenance' => 'bool default:0',
                    'visible' => 'bool default:1',
                ],
                'foreign' => ['location_id' => 'locations'],
            ],
            'carts' => [
                'columns' => [
                    'id' => 'pk',
                    'token' => 'string:64 unique',
                    'user_id' => 'int null',
                    'email_enc' => 'text null',
                    'currency' => "string:3 default:'EUR'",
                    'status' => "string:20 default:'open'",
                    'reminder_sent_at' => 'ts null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
                'indexes' => [['status']],
            ],
            'cart_items' => [
                'columns' => [
                    'id' => 'pk',
                    'cart_id' => 'int',
                    'product_id' => 'int',
                    'location_id' => 'int null',
                    'cycle' => 'string:20',
                    'config_json' => 'json',
                    'price_cents' => 'bigint default:0',
                    'created_at' => 'ts',
                ],
                'foreign' => ['cart_id' => 'carts', 'product_id' => 'products'],
            ],
            'coupons' => [
                'columns' => [
                    'id' => 'pk',
                    'code' => 'string:40 unique',
                    'type' => "string:10 default:'percent'",
                    'value' => 'int default:0',
                    'valid_from' => 'ts null',
                    'valid_to' => 'ts null',
                    'max_uses' => 'int default:0',
                    'uses' => 'int default:0',
                    'per_user_limit' => 'int default:1',
                    'first_purchase_only' => 'bool default:0',
                    'recurring' => 'bool default:0',
                    'applies_to_json' => 'json null',
                    'active' => 'bool default:1',
                ],
            ],
            'coupon_redemptions' => [
                'columns' => [
                    'id' => 'pk',
                    'coupon_id' => 'int',
                    'user_id' => 'int',
                    'order_id' => 'int',
                    'amount_cents' => 'bigint default:0',
                    'created_at' => 'ts',
                ],
                'foreign' => ['coupon_id' => 'coupons', 'user_id' => 'users', 'order_id' => 'orders'],
                'indexes' => [['coupon_id']],
            ],
            'orders' => [
                'columns' => [
                    'id' => 'pk',
                    'number' => 'string:32 unique',
                    'user_id' => 'int',
                    'status' => "string:30 default:'pending_payment'",
                    'currency' => "string:3 default:'EUR'",
                    'subtotal_cents' => 'bigint default:0',
                    'discount_cents' => 'bigint default:0',
                    'tax_cents' => 'bigint default:0',
                    'total_cents' => 'bigint default:0',
                    'coupon_id' => 'int null',
                    'invoice_id' => 'int null',
                    'risk_score' => 'int default:0',
                    'meta_json' => 'json null',
                    'paid_at' => 'ts null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users', 'coupon_id' => 'coupons'],
                'indexes' => [['status'], ['user_id']],
            ],
            'order_items' => [
                'columns' => [
                    'id' => 'pk',
                    'order_id' => 'int',
                    'product_id' => 'int',
                    'service_id' => 'int null',
                    'description' => 'string:255',
                    'cycle' => 'string:20',
                    'config_json' => 'json null',
                    'unit_cents' => 'bigint default:0',
                    'quantity' => 'int default:1',
                    'total_cents' => 'bigint default:0',
                ],
                'foreign' => ['order_id' => 'orders', 'product_id' => 'products'],
            ],
            'invoices' => [
                'columns' => [
                    'id' => 'pk',
                    'number' => 'string:32 unique',
                    'series' => "string:10 default:'A'",
                    'year' => 'int',
                    'sequence' => 'int',
                    'user_id' => 'int',
                    'order_id' => 'int null',
                    'status' => "string:20 default:'pending'",
                    'currency' => "string:3 default:'EUR'",
                    'subtotal_cents' => 'bigint default:0',
                    'discount_cents' => 'bigint default:0',
                    'tax_cents' => 'bigint default:0',
                    'total_cents' => 'bigint default:0',
                    'tax_mode' => "string:30 default:'domestic'",
                    'vat_country' => 'string:2 null',
                    'vat_rate_bp' => 'int default:0',
                    'reverse_charge' => 'bool default:0',
                    'customer_json' => 'json null',
                    'due_at' => 'ts null',
                    'paid_at' => 'ts null',
                    'pdf_path' => 'string:255 null',
                    'notes' => 'text null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
                'indexes' => [['status'], ['year', 'series']],
            ],
            'invoice_lines' => [
                'columns' => [
                    'id' => 'pk',
                    'invoice_id' => 'int',
                    'description' => 'string:255',
                    'quantity' => 'int default:1',
                    'unit_cents' => 'bigint default:0',
                    'total_cents' => 'bigint default:0',
                    'tax_rate_bp' => 'int default:0',
                ],
                'foreign' => ['invoice_id' => 'invoices'],
            ],
            'credit_notes' => [
                'columns' => [
                    'id' => 'pk',
                    'number' => 'string:32 unique',
                    'invoice_id' => 'int',
                    'amount_cents' => 'bigint default:0',
                    'reason' => 'string:255 null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['invoice_id' => 'invoices'],
            ],
            'payments' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'order_id' => 'int null',
                    'invoice_id' => 'int null',
                    'gateway' => 'string:30',
                    'external_id' => 'string:190 null',
                    'amount_cents' => 'bigint default:0',
                    'currency' => "string:3 default:'EUR'",
                    'status' => "string:20 default:'pending'",
                    'idempotency_key' => 'string:120 unique',
                    'meta_json' => 'json null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
                'indexes' => [['status'], ['gateway', 'external_id']],
            ],
            'payment_methods' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'gateway' => 'string:30',
                    'token_enc' => 'text',
                    'brand' => 'string:30 null',
                    'last4' => 'string:4 null',
                    'exp_month' => 'int null',
                    'exp_year' => 'int null',
                    'is_default' => 'bool default:0',
                    'created_at' => 'ts',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'webhook_events' => [
                'columns' => [
                    'id' => 'pk',
                    'gateway' => 'string:30',
                    'external_id' => 'string:190',
                    'event_type' => 'string:80',
                    'payload_json' => 'mediumtext null',
                    'processed_at' => 'ts null',
                    'error' => 'string:255 null',
                    'created_at' => 'ts',
                ],
                'indexes' => [['gateway', 'external_id']],
            ],
            'wallets' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int unique',
                    'balance_cents' => 'bigint default:0',
                    'currency' => "string:3 default:'EUR'",
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'wallet_topups' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'amount_cents' => 'bigint default:0',
                    'reference' => 'string:60 unique',
                    'status' => "string:20 default:'pending'",
                    'created_at' => 'ts',
                    'paid_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
                'indexes' => [['status']],
            ],
            'wallet_transactions' => [
                'columns' => [
                    'id' => 'pk',
                    'wallet_id' => 'int',
                    'amount_cents' => 'bigint',
                    'type' => 'string:30',
                    'reference' => 'string:120 null',
                    'balance_after_cents' => 'bigint default:0',
                    'meta_json' => 'json null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['wallet_id' => 'wallets'],
                'indexes' => [['wallet_id']],
            ],
            'services' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'order_id' => 'int null',
                    'product_id' => 'int',
                    'name' => 'string:160',
                    'status' => "string:20 default:'pending'",
                    'cycle' => "string:20 default:'monthly'",
                    'price_cents' => 'bigint default:0',
                    'currency' => "string:3 default:'EUR'",
                    'ram_mb' => 'int default:0',
                    'cpu_percent' => 'int default:0',
                    'disk_mb' => 'int default:0',
                    'location_id' => 'int null',
                    'node_id' => 'int null',
                    'addons_json' => 'json null',
                    'config_json' => 'json null',
                    'provision_key' => 'string:64 unique',
                    'provisioning_state' => "string:20 default:'queued'",
                    'pterodactyl_server_id' => 'int null',
                    'external_identifier' => 'string:32 null',
                    'ip' => 'string:45 null',
                    'port' => 'int null',
                    'next_due_at' => 'ts null',
                    'suspended_at' => 'ts null',
                    'terminated_at' => 'ts null',
                    'cancellation_at' => 'ts null',
                    'auto_renew' => 'bool default:1',
                    'suspension_reason' => 'string:255 null',
                    'metadata_json' => 'json null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users', 'product_id' => 'products', 'order_id' => 'orders'],
                'indexes' => [['status'], ['next_due_at'], ['user_id']],
            ],
            'service_events' => [
                'columns' => [
                    'id' => 'pk',
                    'service_id' => 'int',
                    'from_state' => 'string:20 null',
                    'to_state' => 'string:20',
                    'reason' => 'string:255 null',
                    'actor_id' => 'int null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['service_id' => 'services'],
            ],
            'provisioning_logs' => [
                'columns' => [
                    'id' => 'pk',
                    'service_id' => 'int',
                    'attempt' => 'int default:1',
                    'step' => 'string:60',
                    'success' => 'bool default:0',
                    'status_code' => 'int null',
                    'message' => 'text null',
                    'created_at' => 'ts',
                ],
                'foreign' => ['service_id' => 'services'],
            ],
            'tickets' => [
                'columns' => [
                    'id' => 'pk',
                    'number' => 'string:32 unique',
                    'user_id' => 'int',
                    'service_id' => 'int null',
                    'department' => "string:20 default:'technical'",
                    'priority' => "string:10 default:'normal'",
                    'status' => "string:30 default:'open'",
                    'subject' => 'string:200',
                    'assigned_to' => 'int null',
                    'first_response_at' => 'ts null',
                    'closed_at' => 'ts null',
                    'rating' => 'int null',
                    'created_at' => 'ts',
                    'updated_at' => 'ts null',
                ],
                'foreign' => ['user_id' => 'users'],
                'indexes' => [['status'], ['user_id']],
            ],
            'ticket_messages' => [
                'columns' => [
                    'id' => 'pk',
                    'ticket_id' => 'int',
                    'user_id' => 'int null',
                    'body' => 'text',
                    'internal' => 'bool default:0',
                    'created_at' => 'ts',
                ],
                'foreign' => ['ticket_id' => 'tickets'],
            ],
            'ticket_attachments' => [
                'columns' => [
                    'id' => 'pk',
                    'message_id' => 'int',
                    'filename' => 'string:190',
                    'path' => 'string:255',
                    'mime' => 'string:120',
                    'size' => 'int default:0',
                ],
                'foreign' => ['message_id' => 'ticket_messages'],
            ],
            'kb_articles' => [
                'columns' => [
                    'id' => 'pk',
                    'category' => 'string:80',
                    'title' => 'string:190',
                    'slug' => 'string:190 unique',
                    'body' => 'mediumtext',
                    'locale' => "string:5 default:'en'",
                    'published' => 'bool default:1',
                ],
            ],
            'legal_documents' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:60 unique',
                    'version' => 'string:20',
                    'body' => 'mediumtext',
                    'updated_at' => 'ts null',
                ],
            ],
            'legal_acceptances' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'int',
                    'document' => 'string:60',
                    'version' => 'string:20',
                    'ip' => 'string:45 null',
                    'accepted_at' => 'ts',
                ],
                'foreign' => ['user_id' => 'users'],
            ],
            'license_state' => [
                'columns' => [
                    'id' => 'pk',
                    'license_key_enc' => 'text null',
                    'plan' => 'string:40 null',
                    'fingerprint' => 'string:64 null',
                    'status' => "string:20 default:'unlicensed'",
                    'expires_at' => 'ts null',
                    'last_check_at' => 'ts null',
                    'last_valid_at' => 'ts null',
                    'payload_json' => 'json null',
                ],
            ],
            'mail_log' => [
                'columns' => [
                    'id' => 'pk',
                    'to_hash' => 'string:64',
                    'subject' => 'string:190',
                    'template' => 'string:80 null',
                    'status' => 'string:20',
                    'error' => 'string:255 null',
                    'created_at' => 'ts',
                ],
            ],
            'announcements' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:190',
                    'body' => 'text',
                    'level' => "string:20 default:'info'",
                    'published_at' => 'ts null',
                    'expires_at' => 'ts null',
                    'visible' => 'bool default:1',
                ],
            ],
            'jobs' => [
                'columns' => [
                    'id' => 'pk',
                    'queue' => "string:40 default:'default'",
                    'job' => 'string:190',
                    'payload_json' => 'mediumtext null',
                    'attempts' => 'int default:0',
                    'max_attempts' => 'int default:5',
                    'available_at' => 'ts',
                    'reserved_at' => 'ts null',
                    'reserved_by' => 'string:64 null',
                    'failed_at' => 'ts null',
                    'last_error' => 'text null',
                    'created_at' => 'ts',
                ],
                'indexes' => [['queue', 'available_at']],
            ],
        ];
    }

    /** @return list<string> */
    public static function ddl(string $dialect): array
    {
        $tables = self::tables();
        $statements = [];
        $foreign = [];

        foreach ($tables as $table => $definition) {
            $columns = [];
            $columnIndexes = [];
            foreach ($definition['columns'] as $name => $spec) {
                $columns[] = self::column($name, $spec, $dialect, $columnIndexes);
            }
            foreach ($definition['foreign'] ?? [] as $column => $target) {
                $constraint = self::identifier('fk', $table, $column);
                $sql = sprintf(
                    'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE CASCADE',
                    $constraint,
                    self::q($column),
                    self::q($target)
                );
                if ($dialect === 'sqlite') {
                    $columns[] = $sql;
                } else {
                    $foreign[] = sprintf('ALTER TABLE %s ADD %s', self::q($table), $sql);
                }
            }
            $statements[] = sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n  %s\n)%s",
                self::q($table),
                implode(",\n  ", $columns),
                $dialect === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''
            );

            foreach ($columnIndexes as $index) {
                $statements[] = self::indexStatement($table, $index['columns'], $index['unique'], $dialect);
            }
            foreach ($definition['indexes'] ?? [] as $indexColumns) {
                $statements[] = self::indexStatement($table, $indexColumns, false, $dialect);
            }
        }

        return array_merge($statements, $foreign);
    }

    public static function install(Database $db): void
    {
        $dialect = $db->isSqlite() ? 'sqlite' : 'mysql';
        foreach (self::ddl($dialect) as $sql) {
            try {
                $db->pdo()->exec($sql);
            } catch (PDOException $e) {
                if (self::isBenign($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function isBenign(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        return in_array($code, [1005, 1007, 1050, 1060, 1061, 1091, 1826, 1062], true)
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate');
    }

    /** @param list<string> $indexedColumns */
    private static function indexStatement(string $table, array $columns, bool $unique, string $dialect): string
    {
        $name = self::identifier(($unique ? 'uq' : 'ix'), $table, implode('_', $columns));

        return sprintf(
            'CREATE %sINDEX %s%s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $dialect === 'mysql' ? '' : 'IF NOT EXISTS ',
            self::q($name),
            self::q($table),
            implode(', ', array_map([self::class, 'q'], $columns))
        );
    }

    /** @param list<array<string>> $extraIndexes */
    private static function column(string $name, string $spec, string $dialect, array &$extraIndexes): string
    {
        $parts = preg_split('/\s+/', trim($spec)) ?: [];
        $typeSpec = (string) array_shift($parts);
        $nullable = in_array('null', $parts, true);
        $unique = in_array('unique', $parts, true);
        $index = in_array('index', $parts, true) || $unique;
        $default = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 'default:')) {
                $default = substr($part, 8);
            }
        }
        [$type, $argument] = array_pad(explode(':', $typeSpec, 2), 2, null);

        if ($type === 'pk') {
            return $dialect === 'sqlite'
                ? self::q($name) . ' INTEGER PRIMARY KEY AUTOINCREMENT'
                : self::q($name) . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }

        $sqlType = match ($type) {
            'string' => $dialect === 'sqlite' ? 'TEXT' : 'VARCHAR(' . (int) ($argument ?: 190) . ')',
            'text' => 'TEXT',
            'mediumtext' => $dialect === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT',
            'int' => $dialect === 'sqlite' ? 'INTEGER' : 'INT',
            'bigint' => $dialect === 'sqlite' ? 'INTEGER' : 'BIGINT',
            'bool' => $dialect === 'sqlite' ? 'INTEGER' : 'TINYINT(1)',
            'ts' => $dialect === 'sqlite' ? 'TEXT' : 'DATETIME',
            'json' => $dialect === 'sqlite' ? 'TEXT' : 'JSON',
            default => throw new \InvalidArgumentException("Unknown column type [$type] for [$name]"),
        };

        $sql = self::q($name) . ' ' . $sqlType;
        $sql .= $nullable ? ' NULL' : ' NOT NULL';

        if ($default !== null) {
            if (strtolower($default) === 'null') {
                $sql .= ' DEFAULT NULL';
            } elseif (is_numeric($default)) {
                $sql .= ' DEFAULT ' . $default;
            } else {
                $sql .= " DEFAULT '" . str_replace("'", "''", trim($default, "'")) . "'";
            }
        }

        if ($index) {
            $extraIndexes[] = ['columns' => [$name], 'unique' => $unique];
        }

        return $sql;
    }

    private static function identifier(string $prefix, string ...$parts): string
    {
        $name = strtolower($prefix . '_' . implode('_', $parts));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? $name;

        return substr($name, 0, 60);
    }

    public static function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
