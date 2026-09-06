<?php
require_once __DIR__ . '/lib.php';

function ath_build_video_embed_url($anime_path, $ep_num, $lang, $domain_host) {
    $slug = ltrim($anime_path, '/');
    if (strpos($slug, 'watch/') !== 0) $slug = 'watch/' . $slug;
    $sep = (strpos($slug, '?') === false) ? '?' : '&';
    return 'https://' . $domain_host . '/' . $slug . $sep . 'ep=' . intval($ep_num) . '&lang=' . ($lang === 'sub' ? 'sub' : 'dub');
}

function ath_hianime_sources($domain, $path, $ep_num, $lang) {
    $page_url = ath_build_video_embed_url($path, $ep_num, $lang, $domain['host']);
    $html = ath_https_get($page_url, 25);
    $results = [];
    $player_url = '';
    if (preg_match_all('/<iframe[^>]+src="([^"]*)"/i', $html, $ifm)) {
        foreach ($ifm[1] as $u) {
            if (strpos($u, 'http') === 0) {
                $player_url = $u;
                break;
            } elseif (strpos($u, '//') === 0) {
                $player_url = 'https:' . $u;
                break;
            }
        }
    }
    if (!$player_url && preg_match_all('/src[=:]\s*"([^"]*player[^"]*\.php[^"]*|\/player\/[^"]*|\/embed[^"]*)"/i', $html, $pm)) {
        foreach ($pm[1] as $pu) {
            if (strpos($pu, 'http') === 0) $player_url = $pu;
            else $player_url = 'https://' . $domain['host'] . (strpos($pu, '/') === 0 ? '' : '/') . $pu;
            break;
        }
    }
    if ($player_url) {
        $results[] = ['name' => 'Iframe Player', 'url' => $player_url, 'isIframe' => true];
    }
    if (preg_match_all('/file[=:]\s*["\']([^"\']+\.(?:m3u8|mp4)[^"\']*)["\']/i', $html, $urls)) {
        foreach ($urls[1] as $u) {
            if (strpos($u, 'http') !== 0 && strpos($u, '//') !== 0) {
                $u = 'https://' . $domain['host'] . (strpos($u, '/') === 0 ? '' : '/') . $u;
            }
            $results[] = ['name' => 'Direct ' . strtoupper(pathinfo(parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION)), 'url' => $u];
        }
    }
    if (preg_match_all('/(?:sources|playlist)\s*[:=]\s*(\[[\s\S]{0,3000}?\])/i', $html, $blocks)) {
        foreach ($blocks[1] as $block) {
            $arr = json_decode($block, true);
            if (is_array($arr)) {
                foreach ($arr as $s) {
                    $url = is_string($s) ? $s : ($s['file'] ?? ($s['src'] ?? ''));
                    if (!$url) continue;
                    if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                        $url = 'https://' . $domain['host'] . (strpos($url, '/') === 0 ? '' : '/') . $url;
                    }
                    $label = is_array($s) ? ($s['label'] ?? ($s['name'] ?? 'Source')) : 'Source';
                    $results[] = ['name' => (string)$label, 'url' => $url];
                }
            }
        }
    }
    if (empty($results)) {
        $results[] = [
            'name' => 'Watch on ' . ucfirst($domain['host']),
            'url' => $page_url,
            'isIframe' => true,
        ];
    }
    $seen = [];
    $out = [];
    foreach ($results as $r) {
        if (empty($r['url'])) continue;
        $k = md5($r['url']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $r;
    }
    return $out;
}

