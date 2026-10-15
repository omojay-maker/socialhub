<?php
/**
 * Query-free OAuth callback for TikTok.
 *
 * TikTok's portal refuses redirect URIs containing a query string, so this
 * static path stands in for oauth.php?action=callback&platform=tiktok.
 * TikTok appends its own ?code=...&state=... which PHP merges into $_GET.
 */
$_GET['action'] = 'callback';
$_GET['platform'] = 'tiktok';

require_once __DIR__ . '/../../oauth.php';
