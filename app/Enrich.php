<?php
declare(strict_types=1);

/**
 * Infers a customer's region (区域) and industry (行业) from order data, so
 * nobody has to type them in. Driven by tools/enrich_customers.php; nothing
 * here runs on a page load.
 *
 * Region: each order's address → kota → one of eight regions. Two sources:
 *   - the address text itself (explicit kota names, "Kec. X" for known
 *     kecamatan) — free, no network, always available;
 *   - Google Geocoding — also yields coordinates for the delivery map.
 * When the text names a kota explicitly and Google disagrees, the text wins
 * and the coordinates are dropped: a mis-geocoded point on the map is worse
 * than a missing one.
 *
 * Industry: rules first (company-name keywords, the order's client type,
 * "Proyek" addresses, personal buyers of small quantities); Claude only for
 * customers no rule decides. A salesperson's manual choice is final.
 *
 * Raw HTTP on purpose (no Composer/vendor directory); Claude calls go through
 * Claude::send(), which owns the token cap and retry policy.
 */
final class Enrich
{
    public const REGIONS = ['jkt_utara', 'jkt_barat', 'jkt_timur', 'jkt_selatan', 'jkt_pusat', 'tangerang', 'bekasi', 'bogor_depok', 'other', 'unknown'];
    public const INDUSTRIES = ['contractor', 'signage', 'aluminium_shop', 'renovation', 'end_owner', 'distributor', 'other'];

    private const GEO_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Test seam for geocoding: fn(string $url, ?string $body, array $headers): array{0:int,1:string}
     * returning [httpCode, responseBody]. null = real curl.
     * @var ?callable
     */
    public static $http = null;

    /**
     * Kecamatan → region, trusted only right after "Kec"/"Kecamatan": the same
     * words are also kelurahan or street names elsewhere (Tanah Sereal is a
     * kelurahan in Tambora; Bojong Menteng is in Bekasi). Names that exist as a
     * kecamatan in two regions (Setu, Cipayung, Tamansari) are left out.
     */
    private const KECAMATAN = [
        'jkt_pusat'   => ['gambir', 'sawah besar', 'kemayoran', 'senen', 'cempaka putih', 'menteng', 'tanah abang', 'johar baru'],
        'jkt_utara'   => ['penjaringan', 'pademangan', 'tanjung priok', 'koja', 'kelapa gading', 'cilincing'],
        'jkt_barat'   => ['cengkareng', 'grogol petamburan', 'grogol', 'taman sari', 'tambora', 'kebon jeruk', 'kalideres', 'palmerah', 'kembangan'],
        'jkt_selatan' => ['tebet', 'setiabudi', 'setia budi', 'mampang prapatan', 'mampang', 'pasar minggu', 'kebayoran lama', 'cilandak', 'kebayoran baru', 'pancoran', 'jagakarsa', 'pesanggrahan'],
        'jkt_timur'   => ['matraman', 'pulo gadung', 'pulogadung', 'jatinegara', 'duren sawit', 'kramat jati', 'makasar', 'pasar rebo', 'ciracas', 'cakung'],
        'bekasi'      => ['bantar gebang', 'bantargebang', 'bekasi barat', 'bekasi selatan', 'bekasi timur', 'bekasi utara', 'jatiasih', 'jatisampurna', 'medan satria', 'mustika jaya', 'mustikajaya', 'pondok gede', 'pondok melati', 'rawalumbu', 'rawa lumbu',
                          'babelan', 'bojongmangu', 'cabangbungin', 'cibarusah', 'cibitung', 'cikarang barat', 'cikarang pusat', 'cikarang selatan', 'cikarang timur', 'cikarang utara', 'karangbahagia', 'kedungwaringin', 'muara gembong', 'pebayuran', 'serang baru', 'sukakarya', 'sukatani', 'sukawangi', 'tambelang', 'tambun selatan', 'tambun utara', 'tambun', 'tarumajaya'],
        'tangerang'   => ['batuceper', 'benda', 'cibodas', 'ciledug', 'cipondoh', 'jatiuwung', 'karang tengah', 'karangtengah', 'karawaci', 'larangan', 'neglasari', 'periuk', 'pinang',
                          'ciputat', 'ciputat timur', 'pamulang', 'pondok aren', 'serpong', 'serpong utara',
                          'balaraja', 'cikupa', 'cisauk', 'cisoka', 'curug', 'kelapa dua', 'kosambi', 'legok', 'mauk', 'pagedangan', 'pakuhaji', 'panongan', 'pasar kemis', 'rajeg', 'sepatan', 'sindang jaya', 'solear', 'teluknaga', 'tigaraksa'],
        'bogor_depok' => ['beji', 'bojongsari', 'cilodong', 'cimanggis', 'cinere', 'limo', 'pancoran mas', 'sawangan', 'sukmajaya', 'tapos',
                          'bogor barat', 'bogor selatan', 'bogor tengah', 'bogor timur', 'bogor utara', 'tanah sareal',
                          'babakan madang', 'bojonggede', 'bojong gede', 'caringin', 'ciampea', 'ciawi', 'cibinong', 'cibungbulang', 'cigombong', 'cijeruk', 'cileungsi', 'ciomas', 'cisarua', 'ciseeng', 'citeureup', 'dramaga', 'gunung putri', 'gunung sindur', 'jonggol', 'kemang', 'klapanunggal', 'leuwiliang', 'megamendung', 'parung', 'parung panjang', 'rancabungur', 'sukaraja', 'tajurhalang'],
    ];

