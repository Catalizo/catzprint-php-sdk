<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

/**
 * A short mDNS browse for "_catzprint._tcp.local." with ext-sockets. Without
 * ext-sockets browse() finds nothing.
 *
 * mDNS is unauthenticated: what it finds is only used (by LocalState) to map
 * a station ID that is already on the cloud endpoint list, or whose
 * fingerprint the user pinned, to its current private address.
 *
 * @internal
 */
final class Mdns
{
    public const SERVICE = '_catzprint._tcp.local.';
    public const GROUP = '224.0.0.251:5353';
    /** Longest browse (seconds). */
    public const MAX_TIMEOUT = 1.5;

    /** Bounds on what one browse keeps (instances, hosts; addresses per host). */
    public const MAX_NAMES = 256;
    public const MAX_ADDRESSES = 32;

    private const TYPE_A = 1;
    private const TYPE_PTR = 12;
    private const TYPE_TXT = 16;
    private const TYPE_SRV = 33;

    /** @var array<string, bool> instance names (lower case) */
    private array $ptr = [];
    /** @var array<string, array{port: int, target: string}> */
    private array $srv = [];
    /** @var array<string, array<string, string>> */
    private array $txt = [];
    /** @var array<string, list<string>> host (lower case) => IPv4s */
    private array $addr = [];
    /** @var array<string, string> instance => source IP of the packet */
    private array $src = [];
    /** @var array<string, string> instance lower => instance as sent */
    private array $name = [];

    public static function available(): bool
    {
        return extension_loaded('sockets');
    }

    /**
     * Sends a PTR query (QU bit set) to $dst ("ip:port": the mDNS group, or
     * a unicast address in tests) and collects answers until $timeout.
     *
     * @return list<MdnsStation>
     */
    public static function browse(float $timeout, string $dst = self::GROUP): array
    {
        if (!self::available()) {
            return [];
        }
        $timeout = max(0.05, min($timeout, self::MAX_TIMEOUT));
        [$ip, $port] = explode(':', $dst, 2) + [1 => '5353'];
        $port = (int) $port;
        $multicast = str_starts_with($ip, '224.');
        $query = self::buildQuery();

        $socks = [];
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($s === false) {
            return [];
        }
        @socket_bind($s, '0.0.0.0', 0);
        socket_set_nonblock($s);
        $socks[] = $s;
        if ($multicast) {
            @socket_set_option($s, IPPROTO_IP, IP_MULTICAST_TTL, 255);
            // Responders that ignore the QU bit answer to the group: listen
            // there too when the port can be shared (it can't always be).
            $mc = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($mc !== false) {
                @socket_set_option($mc, SOL_SOCKET, SO_REUSEADDR, 1);
                if (defined('SO_REUSEPORT')) {
                    @socket_set_option($mc, SOL_SOCKET, SO_REUSEPORT, 1);
                }
                if (@socket_bind($mc, '0.0.0.0', 5353) && @socket_set_option($mc, IPPROTO_IP, MCAST_JOIN_GROUP, ['group' => '224.0.0.251', 'interface' => 0])) {
                    socket_set_nonblock($mc);
                    $socks[] = $mc;
                } else {
                    socket_close($mc);
                }
            }
        }

        $send = static function () use ($s, $query, $ip, $port, $multicast): void {
            $sent = false;
            if ($multicast && function_exists('net_get_interfaces')) {
                foreach (net_get_interfaces() ?: [] as $ifName => $if) {
                    if (empty($if['up']) || !self::hasIpv4($if)) {
                        continue;
                    }
                    if (@socket_set_option($s, IPPROTO_IP, IP_MULTICAST_IF, $ifName) && @socket_sendto($s, $query, strlen($query), 0, $ip, $port) !== false) {
                        $sent = true;
                    }
                }
            }
            if (!$sent) {
                @socket_sendto($s, $query, strlen($query), 0, $ip, $port);
            }
        };

        $col = new self();
        $start = microtime(true);
        $deadline = $start + $timeout;
        $resendAt = $start + min(0.3, $timeout / 3);
        $resent = false;
        $send();
        try {
            while (($now = microtime(true)) < $deadline) {
                if (!$resent && $now >= $resendAt) {
                    $send(); // UDP may drop packets: one resend
                    $resent = true;
                }
                $wait = min($deadline, $resent ? $deadline : $resendAt) - $now;
                $read = $socks;
                $w = $e = null;
                $n = @socket_select($read, $w, $e, (int) $wait, (int) (fmod($wait, 1.0) * 1_000_000));
                if ($n === false) {
                    break;
                }
                foreach ($read ?? [] as $r) {
                    while (true) {
                        $buf = '';
                        $from = '';
                        $fromPort = 0;
                        $len = @socket_recvfrom($r, $buf, 9000, 0, $from, $fromPort);
                        if ($len === false || $len <= 0) {
                            break;
                        }
                        $col->add($buf, (string) $from);
                    }
                }
            }
        } finally {
            foreach ($socks as $sock) {
                socket_close($sock);
            }
        }

        return $col->stations();
    }

