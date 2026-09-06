<?php
if (!defined('ATHANIME_PHP_LOADED')) {
define('ATHANIME_PHP_LOADED', true);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$HIANIME_DOMAINS = [
    ['host' => 'hianime.eu', 'prefix' => ''],
    ['host' => 'hianimes.cz', 'prefix' => ''],
    ['host' => 'hianime.to', 'prefix' => ''],
    ['host' => 'hianime.sx', 'prefix' => ''],
];

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function ath_json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Cache-Control: s-maxage=60, max-age=30, stale-while-revalidate=120');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    exit;
}

function ath_https_get($url, $timeout = 20, $extraHeaders = []) {
    global $UA;
    $headers = array_merge([
        'User-Agent: ' . $UA,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
    ], $extraHeaders);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_VERBOSE => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 400) {
        throw new Exception(trim('HTTP ' . intval($code) . ' ' . $err));
    }
    return $body;
}

function ath_graphql($query, $variables = []) {
    global $UA;
    $body = json_encode(['query' => $query, 'variables' => $variables]);
    $ch = curl_init('https://graphql.anilist.co/v2');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . $UA,
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 400) {
        throw new Exception('AniList HTTP ' . intval($code) . ' ' . $err);
    }
    $obj = json_decode($raw, true);
    if (!is_array($obj)) throw new Exception('AniList invalid JSON');
    if (!empty($obj['errors'])) {
        $msgs = array_map(function($e) { return $e['message'] ?? 'err'; }, $obj['errors']);
        throw new Exception('AniList: ' . implode('; ', $msgs));
    }
    return $obj['data'];
}

function ath_format_default_eps($format) {
    $map = [
        'TV' => 12, 'MOVIE' => 1, 'OVA' => 3, 'ONA' => 10,
        'SPECIAL' => 2, 'TV_SHORT' => 12, 'MUSIC' => 1,
    ];
    return $map[$format] ?? 12;
}

function ath_type_map($format) {
    $map = [
        'TV' => 'TV', 'MOVIE' => 'MOVIE', 'OVA' => 'OVA', 'ONA' => 'ONA',
        'SPECIAL' => 'SPECIAL', 'TV_SHORT' => 'TV', 'MUSIC' => 'ONA',
    ];
    return $map[$format] ?? 'TV';
}

function ath_dub_ratio($media) {
    $t = $media['title'] ?? [];
    $is_licensed = !empty($media['isLicensed']);
    $has_en = !empty($t['english']);
    $pop = intval($media['popularity'] ?? 0);
    $avg = intval($media['averageScore'] ?? ($media['meanScore'] ?? 50));
    $sy = intval($media['seasonYear'] ?? 2020);
    $status = $media['status'] ?? '';
    if ($is_licensed && $has_en) $r = 0.95;
    elseif ($is_licensed) $r = 0.8;
    elseif ($has_en && $pop > 20000) $r = 0.7;
    elseif ($pop > 50000) $r = 0.6;
    elseif ($pop > 30000) $r = 0.4;
    elseif ($pop > 15000) $r = 0.2;
    elseif ($avg >= 75 && $sy >= 2015) $r = 0.1;
    else $r = 0.05;
    if (($status === 'RELEASING' || $status === 'NOT_YET_RELEASED') && $r > 0.5) $r = 0.5;
    return $r;
}

function ath_anilist_list($page = 1, $per_page = 24) {
    $q = <<<'GQL'
query($page: Int, $perPage: Int) {
  Page(page: $page, perPage: $perPage) {
    pageInfo { total currentPage lastPage hasNextPage perPage }
    media(type: ANIME, sort: POPULARITY_DESC, isAdult: false, countryOfOrigin: JP) {
      id idMal
      title { userPreferred romaji english native }
      format episodes status
      isLicensed popularity averageScore meanScore seasonYear
      description(asHtml: false)
      coverImage { large extraLarge medium color }
    }
  }
}
GQL;
    $data = ath_graphql($q, ['page' => $page, 'perPage' => $per_page]);
    $pi = $data['Page']['pageInfo'] ?? [];
    $total_page = intval($pi['lastPage'] ?? max(1, intval($pi['total'] ?? 2000) / $per_page));
    $out = [];
    foreach (($data['Page']['media'] ?? []) as $m) {
        $t = $m['title'] ?? [];
        $title = $t['english'] ?? ($t['userPreferred'] ?? ($t['romaji'] ?? ($t['native'] ?? '')));
        if (!$title || strlen($title) < 2) continue;
        $cov = $m['coverImage'] ?? [];
        $img = $cov['extraLarge'] ?? ($cov['large'] ?? ($cov['medium'] ?? ''));
        if (!$img) continue;
        $format = $m['format'] ?? 'TV';
        $type = ath_type_map($format);
        $raw_eps = intval($m['episodes'] ?? 0);
        $eps = $raw_eps > 0 ? $raw_eps : ath_format_default_eps($format);
        $ratio = ath_dub_ratio($m);
        $dub_c = (int)round($eps * $ratio);
        if ($ratio > 0.01 && $dub_c === 0) $dub_c = 1;
        if ($dub_c > $eps) $dub_c = $eps;
        if ($type === 'MOVIE' && $dub_c > 1) $dub_c = 1;
        $sub_c = $eps;
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $slug = trim($slug, '-');
        if (!$slug) $slug = 'anime-' . $m['id'];
        $slug = substr($slug, 0, 80);
        $syn = !empty($m['description']) ? trim(preg_replace('/\s+/', ' ', $m['description'])) : '';
        $out[] = [
            'id' => '/' . $slug . '-' . $m['id'],
            'dataId' => strval($m['id']),
            'image' => $img,
            'title' => $title,
            'type' => $type,
            'synopsis' => $syn,
            'language' => ['sub' => strval($sub_c), 'dub' => strval($dub_c)],
            '_epCount' => $eps,
        ];
    }
    return [
        'page' => intval($pi['currentPage'] ?? $page),
        'totalPage' => min(50, $total_page),
        'hasNextPage' => !empty($pi['hasNextPage']),
        'results' => $out,
    ];
}