    /** Tokens that make a following kota name part of a street name ("Jl. Raya Bekasi" is in Jakarta Timur). */
    private const STREET_WORDS = ['jl', 'jln', 'jalan', 'raya', 'gg', 'gang', 'ji'];

    /** Values staff type into the delivery-address box that are not an address. */
    private const NOT_AN_ADDRESS = ['self pickup', 'ambil sendiri', 'ambil gudang', 'ambil di gudang', 'pickup', 'pick up', 'gudang', 'sama', 'same', 'idem'];

    // ───────────────────────────── address text ─────────────────────────────

    /** Lowercase, every non-alphanumeric run → one space. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        // Common abbreviations: "Gn Putri" = Gunung Putri, "Tg Priok" = Tanjung Priok.
        $s = preg_replace(['/\bgn\b/u', '/\btg\b/u'], ['gunung', 'tanjung'], $s) ?? $s;
        return trim($s);
    }

    public static function usable_address(?string $s): bool
    {
        $n = self::norm((string) $s);
        if (mb_strlen($n) < 5 || !preg_match('/\p{L}/u', $n)) {
            return false;
        }
        if (in_array($n, self::NOT_AN_ADDRESS, true)) {
            return false;
        }
        // Courier / pickup notes ("kirim ke expedisi sahabat", "cust datang ke
        // showroom"): Google happily geocodes them to a town of that name.
        return !preg_match('/\b(e[kx]s?pedisi|expedition|kirim ke|dikirim|via|kurir|courier|datang ke|showroom|showeroom|ambil)\b/u', $n);
    }

    /**
     * The address that best says where an order's goods went: the delivery
     * address, else the address on the order (self-pickup orders usually carry
     * the customer's own), else the customer record's address.
     */
    public static function order_address(array $order): string
    {
        return self::order_addresses($order)[0] ?? '';
    }