    /** @param array<string, mixed> $if */
    private static function hasIpv4(array $if): bool
    {
        foreach (is_array($if['unicast'] ?? null) ? $if['unicast'] : [] as $u) {
            if (is_array($u) && ($u['family'] ?? null) === AF_INET && ($u['address'] ?? '') !== '127.0.0.1') {
                return true;
            }
        }

        return false;
    }

    /** A PTR query for the CatzPrint service with the unicast-response (QU) bit set. */
    public static function buildQuery(): string
    {
        return pack('n6', 0, 0, 1, 0, 0, 0) . self::encodeName(self::SERVICE) . pack('n2', self::TYPE_PTR, 0x8001);
    }

    public static function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $out .= chr(strlen($label)) . $label;
        }

        return $out . "\0";
    }

    /** Parses one mDNS response packet. Malformed packets and unrelated records are ignored. */
    public function add(string $msg, string $fromIp): void
    {
        try {
            $this->parse($msg, filter_var($fromIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $fromIp : '');
        } catch (\UnexpectedValueException) {
            // malformed: keep what was collected before
        }
    }

    private function parse(string $msg, string $src): void
    {
        if (strlen($msg) < 12) {
            return;
        }
        $h = unpack('nid/nflags/nqd/nan/nns/nar', $msg);
        if ($h === false || ($h['flags'] & 0x8000) === 0) {
            return; // not a response
        }
        $off = 12;
        for ($i = 0; $i < $h['qd']; $i++) {
            self::readName($msg, $off);
            $off += 4;
        }
        $records = $h['an'] + $h['ns'] + $h['ar'];
        for ($i = 0; $i < $records; $i++) {
            $orig = self::readName($msg, $off);
            $name = strtolower($orig);
            if ($off + 10 > strlen($msg)) {
                throw new \UnexpectedValueException('truncated');
            }
            $rh = unpack('ntype/nclass/Nttl/nlen', $msg, $off);
            if ($rh === false) {
                throw new \UnexpectedValueException('truncated');
            }
            $off += 10;
            $end = $off + $rh['len'];
            if ($end > strlen($msg)) {
                throw new \UnexpectedValueException('truncated');
            }
            $rdOff = $off;
            switch ($rh['type']) {
                case self::TYPE_PTR:
                    $target = self::readName($msg, $rdOff);
                    $lt = strtolower($target);
                    if ($name === self::SERVICE && self::isInstance($lt) && $this->room($this->ptr, $lt)) {
                        $this->ptr[$lt] = true;
                        $this->name[$lt] = $target;
                    }
                    break;
                case self::TYPE_SRV:
                    if ($rh['len'] < 7) {
                        throw new \UnexpectedValueException('bad srv');
                    }
                    $srv = unpack('npri/nweight/nport', $msg, $rdOff);
                    $rdOff += 6;
                    $target = self::readName($msg, $rdOff);
                    if (self::isInstance($name) && $srv !== false && $this->room($this->srv, $name)) {
                        $this->srv[$name] = ['port' => (int) $srv['port'], 'target' => strtolower($target)];
                        $this->name[$name] = $orig;
                        if ($src !== '') {
                            $this->src[$name] = $src;
                        }
                    }
                    break;
                case self::TYPE_TXT:
                    if (self::isInstance($name) && $this->room($this->txt, $name)) {
                        $kv = [];
                        $p = $off;
                        while ($p < $end) {
                            $l = ord($msg[$p]);
                            $p++;
                            if ($p + $l > $end) {
                                throw new \UnexpectedValueException('bad txt');
                            }
                            $s = substr($msg, $p, $l);
                            $p += $l;
                            [$k, $v] = array_pad(explode('=', $s, 2), 2, '');
                            $k = strtolower(trim($k));
                            if ($k !== '' && !array_key_exists($k, $kv)) {
                                $kv[$k] = $v; // RFC 6763: the first one wins
                            }
                        }
                        $this->txt[$name] = $kv;
                        $this->name[$name] = $orig;
                        if ($src !== '') {
                            $this->src[$name] = $src;
                        }
                    }
                    break;
                case self::TYPE_A:
                    if ($rh['len'] === 4 && $this->room($this->addr, $name) && count($this->addr[$name] ?? []) < self::MAX_ADDRESSES) {
                        $ip = (string) inet_ntop(substr($msg, $off, 4));
                        if (!in_array($ip, $this->addr[$name] ?? [], true)) {
                            $this->addr[$name][] = $ip;
                        }
                    }
                    break;
            }
            $off = $end;
        }
    }

    /** @param array<string, mixed> $map */
    private function room(array $map, string $key): bool
    {
        return isset($map[$key]) || count($map) < self::MAX_NAMES;
    }

    private static function isInstance(string $n): bool
    {
        return str_ends_with($n, '.' . self::SERVICE) && strlen($n) > strlen(self::SERVICE) + 1;
    }

    /** Reads a (possibly compressed) DNS name at $off and advances $off past it. */
    private static function readName(string $msg, int &$off): string
    {
        $labels = [];
        $pos = $off;
        $jumped = false;
        $jumps = 0;
        $len = strlen($msg);
        while (true) {
            if ($pos >= $len) {
                throw new \UnexpectedValueException('truncated name');
            }
            $l = ord($msg[$pos]);
            if (($l & 0xc0) === 0xc0) {
                if ($pos + 1 >= $len || ++$jumps > 16) {
                    throw new \UnexpectedValueException('bad pointer');
                }
                $ptr = (($l & 0x3f) << 8) | ord($msg[$pos + 1]);
                if (!$jumped) {
                    $off = $pos + 2;
                }
                $jumped = true;
                $pos = $ptr;
                continue;
            }
            if ($l > 63) {
                throw new \UnexpectedValueException('bad label');
            }
            $pos++;
            if ($l === 0) {
                break;
            }
            if ($pos + $l > $len) {
                throw new \UnexpectedValueException('truncated label');
            }
            $labels[] = substr($msg, $pos, $l);
            $pos += $l;
            if (count($labels) > 64) {
                throw new \UnexpectedValueException('name too long');
            }
        }
        if (!$jumped) {
            $off = $pos;
        }

        return implode('.', $labels) . '.';
    }

    /**
     * Every complete, valid station seen so far, sorted by instance name.
     *
     * @return list<MdnsStation>
     */
    public function stations(): array
    {
        $names = array_keys(array_intersect_key($this->txt, $this->srv));
        sort($names);
        $out = [];
        foreach ($names as $n) {
            $kv = $this->txt[$n];
            $srv = $this->srv[$n];
            if (($kv['v'] ?? '') !== '1' || ($kv['id'] ?? '') === '') {
                continue;
            }
            $port = $srv['port'];
            if (isset($kv['port']) && ctype_digit($kv['port']) && (int) $kv['port'] > 0 && (int) $kv['port'] < 65536) {
                $port = (int) $kv['port'];
            }
            if ($port <= 0) {
                continue;
            }
            $fp = Net::normalizeFingerprint($kv['fp'] ?? '');
            if (($kv['fp'] ?? '') !== '' && $fp === '') {
                continue; // malformed fingerprint: ignore the whole announcement
            }
            $addrs = $this->addr[$srv['target']] ?? [];
            if ($addrs === [] && isset($this->src[$n])) {
                $addrs = [$this->src[$n]];
            }
            $instance = $this->name[$n] ?? $n;
            $out[] = new MdnsStation(
                instance: substr($instance, 0, -strlen('.' . self::SERVICE)),
                stationId: $kv['id'],
                orgId: $kv['org'] ?? '',
                locationId: $kv['loc'] ?? '',
                port: $port,
                fingerprint: $fp,
                tlsMode: $kv['tls'] ?? '',
                addresses: array_values(array_unique($addrs)),
            );
        }

        return $out;
    }
}
