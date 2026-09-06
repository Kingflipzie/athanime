<?php
require_once __DIR__ . '/lib.php';

function ath_hianime_info($domain, $path_or_id) {
    if (strpos($path_or_id, '/') === 0) {
        $slug = $path_or_id;
    } else {
        $slug = '/watch/' . ltrim($path_or_id, '/');
    }
    if (!preg_match('#^/watch/#', $slug)) {
        $slug = '/watch/' . ltrim($slug, '/');
    }
    $url = 'https://' . $domain['host'] . ($domain['prefix'] ?? '') . $slug;
    $html = ath_https_get($url, 25);
    $info = [
        'title' => '', 'type' => 'TV', 'image' => '', 'synopsis' => '',
        'language' => ['sub' => '0', 'dub' => '0'],
    ];
    if (preg_match('/<h1[^>]*class="[^"]*title[^"]*"[^>]*>([\s\S]*?)<\/h1>/i', $html, $tm)) {
        $info['title'] = trim(strip_tags($tm[1]));
    }
    if (!$info['title'] && preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]*)"/i', $html, $og)) {
        $info['title'] = trim(html_entity_decode($og[1], ENT_QUOTES, 'UTF-8'));
    }
    if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]*)"/i', $html, $og)) {
        $info['image'] = trim($og[1]);
    }
    if (!$info['image'] && preg_match('/<img[^>]+(?:data-src|src)="([^"]*)"[^>]+alt="([^"]*)"/i', $html, $im)) {
        $info['image'] = trim(str_replace('`', '', $im[1]));
    }
    if (preg_match('/<div[^>]*class="[^"]*item-title[^"]*"[^>]*>Type<\/div>\s*<a[^>]*>([^<]*)</i', $html, $typm)) {
        $info['type'] = strtoupper(trim($typm[1])) ?: 'TV';
    }
    if (preg_match('/<div[^>]*class="[^"]*description[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $sm)) {
        $info['synopsis'] = trim(preg_replace('/\s+/', ' ', strip_tags($sm[1])));
    }
    if (!$info['synopsis'] && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"/i', $html, $md)) {
        $info['synopsis'] = trim(html_entity_decode($md[1], ENT_QUOTES, 'UTF-8'));
    }
    if (preg_match('/<span[^>]*class="[^"]*tick-dub[^"]*"[^>]*>\s*(\d+)/i', $html, $dm)) {
        $info['language']['dub'] = strval(intval($dm[1]));
    }
    if (preg_match('/<span[^>]*class="[^"]*tick-sub[^"]*"[^>]*>\s*(\d+)/i', $html, $sm2)) {
        $info['language']['sub'] = strval(intval($sm2[1]));
    }
    $episodes = [];
    if (preg_match('/<ul[^>]*id="episode_page"[^>]*>([\s\S]*?)<\/ul>/i', $html, $eps_pages_html)) {
        if (preg_match_all('/<a[^>]+href="[^"]*ep=(\d+)[^"]*"[^>]*>/i', $eps_pages_html[1], $epm)) {
            $maxEp = $epm[1] ? max(array_map('intval', $epm[1])) : 0;
            $rangeMatch = [];
            if (preg_match('/class="(?:"|\')[^"\']*ep-item[^"\']*(?:"|\')[^>]*>[\s\S]*?<a[^>]+href="(?P<url>[^#"][^"]*#(?P<epstart>\d+)(?:\D|\b)[^"]*)"[^>]*>[\s\S]*?<span[^>]*>(?P<title>[^<]*)</i', $html, $rangeMatch)) {
            }
            $ep_start = 1;
            $ep_end = $maxEp > 0 ? $maxEp : 12;
            if (preg_match('/class="btn-episodes"[^>]*data-id="(\d+)"[^>]*data-ep_start="(\d+)"[^>]*data-ep_end="(\d+)"/i', $html, $rem)) {
                $ep_start = intval($rem[2]);
                $ep_end = intval($rem[3]);
            }
            for ($i = $ep_start; $i <= $ep_end; $i++) {
                $episodes[] = [
                    'number' => strval($i),
                    'title' => 'Episode ' . $i,
                    'id' => '',
                ];
            }
        }
    }
    if (empty($episodes)) {
        if (preg_match_all('/<a[^>]+(?:data-id|data-episode)[^=]*="([^"]*)"[^>]*>([\s\S]*?)<\/a>/i', $html, $eps)) {
            $cnt = count($eps[1]);
            for ($i = 0; $i < $cnt; $i++) {
                $id = trim($eps[1][$i]);
                $text = trim(strip_tags($eps[2][$i]));
                preg_match('/(\d+)/', $text, $nm);
                $n = $nm ? intval($nm[1]) : ($i + 1);
                $episodes[] = [
                    'number' => strval($n),
                    'title' => $text ?: ('Episode ' . $n),
                    'id' => $id,
                ];
            }
        }
    }
    if (empty($episodes)) {
        $tot = max(1, intval($info['language']['sub'] ?: $info['language']['dub']));
        for ($i = 1; $i <= $tot; $i++) {
            $episodes[] = ['number' => strval($i), 'title' => 'Episode ' . $i, 'id' => ''];
        }
    }
    usort($episodes, function($a, $b) { return intval($a['number']) - intval($b['number']); });
    return [
        'anime' => $info,
        'episodes' => $episodes,
        '_html_debug_eps_count' => count($episodes),
    ];
}