    /**
     * Every usable address on an order, best first. The delivery box often
     * holds a courier name or "cust datang ke showroom"; resolving falls
     * through to the next candidate when one names no place.
     * @return string[]
     */
    public static function order_addresses(array $order): array
    {
        $out = [];
        foreach (['delivery_address', 'address', 'cust_city'] as $f) {
            $a = trim((string) ($order[$f] ?? ''));
            if (self::usable_address($a) && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** resolve() over an order's candidate addresses; the first that names a place wins. */
    public static function resolve_order(PDO $pdo, array $config, array $order, bool $allowNetwork = true): array
    {
        $last = ['region' => 'unknown', 'lat' => null, 'lng' => null, 'source' => 'none', 'geo_error' => ''];
        foreach (self::order_addresses($order) as $addr) {
            $r = self::resolve($pdo, $config, $addr, $allowNetwork);
            if ($r['region'] !== 'unknown') {
                return $r;
            }
            $last = $r['geo_error'] !== '' ? $r : $last;
        }
        return $last;
    }

    /** "Kota Administrasi Jakarta Utara" / "Kabupaten Bekasi" → region code, or 'other'. */
    public static function kota_region(string $kota, array $cfg): string
    {
        $n = ' ' . self::norm($kota) . ' ';
        $n = preg_replace('/ (kota administrasi|kota adm|kota|kabupaten|kab|adm|administrasi|city|regency) /u', ' ', $n) ?? $n;
        $n = trim(preg_replace('/ (kota|city|regency)$/u', '', trim($n)) ?? $n);
        return (string) ($cfg['region_map'][$n] ?? 'other');
    }

    /**
     * Region from the address text alone.
     *
     * @return ?array{region:string,strength:string,why:string}
     *   strength: 'explicit' (Kota/Kabupaten X), 'named' (bare kota name or
     *   "Kec. X"), 'marker' (a place known to be outside the eight regions).
     */
    public static function text_region(string $addr, array $cfg): ?array
    {
        $n = ' ' . self::norm($addr) . ' ';
        if (trim($n) === '') {
            return null;
        }
        $map = (array) ($cfg['region_map'] ?? []);
        uksort($map, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // longest alias first

        $explicit = [];
        $named = [];
        foreach ($map as $alias => $region) {
            $alias = (string) $alias;
            foreach (self::positions($n, ' ' . $alias . ' ') as $pos) {
                // Only the two words right before matter: "Jl. Raya Bekasi" is a
                // street, "...raya C 265, Jakarta Pusat" is not.
                $before = self::tokens_before($n, $pos, 2);
                $prefix = end($before) ?: '';
                if (in_array($prefix, ['kota', 'kabupaten', 'kab', 'adm', 'administrasi'], true)) {
                    $explicit[] = [$pos, $region, $prefix . ' ' . $alias];
                } elseif (!array_intersect($before, self::STREET_WORDS)) {
                    $named[] = [$pos, $region, $alias];
                }
            }
        }
        foreach (self::KECAMATAN as $region => $names) {
            foreach ($names as $kec) {
                foreach (['kec', 'kecamatan'] as $k) {
                    foreach (self::positions($n, " {$k} {$kec} ") as $pos) {
                        $named[] = [$pos, $region, "kec {$kec}"];
                    }
                }
            }
        }
        // Indonesian addresses run street → kelurahan → kecamatan → kota, so
        // the rightmost mention is the most authoritative one.
        foreach (['explicit' => $explicit, 'named' => $named] as $strength => $hits) {
            if ($hits) {
                usort($hits, fn($a, $b) => $b[0] <=> $a[0]);
                return ['region' => $hits[0][1], 'strength' => $strength, 'why' => $hits[0][2]];
            }
        }
        foreach ((array) ($cfg['other_markers'] ?? []) as $m) {
            if (str_contains($n, ' ' . $m . ' ')) {
                return ['region' => 'other', 'strength' => 'marker', 'why' => $m];
            }
        }
        return null;
    }

    /** @return int[] */
    private static function positions(string $hay, string $needle): array
    {
        $out = [];
        $at = 0;
        while (($p = strpos($hay, $needle, $at)) !== false) {
            $out[] = $p;
            $at = $p + 1;
        }
        return $out;
    }

    /** @return string[] the last $k words before byte offset $pos */
    private static function tokens_before(string $n, int $pos, int $k): array
    {
        $words = preg_split('/ +/', trim(substr($n, 0, $pos))) ?: [];
        return array_slice(array_values(array_filter($words, fn($w) => $w !== '')), -$k);
    }

    // ───────────────────────────── geocoding ─────────────────────────────

    public static function geo_enabled(array $config): bool
    {
        return ($config['geo']['driver'] ?? '') === 'google' && trim((string) ($config['geo']['key'] ?? '')) !== '';
    }

    /**
     * Parse a Google Geocoding response.
     * @return array{status:string,lat:?float,lng:?float,kota:string,kecamatan:string,error:string}
     *   status: ok (kota known) | coarse (only province/country) | zero | error
     */
    public static function parse_google(string $json): array
    {
        $out = ['status' => 'error', 'lat' => null, 'lng' => null, 'kota' => '', 'kecamatan' => '', 'error' => ''];
        $d = json_decode($json, true);
        if (!is_array($d)) {
            $out['error'] = 'malformed response';
            return $out;
        }
        $st = (string) ($d['status'] ?? '');
        if ($st === 'ZERO_RESULTS') {
            $out['status'] = 'zero';
            return $out;
        }
        if ($st !== 'OK' || empty($d['results'][0])) {
            $out['error'] = trim($st . ' ' . (string) ($d['error_message'] ?? ''));
            return $out;
        }
        $r = $d['results'][0];
        foreach ((array) ($r['address_components'] ?? []) as $c) {
            $types = (array) ($c['types'] ?? []);
            if (in_array('administrative_area_level_2', $types, true)) {
                $out['kota'] = (string) ($c['long_name'] ?? '');
            } elseif (in_array('administrative_area_level_3', $types, true)) {
                $out['kecamatan'] = (string) ($c['long_name'] ?? '');
            }
        }
        $loc = $r['geometry']['location'] ?? null;
        if (isset($loc['lat'], $loc['lng'])) {
            $out['lat'] = (float) $loc['lat'];
            $out['lng'] = (float) $loc['lng'];
        }
        $out['status'] = $out['kota'] !== '' ? 'ok' : 'coarse';
        return $out;
    }

    /** One Google lookup, restricted to Indonesia. */
    public static function google_geocode(array $config, string $addr): array
    {
        $url = self::GEO_ENDPOINT . '?' . http_build_query([
            'address'    => $addr,
            'components' => 'country:ID',
            'region'     => 'id',
            'language'   => 'id',
            'key'        => (string) $config['geo']['key'],
        ]);
        [$code, $body, $err] = self::request($url, null, []);
        if ($err !== '') {
            return ['status' => 'error', 'lat' => null, 'lng' => null, 'kota' => '', 'kecamatan' => '', 'error' => $err];
        }
        if ($code < 200 || $code >= 300) {
            return ['status' => 'error', 'lat' => null, 'lng' => null, 'kota' => '', 'kecamatan' => '', 'error' => "HTTP {$code}"];
        }
        return self::parse_google($body);
    }

    /**
     * Resolve one address to a region (+ coordinates when geocoding is on).
     * Google results are cached per normalised address — including "no
     * result" — so each distinct address is paid for once, ever. Transport or
     * key errors are not cached, so they are retried on the next run.
     *
     * @return array{region:string,lat:?float,lng:?float,source:string,geo_error:string}
     */
    public static function resolve(PDO $pdo, array $config, string $addr, bool $allowNetwork = true): array
    {
        $cfg = (array) ($config['insights'] ?? []);
        $none = ['region' => 'unknown', 'lat' => null, 'lng' => null, 'source' => 'none', 'geo_error' => ''];
        $key = mb_substr(self::norm($addr), 0, 300);
        if ($key === '') {
            return $none;
        }
        $text = self::text_region($addr, $cfg);

        $geo = null;
        if (self::geo_enabled($config)) {
            $st = $pdo->prepare('SELECT * FROM geocode_cache WHERE addr_key = ?');
            $st->execute([$key]);
            $geo = $st->fetch() ?: null;
            if ($geo === null && $allowNetwork) {
                $geo = self::google_geocode($config, $addr);
                if ($geo['status'] !== 'error') {
                    $pdo->prepare('INSERT OR REPLACE INTO geocode_cache (addr_key, query, status, lat, lng, kota, kecamatan, error) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$key, $addr, $geo['status'], $geo['lat'], $geo['lng'], $geo['kota'], $geo['kecamatan'], $geo['error']]);
                }
            }
        }
        $geoErr = ($geo['status'] ?? '') === 'error' ? (string) $geo['error'] : '';
        $gRegion = ($geo['status'] ?? '') === 'ok' ? self::kota_region((string) $geo['kota'], $cfg) : null;
        $hasPoint = in_array($geo['status'] ?? '', ['ok'], true) && $geo['lat'] !== null;

        $textStrong = $text !== null && $text['strength'] !== 'marker';
        if ($textStrong && $gRegion !== null && $gRegion !== $text['region']) {
            // The address says one kota, Google placed it in another: trust the
            // words, and keep the dubious point off the map.
            return ['region' => $text['region'], 'lat' => null, 'lng' => null, 'source' => 'text', 'geo_error' => $geoErr];
        }
        if ($gRegion !== null) {
            return ['region' => $gRegion, 'lat' => $hasPoint ? (float) $geo['lat'] : null, 'lng' => $hasPoint ? (float) $geo['lng'] : null, 'source' => 'google', 'geo_error' => ''];
        }
        if ($text !== null) {
            return ['region' => $text['region'], 'lat' => null, 'lng' => null, 'source' => 'text', 'geo_error' => $geoErr];
        }
        return ['region' => 'unknown', 'lat' => null, 'lng' => null, 'source' => 'none', 'geo_error' => $geoErr];
    }

    /**
     * A customer's region: the most frequent known region among their three
     * most recent shipped orders; a tie goes to the more recent order.
     *
     * @param string[] $regionsNewestFirst delivery_region of shipped orders, newest first
     */
    public static function customer_region(array $regionsNewestFirst): ?string
    {
        if ($regionsNewestFirst === []) {
            return null;
        }
        $recent = array_slice($regionsNewestFirst, 0, 3);
        $known = array_values(array_filter($recent, fn($r) => $r !== null && $r !== '' && $r !== 'unknown'));
        if ($known === []) {
            return 'unknown';
        }
        $counts = array_count_values($known);
        $best = null;
        foreach ($known as $r) {               // newest first, so the first max wins ties
            if ($best === null || $counts[$r] > $counts[$best]) {
                $best = $r;
            }
        }
        return $best;
    }

    // ───────────────────────────── industry ─────────────────────────────

    /** Company/name keywords → industry, in precedence order. Matched at a word start. */
    private const INDUSTRY_WORDS = [
        'contractor'     => ['konstruksi', 'kontraktor', 'contractor', 'construction', 'bangun'],
        'signage'        => ['advertising', 'reklame', 'signage', 'sign', 'adv', 'advertindo', 'promosi', 'promotion', 'printing', 'percetakan', 'akrilik', 'acrylic', 'neon'],
        'aluminium_shop' => ['aluminium', 'alumunium', 'aluminum', 'almunium', 'alumnium', 'kaca', 'glass'],
        'renovation'     => ['interior', 'renovasi', 'renovation', 'dekorasi', 'decoration', 'decor'],
        'distributor'    => ['distributor', 'supplier'],
    ];

    /** Words that mark a business rather than a person. */
    private const BUSINESS_WORDS = ['pt', 'cv', 'ud', 'tb', 'pd', 'toko', 'koperasi', 'store', 'shop', 'group', 'jaya', 'mandiri', 'abadi', 'sejahtera', 'makmur', 'karya'];

    /**
     * Rules first. Returns [industry, note] when a rule decides, else null.
     *
     * @param array $c     customer row (name, company)
     * @param array $stats ['client_types' => [type => n], 'addresses' => string[], 'total_qty' => float, 'orders' => int]
     */
    public static function industry_rule(array $c, array $stats, array $cfg): ?array
    {
        $company = ' ' . self::norm((string) ($c['company'] ?? '')) . ' ';
        $name = ' ' . self::norm((string) ($c['name'] ?? '')) . ' ';
        foreach ([['公司名', $company], ['客户名', $name]] as [$label, $text]) {
            if (trim($text) === '') {
                continue;
            }
            foreach (self::INDUSTRY_WORDS as $industry => $words) {
                foreach ($words as $w) {
                    if (preg_match('/ ' . preg_quote($w, '/') . '/u', $text)) {
                        return [$industry, "{$label}含 \"{$w}\""];
                    }
                }
            }
        }

        $types = (array) ($stats['client_types'] ?? []);
        arsort($types);
        $top = (string) (array_key_first($types) ?? '');
        if ($top === 'Distributor') {
            return ['distributor', '订单客户类型 Distributor'];
        }
        if ($top === 'Contractor') {
            return ['contractor', '订单客户类型 Contractor'];
        }

        foreach ((array) ($stats['addresses'] ?? []) as $a) {
            if (preg_match('/ (proyek|project|site) /u', ' ' . self::norm((string) $a) . ' ', $m)) {
                return ['contractor', "送货地址含 \"{$m[1]}\""];
            }
        }

        $hasCompany = trim($company) !== '' && trim($company) !== trim($name);
        $isPerson = !preg_match('/ (' . implode('|', self::BUSINESS_WORDS) . ') /u', $company . $name);
        $maxQty = (float) ($cfg['end_owner_max_qty'] ?? 20);
        if (!$hasCompany && $isPerson && (int) ($stats['orders'] ?? 0) > 0 && (float) ($stats['total_qty'] ?? 0) <= $maxQty) {
            return ['end_owner', sprintf('个人名、无公司、累计 %s 张', rtrim(rtrim(number_format((float) $stats['total_qty'], 1, '.', ''), '0'), '.'))];
        }
        return null;
    }

    public static function ai_enabled(array $config): bool
    {
        return !empty($config['insights']['industry_ai']) && trim((string) ($config['ai']['key'] ?? '')) !== '';
    }

    public static function industry_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'         => ['type' => 'integer'],
                            'industry'   => ['type' => 'string', 'enum' => self::INDUSTRIES],
                            'confidence' => ['type' => 'number', 'description' => '0 to 1: how sure you are.'],
                            'reason'     => ['type' => 'string', 'description' => 'At most 20 Chinese characters.'],
                        ],
                        'required' => ['id', 'industry', 'confidence', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['results'],
            'additionalProperties' => false,
        ];
    }

    public static function industry_prompt(): string
    {
        return <<<TXT
            You classify customers of an aluminium composite panel (ACP) seller in
            Jakarta, Indonesia, by what kind of business they are. Each customer is
            one JSON object: name, company, delivery addresses, what they bought
            (ACP specs such as "4.0*0.21" or "3.0*0.06背涂"), how many sheets, how
            many orders, and the client type the sales assistant picked on the
            order form ("End User" is that form's default, so it says little).

            Industries:
            - contractor: building contractors / construction companies, project sites
            - signage: advertising, reklame, signboards, printing, neon/acrylic shops
            - aluminium_shop: aluminium & glass shops (toko aluminium / kaca) that fabricate windows, doors, partitions
            - renovation: interior design / renovation / decoration companies
            - end_owner: the building owner buying for their own house, shop or office
            - distributor: resells ACP sheets to other businesses
            - other: none of the above

            Indonesian business names carry strong hints ("Toko", "Kaca", "Reklame",
            "Interior", "PT ... Konstruksi"). A personal name with no company is
            usually end_owner unless the volume suggests a trade buyer. Repeated
            large orders of the same cheap spec suggest a trade buyer, not an owner.

            Return one result per input id, every id exactly once. Confidence is
            your honest probability that the industry is right; use below 0.7
            whenever the evidence is thin. "reason" is a short Chinese phrase.
            TXT;
    }

    /** The Messages API request body. Public so tests can assert its shape offline. */
    public static function ai_request(array $config, array $batch): array
    {
        $lines = [];
        foreach ($batch as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
        }
        return [
            'model'      => (string) ($config['insights']['industry_model'] ?? 'claude-opus-5-5'),
            'max_tokens' => 4096,     // ~20 short answers; Claude::send() clamps to ai.max_tokens anyway
            'system'     => self::industry_prompt(),
            'messages'   => [['role' => 'user', 'content' => implode("\n", $lines)]],
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => self::industry_schema()],
            ],
            // On a safety-classifier decline, retry server-side on the model
            // Anthropic recommends rather than failing the whole batch.
            'fallbacks' => 'default',
        ];
    }

    /**
     * Classify one batch. Token cap, size guard and retries: Claude::send().
     * @param array $batch list of ['id' => int, ...facts]
     * @return array{0:array<int,array{industry:string,confidence:float,reason:string}>,1:string,2:array}
     *   [results by customer id, error, usage]
     */
    public static function ai_classify(array $config, array $batch): array
    {
        [$json, $err, $usage] = Claude::send($config, self::ai_request($config, $batch), ['server-side-fallback-2026-07-01']);
        if ($json === null) {
            return [[], $err, $usage];
        }
        return [self::parse_ai($json, array_column($batch, 'id')), '', $usage];
    }

    /**
     * Results from a decoded response, keeping only ids that were asked about
     * and industries inside the enum.
     * @return array<int,array{industry:string,confidence:float,reason:string}>
     */
    public static function parse_ai(array $response, array $askedIds): array
    {
        $parsed = Claude::text_json($response);
        $asked = array_flip(array_map('intval', $askedIds));
        $out = [];
        foreach ((array) ($parsed['results'] ?? []) as $r) {
            $id = (int) ($r['id'] ?? 0);
            $ind = (string) ($r['industry'] ?? '');
            if (!isset($asked[$id]) || !in_array($ind, self::INDUSTRIES, true)) {
                continue;
            }
            $out[$id] = [
                'industry'   => $ind,
                'confidence' => max(0.0, min(1.0, (float) ($r['confidence'] ?? 0))),
                'reason'     => mb_substr((string) ($r['reason'] ?? ''), 0, 120),
            ];
        }
        return $out;
    }

    // ───────────────────────────── the batch run ─────────────────────────────

    /** Orders whose goods have left: the warehouse approved them (a DO exists). */
    public const SHIPPED = "status = 'approved'";

    /**
     * One enrichment pass. Incremental by default: orders not yet resolved
     * (or resolved from text only, once geocoding is configured), customers
     * with no industry yet. Rules are cheap and deterministic, so they are
     * re-applied to every non-manual customer each run.
     *
     * @param array $opt  all, dry, geo (bool), ai (bool), limit (int, 0 = none)
     * @param callable $log fn(string $line)
     */
    public static function run(PDO $pdo, array $config, array $opt, callable $log): array
    {
        $all = !empty($opt['all']);
        $dry = !empty($opt['dry']);
        $useGeo = ($opt['geo'] ?? true) && !$dry && self::geo_enabled($config);
        $useAi = ($opt['ai'] ?? true) && !$dry && self::ai_enabled($config);
        $limit = (int) ($opt['limit'] ?? 0);
        $cfg = (array) ($config['insights'] ?? []);
        $sum = ['orders' => 0, 'by_source' => [], 'geo_errors' => 0, 'customers_region' => 0,
                'rule' => 0, 'ai' => 0, 'ai_pending' => 0, 'ai_failed' => 0, 'ai_in' => 0, 'ai_out' => 0, 'manual_kept' => 0];
        $configForResolve = $config;
        if (!$useGeo) {
            $configForResolve['geo']['driver'] = '';
        }

        // 1) Orders → region (+ point).
        $where = "o.status <> 'draft'";
        if (!$all) {
            $where .= $useGeo ? " AND (o.delivery_region IS NULL OR COALESCE(o.geo_source, '') <> 'google')" : ' AND o.delivery_region IS NULL';
        }
        $sql = "SELECT o.id, o.delivery_address, o.address, o.delivery_region, o.geo_source, c.city AS cust_city
                  FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE {$where} ORDER BY o.id";
        $orders = $pdo->query($sql)->fetchAll();
        if ($limit > 0) {
            $orders = array_slice($orders, 0, $limit);
        }
        $upd = $pdo->prepare('UPDATE orders SET delivery_lat = ?, delivery_lng = ?, delivery_region = ?, geo_source = ? WHERE id = ?');
        foreach ($orders as $o) {
            $r = self::resolve_order($pdo, $configForResolve, $o, $useGeo);
            $sum['orders']++;
            $sum['by_source'][$r['source']] = ($sum['by_source'][$r['source']] ?? 0) + 1;
            if ($r['geo_error'] !== '') {
                $sum['geo_errors']++;
                if ($sum['geo_errors'] <= 3) {
                    $log("  geocode error (order {$o['id']}): {$r['geo_error']}");
                }
            }
            // A text-only pass must not erase a point an earlier geocoded pass found.
            if (!$useGeo && ($o['geo_source'] ?? '') === 'google') {
                continue;
            }
            if (!$dry) {
                $upd->execute([$r['lat'], $r['lng'], $r['region'], $r['source'], $o['id']]);
            }
        }

        // 2) Customers → region, from their shipped orders.
        $rows = $pdo->query('SELECT customer_id, delivery_region FROM orders WHERE customer_id IS NOT NULL AND ' . self::SHIPPED . ' ORDER BY customer_id, created_at DESC, id DESC')->fetchAll();
        $byCustomer = [];
        foreach ($rows as $r) {
            $byCustomer[(int) $r['customer_id']][] = $r['delivery_region'] ?? 'unknown';
        }
        $updC = $pdo->prepare('UPDATE customers SET region = ?, delivery_regions = ? WHERE id = ? AND (region IS NOT ? OR delivery_regions IS NOT ?)');
        foreach ($pdo->query('SELECT id FROM customers')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $regs = $byCustomer[(int) $cid] ?? [];
            $region = self::customer_region($regs);
            $counts = $regs ? array_count_values(array_map(fn($x) => $x ?? 'unknown', $regs)) : null;
            if ($counts) {
                arsort($counts);
            }
            $dist = $counts ? json_encode($counts) : null;
            if (!$dry) {
                $updC->execute([$region, $dist, $cid, $region, $dist]);
                $sum['customers_region'] += $updC->rowCount();
            }
        }

        // 3) Industry: rules for everyone not manually set, the model for the rest.
        $facts = self::customer_facts($pdo);
        $needAi = [];
        $setRule = $pdo->prepare("UPDATE customers SET industry = ?, industry_source = 'rule', industry_confidence = 1, industry_pending = 0, industry_note = ?, enriched_at = datetime('now','localtime') WHERE id = ? AND COALESCE(industry_source, '') <> 'manual'");
        foreach ($pdo->query('SELECT id, name, company, industry, industry_source FROM customers ORDER BY id')->fetchAll() as $c) {
            if (($c['industry_source'] ?? '') === 'manual') {
                $sum['manual_kept']++;
                continue;
            }
            $f = $facts[(int) $c['id']] ?? ['client_types' => [], 'addresses' => [], 'total_qty' => 0.0, 'orders' => 0, 'specs' => []];
            $hit = self::industry_rule($c, $f, $cfg);
            if ($hit !== null) {
                $sum['rule']++;
                if (!$dry) {
                    $setRule->execute([$hit[0], $hit[1], $c['id']]);
                }
                continue;
            }
            // A rule that matched last time but no longer does (name edited)
            // leaves a stale answer: ask the model again.
            if ($c['industry'] === null || $c['industry_source'] === 'rule' || ($all && $c['industry_source'] === 'ai')) {
                $needAi[] = [
                    'id' => (int) $c['id'], 'name' => (string) $c['name'], 'company' => (string) $c['company'],
                    'addresses' => array_slice($f['addresses'], 0, 2), 'bought' => array_slice($f['specs'], 0, 4, true),
                    'sheets' => $f['total_qty'], 'orders' => $f['orders'], 'client_type' => (string) (array_key_first($f['client_types']) ?? ''),
                ];
            }
        }
        if ($needAi && !$useAi) {
            $log('  ' . count($needAi) . ' customers need the model but AI is off (no ai.key, --no-ai or --dry-run); left as they are.');
        }
        if ($useAi && $needAi) {
            $min = (float) ($cfg['min_confidence'] ?? 0.7);
            $setAi = $pdo->prepare("UPDATE customers SET industry = ?, industry_source = 'ai', industry_confidence = ?, industry_pending = ?, industry_note = ?, enriched_at = datetime('now','localtime') WHERE id = ? AND COALESCE(industry_source, '') <> 'manual'");
            $logAi = $pdo->prepare("INSERT INTO ai_queries (user_id, user_name, question, matched, ok, error, in_tokens, out_tokens) VALUES (NULL, 'enrich', ?, ?, ?, ?, ?, ?)");
            $failStreak = 0;
            $batches = array_chunk($needAi, max(1, (int) ($cfg['ai_batch'] ?? 20)));
            foreach ($batches as $i => $batch) {
                // Guards against a run that keeps spending without progress:
                // the shared daily budget, and three failed batches in a row.
                if (Claude::budget_left($pdo, $config) <= 0) {
                    $sum['ai_failed'] += array_sum(array_map('count', array_slice($batches, $i)));
                    $log('  AI stopped: daily token budget (ai.daily_token_budget) used up; the rest waits for the next run.');
                    break;
                }
                if ($failStreak >= 3) {
                    $sum['ai_failed'] += array_sum(array_map('count', array_slice($batches, $i)));
                    $log('  AI stopped after 3 failed batches in a row; the rest waits for the next run.');
                    break;
                }
                [$res, $err, $usage] = self::ai_classify($config, $batch);
                $failStreak = $err === '' ? 0 : $failStreak + 1;
                $sum['ai_in'] += (int) ($usage['in'] ?? 0);
                $sum['ai_out'] += (int) ($usage['out'] ?? 0);
                $logAi->execute(['industry batch of ' . count($batch), implode(',', array_keys($res)), $err === '' ? 1 : 0, $err ?: null, (int) ($usage['in'] ?? 0), (int) ($usage['out'] ?? 0)]);
                if ($err !== '') {
                    $sum['ai_failed'] += count($batch);
                    $log("  AI batch " . ($i + 1) . " failed: {$err}");
                    continue;
                }
                foreach ($batch as $b) {
                    if (!isset($res[$b['id']])) {
                        $sum['ai_failed']++;
                        continue;
                    }
                    $r = $res[$b['id']];
                    $pending = $r['confidence'] < $min ? 1 : 0;
                    $setAi->execute([$r['industry'], $r['confidence'], $pending, $r['reason'], $b['id']]);
                    $sum['ai']++;
                    $sum['ai_pending'] += $pending;
                }
            }
        }
        return $sum;
    }

    /**
     * Per-customer facts the rules and the model read: client types picked on
     * the order form, distinct addresses, sheets and specs bought.
     * @return array<int,array>
     */
    public static function customer_facts(PDO $pdo): array
    {
        $facts = [];
        $base = fn() => ['client_types' => [], 'addresses' => [], 'total_qty' => 0.0, 'orders' => 0, 'specs' => []];
        foreach ($pdo->query("SELECT id, customer_id, client_type, delivery_address, address FROM orders WHERE customer_id IS NOT NULL AND status <> 'draft' ORDER BY id DESC") as $o) {
            $cid = (int) $o['customer_id'];
            $facts[$cid] ??= $base();
            $facts[$cid]['orders']++;
            $t = trim((string) $o['client_type']);
            if ($t !== '') {
                $facts[$cid]['client_types'][$t] = ($facts[$cid]['client_types'][$t] ?? 0) + 1;
            }
            $a = self::order_address($o);
            if ($a !== '' && !in_array($a, $facts[$cid]['addresses'], true)) {
                $facts[$cid]['addresses'][] = $a;
            }
        }
        $items = $pdo->query("SELECT o.customer_id, oi.spec, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
                               WHERE o.customer_id IS NOT NULL AND o.status <> 'draft' GROUP BY o.customer_id, oi.spec");
        foreach ($items as $it) {
            $cid = (int) $it['customer_id'];
            $facts[$cid] ??= $base();
            $facts[$cid]['total_qty'] += (float) $it['q'];
            $spec = trim((string) $it['spec']) ?: '?';
            $facts[$cid]['specs'][$spec] = (float) $it['q'];
        }
        foreach ($facts as &$f) {
            arsort($f['client_types']);
            arsort($f['specs']);
        }
        unset($f);
        return $facts;
    }

    // ───────────────────────────── transport ─────────────────────────────

    /** @return array{0:int,1:string,2:string} [httpCode, body, transportError] */
    private static function request(string $url, ?string $body, array $headers): array
    {
        if (self::$http !== null) {
            [$code, $res] = (self::$http)($url, $body, $headers);
            return [(int) $code, (string) $res, ''];
        }
        if (!function_exists('curl_init')) {
            return [0, '', 'php curl extension is not available'];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return [0, '', 'curl: ' . $err];
        }
        return [$code, (string) $res, ''];
    }
}
