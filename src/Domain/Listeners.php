<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Events;
use App\Core\Mailer;
use App\Core\Queue;

/**
 * Domain events -> customer e-mails. Everything is queued, so a slow SMTP
 * server never slows down checkout or a power action.
 */
final class Listeners
{
    public static function register(): void
    {
        Events::listen('order.paid', static function (array $payload): void {
            $order = App::db()->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => (int) ($payload['order_id'] ?? 0)]);
            if ($order === null) {
                return;
            }
            $user = UserRepository::find((int) $order['user_id']);
            if ($user === null) {
                return;
            }
            self::mail(
                UserRepository::present($user)['email'],
                __('mail_order_paid_subject', ['number' => (string) $order['number']]),
                __('mail_order_paid_heading'),
                '<p>' . __('mail_order_paid_body', ['number' => e((string) $order['number']), 'total' => money((int) $order['total_cents'])]) . '</p>',
                'order_paid',
                url('/client')
            );
        });

        Events::listen('service.created', static function (array $payload): void {
            $service = Services::find((int) ($payload['service_id'] ?? 0));
            if ($service === null) {
                return;
            }
            $user = UserRepository::find((int) $service['user_id']);
            if ($user === null) {
                return;
            }
            self::mail(
                UserRepository::present($user)['email'],
                __('mail_service_created_subject', ['name' => (string) $service['name']]),
                __('mail_service_created_heading'),
                '<p>' . __('mail_service_created_body', ['name' => e((string) $service['name'])]) . '</p>',
                'service_created',
                url('/client/service/' . (int) $service['id'])
            );
        });

        Events::listen('service.suspended', static function (array $payload): void {
            $service = Services::find((int) ($payload['service_id'] ?? 0));
            if ($service === null) {
                return;
            }
            $user = UserRepository::find((int) $service['user_id']);
            if ($user === null) {
                return;
            }
            self::mail(
                UserRepository::present($user)['email'],
                __('mail_service_suspended_subject', ['name' => (string) $service['name']]),
                __('mail_service_suspended_heading'),
                '<p>' . __('mail_service_suspended_body', ['name' => e((string) $service['name']), 'reason' => e((string) ($payload['reason'] ?? ''))]) . '</p>',
                'service_suspended',
                url('/client/invoices')
            );
        });

        Events::listen('job.failed', static function (array $payload): void {
            $admin = (string) App::setting('company_email', '');
            if ($admin === '') {
                return;
            }
            self::mail(
                $admin,
                'Job failed: ' . (string) ($payload['job'] ?? ''),
                __('mail_job_failed_heading'),
                '<p>' . e((string) ($payload['error'] ?? '')) . '</p>',
                'job_failed',
                url('/' . config('security.admin_path') . '/logs')
            );
        });
    }

    private static function mail(string $to, string $subject, string $heading, string $bodyHtml, string $template, string $ctaUrl = ''): void
    {
        if (trim($to) === '') {
            return;
        }
        Queue::push(\App\Domain\Jobs\SendMailJob::class, [
            'to' => Crypto::encrypt($to),
            'subject' => $subject,
            'html' => Mailer::template($heading, $bodyHtml, [
                'cta_url' => $ctaUrl,
                'cta_label' => __('open_dashboard'),
            ]),
            'template' => $template,
        ]);
    }
}
