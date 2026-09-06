<?php
require_once __DIR__ . '/lib.php';
try {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $data = ath_dubbed_list($page);
    ath_json_out($data);
} catch (Throwable $e) {
    ath_json_out([
        'error' => true,
        'message' => 'All anime data sources are currently unreachable. Please try again later. ' . $e->getMessage(),
        'page' => isset($page) ? $page : 1,
        'totalPage' => 1,
        'hasNextPage' => false,
        'results' => [],
    ], 502);
}