function ath_parse_grid($html) {
    $results = [];
    if (preg_match_all('/<div[^>]*class="[^"]*flw-item[^"]*"[^>]*>([\s\S]*?)<\/div>\s*<\/div>\s*<\/div>/i', $html, $blocks, PREG_SET_ORDER)) {
    } else {
        preg_match_all('/<div[^>]*class="[^"]*flw-item[^"]*"[^>]*>([\s\S]*?)<\/div>\s*<\/div>/i', $html, $blocks, PREG_SET_ORDER);
    }
    if (empty($blocks) && preg_match_all('/<a[^>]+href="(\/watch\/[^"]+)"[^>]*>([\s\S]*?)<\/a>/i', $html, $am, PREG_SET_ORDER)) {
        $blocks = [];
        foreach ($am as $a) $blocks[] = [1 => $a[0]];
    }
    foreach ($blocks as $b) {
        $block = $b[1] ?? '';
        if (!$block) continue;
        if (!preg_match('/<a[^>]+href="([^"]*)"/i', $block, $hm)) continue;
        $href = trim($hm[1]);
        if (!preg_match('/<img[^>]+(?:data-src|src)="([^"]*)"[^>]*title="([^"]*)"/i', $block, $im)) continue;
        $img = trim(str_replace('`', '', $im[1]));
        $title = trim($im[2]);
        $type = 'TV';
        if (preg_match('/<div[^>]*class="[^"]*tick-type[^"]*"[^>]*>([^<]*)<\/div>/i', $block, $tm)) {
            $type = strtoupper(trim($tm[1])) ?: 'TV';
        }
        $did = '';
        if (preg_match('/-(\d+)$/', $href, $idm)) $did = $idm[1];
        $sub = '0'; $dub = '0';
        if (preg_match_all('/<div[^>]*class="[^"]*tick[^"]*"[^>]*>\s*(\d+)\s*<\/div>/i', $block, $tks)) {
            $nums = $tks[1] ?? [];
            if (count($nums) >= 2) { list($sub, $dub) = [$nums[0], $nums[1]]; }
            elseif (count($nums) === 1) { $dub = $nums[0]; $sub = strval(intval($dub) + 1); }
        }
        if (!$title || !$img) continue;
        $results[] = [
            'id' => $href,
            'dataId' => $did ?: strval(count($results) + 1),
            'image' => $img,
            'title' => $title,
            'type' => $type,
            'language' => ['sub' => strval($sub), 'dub' => strval($dub)],
        ];
    }
    return $results;
}

function ath_hianime_list($domain, $page) {
    $url = 'https://' . $domain['host'] . ($domain['prefix'] ?? '') . '/dubbed-anime?page=' . urlencode(strval($page));
    $html = ath_https_get($url, 18);
    $results = ath_parse_grid($html);
    if (count($results) < 5) throw new Exception('no items on ' . $domain['host']);
    if (preg_match('/href="\/dubbed-anime\?page=(\d+)"[^>]*>\s*Last\s*<\/a>/i', $html, $lm)) {
        $total_page = intval($lm[1]);
    } else {
        preg_match_all('/href="\/dubbed-anime\?page=(\d+)"/i', $html, $pms);
        $nums = array_map('intval', array_filter($pms[1] ?? [], 'ctype_digit'));
        $total_page = $nums ? max($nums) : 94;
    }
    $has_next = (bool)preg_match('/href="\/dubbed-anime\?page=(\d+)"[^>]*>\s*Next\s*<\/a>/i', $html);
    if (!$has_next && $page < $total_page) $has_next = true;
    return [
        'page' => $page,
        'totalPage' => min(200, $total_page),
        'hasNextPage' => $has_next,
        'results' => $results,
    ];
}

function ath_dubbed_list($page) {
    global $HIANIME_DOMAINS;
    $last_err = null;
    foreach ($HIANIME_DOMAINS as $d) {
        try {
            $r = ath_hianime_list($d, $page);
            return $r;
        } catch (Throwable $e) {
            $last_err = $e;
        }
    }
    try {
        $r = ath_anilist_list($page, 24);
        return $r;
    } catch (Throwable $e2) {
        throw new Exception(($last_err ? $last_err->getMessage() . '; ' : '') . $e2->getMessage());
    }
}
}