function ath_anilist_direct_sources($id_or_title, $ep_num, $lang) {
    $q = <<<'GQL'
query($id: Int, $search: String) {
  Media(id: $id, search: $search, type: ANIME) {
    id
    streamingEpisodes { title episode thumbnail url site }
    externalLinks { url site type language }
  }
}
GQL;
    $vars = [];
    $id = intval($id_or_title);
    if ($id > 0) $vars['id'] = $id;
    else $vars['search'] = $id_or_title;
    $data = ath_graphql($q, $vars);
    $m = $data['Media'] ?? null;
    $out = [];
    if (!$m) return $out;
    $ep_num_int = intval($ep_num);
    $streaming = $m['streamingEpisodes'] ?? [];
    if (is_array($streaming)) {
        foreach ($streaming as $s) {
            $n = intval($s['episode'] ?? 0);
            if ($n !== $ep_num_int && $ep_num_int > 0) continue;
            $u = $s['url'] ?? '';
            if (!$u) continue;
            $site = $s['site'] ?? 'Stream';
            $out[] = ['name' => $site . ' (Ep ' . max(1, $n) . ')', 'url' => $u, 'isIframe' => true];
        }
    }
    $ext = $m['externalLinks'] ?? [];
    if (is_array($ext)) {
        $wantLang = $lang === 'dub' ? 'EN' : null;
        foreach ($ext as $e) {
            $t = strtolower($e['type'] ?? '');
            if ($t !== 'streaming' && $t !== '') continue;
            $url = $e['url'] ?? '';
            if (!$url) continue;
            $linkLang = strtoupper($e['language'] ?? '');
            if ($wantLang && $linkLang && $linkLang !== $wantLang) continue;
            $site = $e['site'] ?? parse_url($url, PHP_URL_HOST);
            $out[] = ['name' => $site . ($linkLang ? ' (' . $linkLang . ')' : ''), 'url' => $url, 'isIframe' => true];
        }
    }
    $seen = [];
    $final = [];
    foreach ($out as $r) {
        if (empty($r['url'])) continue;
        $k = md5($r['url']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $final[] = $r;
    }
    return $final;
}

function ath_make_fallback_sources($anime_id, $anime_title, $ep_num, $lang) {
    $safe_title = rawurlencode($anime_title ?: 'anime');
    $ep = intval($ep_num) ?: 1;
    $list = [];
    $list[] = [
        'name' => 'Gogoanime (search)',
        'url' => 'https://gogoanime3.co//search.html?keyword=' . $safe_title,
        'isIframe' => true,
    ];
    if (preg_match('/^\d+$/', strval($anime_id))) {
        $list[] = [
            'name' => 'AniList page',
            'url' => 'https://anilist.co/anime/' . intval($anime_id),
            'isIframe' => true,
        ];
    }
    $list[] = [
        'name' => 'MyAnimeList (search)',
        'url' => 'https://myanimelist.net/anime.php?q=' . $safe_title,
        'isIframe' => true,
    ];
    return $list;
}

try {
    $id = $_GET['id'] ?? '';
    $path = $_GET['path'] ?? '';
    $title = $_GET['title'] ?? '';
    $ep = $_GET['ep'] ?? '1';
    $epid = $_GET['epid'] ?? '';
    $lang = in_array(($_GET['lang'] ?? 'dub'), ['dub','sub'], true) ? ($_GET['lang'] ?? 'dub') : 'dub';

    $sources = [];
    $errors = [];
    if ($path && (strpos($path, '/') === 0 || strpos($path, 'watch/') === 0)) {
        global $HIANIME_DOMAINS;
        foreach ($HIANIME_DOMAINS as $d) {
            try {
                $r = ath_hianime_sources($d, $path, $ep, $lang);
                if (!empty($r)) { $sources = $r; break; }
            } catch (Throwable $e) { $errors[] = $d['host'] . ': ' . $e->getMessage(); }
        }
    }
    if (empty($sources)) {
        try {
            $lookup = $id ? $id : $title;
            if ($lookup) $sources = ath_anilist_direct_sources($lookup, $ep, $lang);
        } catch (Throwable $e) { $errors[] = 'AniList: ' . $e->getMessage(); }
    }
    if (empty($sources)) {
        $sources = ath_make_fallback_sources($id, $title, $ep, $lang);
    }
    $msg = '';
    if (!empty($errors)) $msg = 'Notes: ' . implode(' | ', $errors);
    ath_json_out([
        'sources' => $sources,
        'episode' => $ep,
        'lang' => $lang,
        'message' => $msg,
    ]);
} catch (Throwable $e) {
    ath_json_out([
        'error' => true,
        'message' => $e->getMessage(),
        'sources' => [],
        'lang' => $lang ?? 'dub',
        'episode' => $ep ?? '1',
    ], 502);
}
