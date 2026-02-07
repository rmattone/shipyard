<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;
use Carbon\Carbon;
use RuntimeException;

class CertbotService
{
    public function __construct(
        private SSHService $sshService,
        private NginxService $nginxService
    ) {}

    /**
     * Obtain SSL certificate for a specific domain.
     */
    public function obtainCertificateForDomain(Domain $domain, string $email): array
    {
        $app = $domain->application;

        $this->sshService->connect($app->server);

        // Check if certbot is installed
        $result = $this->sshService->execute('which certbot');
        if (!$result['success']) {
            throw new RuntimeException('Certbot is not installed on the server');
        }

        // Obtain certificate using webroot method
        $webroot = $app->getDocumentRoot();
        $command = sprintf(
            'certbot certonly --webroot -w %s -d %s --email %s --agree-tos --non-interactive',
            $webroot,
            $domain->domain,
            $email
        );

        $result = $this->sshService->execute($command, 120);

        if (!$result['success']) {
            $this->sshService->disconnect();
            throw new RuntimeException("Failed to obtain SSL certificate: {$result['output']}");
        }

        // Get certificate info to update the domain
        $certInfo = $this->getCertificateInfo($domain->domain);

        $this->sshService->disconnect();

        // Update domain record
        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => $certInfo['expiry_date'],
            'ssl_issuer' => $certInfo['issuer'],
        ]);

        // Redeploy nginx config with SSL
        $this->nginxService->deploy($app);

        return [
            'success' => true,
            'message' => 'SSL certificate obtained and configured successfully',
            'output' => $result['output'],
            'domain' => $domain->fresh(),
        ];
    }

    /**
     * Check SSL certificate status for a specific domain.
     */
    public function checkDomainStatus(Domain $domain): array
    {
        $app = $domain->application;

        $this->sshService->connect($app->server);

        $certPath = "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem";

        // Check if certificate file exists
        $existsResult = $this->sshService->execute("test -f {$certPath} && echo 'exists'");
        if (!$existsResult['success'] || trim($existsResult['output']) !== 'exists') {
            $this->sshService->disconnect();
            return [
                'exists' => false,
                'valid' => false,
                'expiry_date' => null,
                'days_remaining' => null,
                'issuer' => null,
            ];
        }

        // Get certificate dates
        $datesResult = $this->sshService->execute("openssl x509 -in {$certPath} -noout -dates 2>/dev/null");

        // Get certificate issuer
        $issuerResult = $this->sshService->execute("openssl x509 -in {$certPath} -noout -issuer 2>/dev/null");

        $this->sshService->disconnect();

        $expiry = null;
        $daysRemaining = null;

        if ($datesResult['success']) {
            preg_match('/notAfter=(.+)/', $datesResult['output'], $matches);
            if (isset($matches[1])) {
                $expiry = strtotime($matches[1]);
                $daysRemaining = (int) ceil(($expiry - time()) / 86400);
            }
        }

        $issuer = null;
        if ($issuerResult['success']) {
            preg_match('/O\s*=\s*([^,\/]+)/', $issuerResult['output'], $matches);
            $issuer = isset($matches[1]) ? trim($matches[1]) : "Let's Encrypt";
        }

        // Update domain record if we have new info
        if ($expiry) {
            $domain->update([
                'ssl_expires_at' => Carbon::createFromTimestamp($expiry),
                'ssl_issuer' => $issuer,
            ]);
        }

        return [
            'exists' => true,
            'valid' => $expiry && $expiry > time(),
            'expiry_date' => $expiry ? date('Y-m-d H:i:s', $expiry) : null,
            'days_remaining' => $daysRemaining,
            'issuer' => $issuer,
        ];
    }

    /**
     * Get certificate information without full status check.
     */
    private function getCertificateInfo(string $domainName): array
    {
        $certPath = "/etc/letsencrypt/live/{$domainName}/fullchain.pem";

        // Get certificate dates
        $datesResult = $this->sshService->execute("openssl x509 -in {$certPath} -noout -dates 2>/dev/null");

        // Get certificate issuer
        $issuerResult = $this->sshService->execute("openssl x509 -in {$certPath} -noout -issuer 2>/dev/null");

        $expiryDate = null;
        if ($datesResult['success']) {
            preg_match('/notAfter=(.+)/', $datesResult['output'], $matches);
            if (isset($matches[1])) {
                $expiryDate = Carbon::createFromTimestamp(strtotime($matches[1]));
            }
        }

        $issuer = "Let's Encrypt";
        if ($issuerResult['success']) {
            preg_match('/O\s*=\s*([^,\/]+)/', $issuerResult['output'], $matches);
            if (isset($matches[1])) {
                $issuer = trim($matches[1]);
            }
        }

        return [
            'expiry_date' => $expiryDate,
            'issuer' => $issuer,
        ];
    }

    /**
     * @deprecated Use obtainCertificateForDomain instead
     */
    public function obtainCertificate(Application $app, string $email): array
    {
        $this->sshService->connect($app->server);

        // Check if certbot is installed
        $result = $this->sshService->execute('which certbot');
        if (!$result['success']) {
            throw new RuntimeException('Certbot is not installed on the server');
        }

        // Obtain certificate using webroot method
        $webroot = $app->getDocumentRoot();
        $command = sprintf(
            'certbot certonly --webroot -w %s -d %s --email %s --agree-tos --non-interactive',
            $webroot,
            $app->domain,
            $email
        );

        $result = $this->sshService->execute($command, 120);

        if (!$result['success']) {
            $this->sshService->disconnect();
            throw new RuntimeException("Failed to obtain SSL certificate: {$result['output']}");
        }

        $this->sshService->disconnect();

        // Update application
        $app->update(['ssl_enabled' => true]);

        // Also update the primary domain if it exists
        $primaryDomain = $app->primaryDomain();
        if ($primaryDomain) {
            $primaryDomain->update(['ssl_enabled' => true]);
        }

        // Redeploy nginx config with SSL
        $this->nginxService->deploy($app);

        return [
            'success' => true,
            'message' => 'SSL certificate obtained and configured successfully',
            'output' => $result['output'],
        ];
    }

    public function renewCertificates(Application $app): array
    {
        $this->sshService->connect($app->server);

        $result = $this->sshService->execute('certbot renew --non-interactive', 300);

        $this->sshService->disconnect();

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Certificates renewed' : 'Renewal failed',
            'output' => $result['output'],
        ];
    }

    /**
     * @deprecated Use checkDomainStatus instead
     */
    public function checkCertificateStatus(Application $app): array
    {
        $this->sshService->connect($app->server);

        $certPath = "/etc/letsencrypt/live/{$app->domain}/fullchain.pem";

        $result = $this->sshService->execute("openssl x509 -in {$certPath} -noout -dates 2>/dev/null");

        $this->sshService->disconnect();

        if (!$result['success']) {
            return [
                'exists' => false,
                'valid' => false,
                'expiry' => null,
            ];
        }

        preg_match('/notAfter=(.+)/', $result['output'], $matches);
        $expiry = isset($matches[1]) ? strtotime($matches[1]) : null;

        return [
            'exists' => true,
            'valid' => $expiry && $expiry > time(),
            'expiry' => $expiry ? date('Y-m-d H:i:s', $expiry) : null,
            'days_remaining' => $expiry ? ceil(($expiry - time()) / 86400) : null,
        ];
    }
}
