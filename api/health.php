<?php
require_once __DIR__ . '/lib.php';
$hi_live = false;
$msg = 'PHP backend ready. HiAnime live data preferred, AniList metadata fallback.';
global $HIANIME_DOMAINS;
foreach ($HIANIME_DOMAINS as $d) {
    try {
        $r = ath_hianime_list($d, 1);
        if (!empty($r['results'])) { $hi_live = true; $msg = 'Using live data from ' . $d['host']; break; }
    } catch (Throwable $e) {}
}
ath_json_out([
    'status' => 'ok',
    'hianimeLive' => $hi_live,
    'anilistFallback' => true,
    'backend' => 'php+cURL (InfinityFree-compatible)',
    'note' => $msg,
]);
