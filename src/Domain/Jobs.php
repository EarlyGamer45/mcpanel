<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

use App\Core\Crypto;
use App\Core\Job;
use App\Core\Mailer;
use App\Core\Queue;
use App\Domain\ProvisioningService;

final class ProvisionServerJob implements Job
{
    public function handle(array $payload): void
    {
        $serviceId = (int) ($payload['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return;
        }
        ProvisioningService::provision($serviceId);
        Queue::push(CheckInstallJob::class, ['service_id' => $serviceId, 'attempt' => 1], 120, 'provisioning');
    }
}

final class CheckInstallJob implements Job
{
    public function handle(array $payload): void
    {
        $serviceId = (int) ($payload['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return;
        }
        ProvisioningService::checkInstall($serviceId, (int) ($payload['attempt'] ?? 1));
    }
}

/**
 * E-mails are queued with the recipient encrypted, so the jobs table never holds
 * personal data in clear text.
 */
final class SendMailJob implements Job
{
    public function handle(array $payload): void
    {
        $to = Crypto::decrypt((string) ($payload['to'] ?? ''));
        if ($to === null || $to === '') {
            return;
        }
        Mailer::send($to, (string) ($payload['subject'] ?? ''), (string) ($payload['html'] ?? ''), (string) ($payload['template'] ?? ''));
    }
}
