<?php

namespace App\Services\Mcp\OAuth;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicMetadataDns
{
    public function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(fn ($row) => $row['ip'] ?? $row['ipv6'] ?? null, $records ?: [])));
    }

    public function resolve(string $host): string
    {
        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        foreach ($addresses as $address) {
            // Reject all special-use ranges, including mapped IPv4, NAT64 and documentation networks.
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3', '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2002::/16', '2620:4f:8000::/48', '3fff::/20', 'fc00::/7', 'fe80::/10', 'ff00::/8'])
                || (str_contains($address, ':') && ! IpUtils::checkIp($address, '2000::/3'))) {
                throw new \UnexpectedValueException('Invalid client metadata');
            }
        }

        return $addresses[0];
    }
}