function ath_anilist_info($id_or_title) {
    $q = <<<'GQL'
query($id: Int, $search: String) {
  Media(id: $id, search: $search, type: ANIME, isAdult: false) {
    id idMal
    title { userPreferred romaji english native }
    format episodes status
    isLicensed popularity averageScore meanScore seasonYear
    description(asHtml: false)
    coverImage { large extraLarge medium }
    streamingEpisodes { title episode thumbnail url }
  }
}
GQL;
    $vars = [];
    $id = intval($id_or_title);
    if ($id > 0) $vars['id'] = $id;
    else $vars['search'] = $id_or_title;
    $data = ath_graphql($q, $vars);
    $m = $data['Media'] ?? null;
    if (!$m) throw new Exception('anilist media not found');
    $t = $m['title'] ?? [];
    $title = $t['english'] ?? ($t['userPreferred'] ?? ($t['romaji'] ?? ($t['native'] ?? 'Untitled')));
    $cov = $m['coverImage'] ?? [];
    $img = $cov['extraLarge'] ?? ($cov['large'] ?? ($cov['medium'] ?? ''));
    $format = $m['format'] ?? 'TV';
    $type = ath_type_map($format);
    $raw_eps = intval($m['episodes'] ?? 0);
    $eps = $raw_eps > 0 ? $raw_eps : ath_format_default_eps($format);
    $ratio = ath_dub_ratio($m);
    $dub_c = (int)round($eps * $ratio);
    if ($ratio > 0.01 && $dub_c === 0) $dub_c = 1;
    if ($dub_c > $eps) $dub_c = $eps;
    if ($type === 'MOVIE' && $dub_c > 1) $dub_c = 1;
    $anime = [
        'title' => $title,
        'type' => $type,
        'image' => $img,
        'language' => ['sub' => strval($eps), 'dub' => strval($dub_c)],
        'synopsis' => !empty($m['description']) ? trim(preg_replace('/\s+/', ' ', $m['description'])) : '',
        'dataId' => strval($m['id']),
    ];
    $episodes = [];
    $streaming = $m['streamingEpisodes'] ?? [];
    if (is_array($streaming) && count($streaming) > 0) {
        foreach ($streaming as $s) {
            $n = intval($s['episode'] ?? 0);
            if ($n <= 0) continue;
            $episodes[] = [
                'number' => strval($n),
                'title' => $s['title'] ?: ('Episode ' . $n),
                'id' => '',
                'thumbnail' => $s['thumbnail'] ?? '',
                'streamUrl' => $s['url'] ?? '',
            ];
        }
    }
    if (count($episodes) < $eps) {
        $existing_nums = array_flip(array_map(function($e){ return intval($e['number']); }, $episodes));
        for ($i = 1; $i <= $eps; $i++) {
            if (!isset($existing_nums[$i])) {
                $episodes[] = ['number' => strval($i), 'title' => 'Episode ' . $i, 'id' => ''];
            }
        }
    }
    usort($episodes, function($a, $b) { return intval($a['number']) - intval($b['number']); });
    return ['anime' => $anime, 'episodes' => $episodes];
}

try {
    $id = $_GET['id'] ?? '';
    $path = $_GET['path'] ?? '';
    $title = $_GET['title'] ?? '';
    $info = null;
    $errors = [];
    if ($path && (strpos($path, '/watch/') === 0 || strpos($path, '/') === 0)) {
        global $HIANIME_DOMAINS;
        foreach ($HIANIME_DOMAINS as $d) {
            try {
                $info = ath_hianime_info($d, $path);
                break;
            } catch (Throwable $e) { $errors[] = $d['host'] . ': ' . $e->getMessage(); }
        }
    }
    if (!$info) {
        try {
            $lookup = $id ? $id : $title;
            if (!$lookup) throw new Exception('no id or title');
            $info = ath_anilist_info($lookup);
        } catch (Throwable $e) {
            $errors[] = 'AniList: ' . $e->getMessage();
            $info = [
                'anime' => [
                    'title' => $title ?: 'Anime',
                    'type' => 'TV',
                    'image' => '',
                    'language' => ['sub' => '12', 'dub' => '12'],
                    'synopsis' => 'Unable to load details. Try selecting an episode below.',
                    'dataId' => $id ?: '0',
                ],
                'episodes' => [],
            ];
        }
    }
    if (empty($info['anime']['title'])) $info['anime']['title'] = $title ?: 'Anime';
    $info['anime']['dataId'] = $info['anime']['dataId'] ?? ($id ?: '0');
    ath_json_out($info);
} catch (Throwable $e) {
    ath_json_out([
        'error' => true,
        'message' => $e->getMessage(),
        'anime' => null,
        'episodes' => [],
    ], 502);
}
