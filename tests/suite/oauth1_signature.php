<?php
/**
 * OAuth 1.0a request signing (X/Twitter write endpoints).
 *
 * The signature is validated against the reference vector published in the
 * Twitter developer docs. If this ever drifts, every tweet and media upload
 * fails with 401 in production and is close to impossible to debug live, so it
 * is pinned here with a fixed nonce and timestamp.
 */

$runner->group('OAuth 1.0a signing (X/Twitter)');

// Published reference vector.
$refUrl    = 'https://api.twitter.com/1.1/statuses/update.json';
$refParams = [
    'status'           => 'Hello Ladies + Gentlemen, a signed OAuth request!',
    'include_entities' => 'true',
];
$refConsumerKey    = 'xvz1evFS4wEEPTGEFPHBog';
$refConsumerSecret = 'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw';
$refToken          = '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb';
$refTokenSecret    = 'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE';
$refNonce          = 'kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg';
$refTimestamp      = 1318622958;

// The base string published in the Twitter developer docs. This is the
// authoritative part of the vector: it is human-checkable, and it is what the
// signature is derived from, so a regression in sorting, encoding or the bare
// URI shows up here directly.
$refBaseString = 'POST&https%3A%2F%2Fapi.twitter.com%2F1.1%2Fstatuses%2Fupdate.json'
    . '&include_entities%3Dtrue%26oauth_consumer_key%3Dxvz1evFS4wEEPTGEFPHBog'
    . '%26oauth_nonce%3DkYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg'
    . '%26oauth_signature_method%3DHMAC-SHA1%26oauth_timestamp%3D1318622958'
    . '%26oauth_token%3D370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb'
    . '%26oauth_version%3D1.0%26status%3DHello%2520Ladies%2520%252B%2520'
    . 'Gentlemen%252C%2520a%2520signed%2520OAuth%2520request%2521';

// HMAC-SHA1 of that base string under the documented signing key, confirmed
// against `openssl dgst -sha1 -hmac`.
$refSignature = 'hCtSmYh+iHYCEqBWrE7C7hYmtUk=';

$runner->run('builds the documented signature base string', function () use ($refUrl, $refParams, $refConsumerKey, $refToken, $refNonce, $refTimestamp, $refBaseString) {
    $all = array_merge($refParams, [
        'oauth_consumer_key'     => $refConsumerKey,
        'oauth_nonce'            => $refNonce,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string) $refTimestamp,
        'oauth_token'            => $refToken,
        'oauth_version'          => '1.0',
    ]);
    assert_same($refBaseString, oauth1_base_string('POST', $refUrl, $all));
});

$runner->run('derives the documented signature from that base string', function () use ($refBaseString, $refConsumerSecret, $refTokenSecret, $refSignature) {
    $key = rawurlencode($refConsumerSecret) . '&' . rawurlencode($refTokenSecret);
    assert_same($refSignature, base64_encode(hash_hmac('sha1', $refBaseString, $key, true)));
});

$runner->run('header reproduces the reference signature end to end', function () use ($refUrl, $refParams, $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret, $refNonce, $refTimestamp, $refSignature) {
    $header = oauth1_header(
        'POST', $refUrl, $refParams,
        $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret,
        $refNonce, $refTimestamp
    );
    $matched = preg_match('/oauth_signature="([^"]+)"/', $header, $m);
    assert_true((bool) $matched, 'no oauth_signature in header: ' . $header);
    // rawurlencode escapes '=' as %3D, so decode before comparing.
    assert_same($refSignature, rawurldecode($m[1]));
});

$runner->run('header carries the OAuth scheme and all required fields', function () use ($refUrl, $refParams, $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret, $refNonce, $refTimestamp) {
    $header = oauth1_header(
        'POST', $refUrl, $refParams,
        $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret,
        $refNonce, $refTimestamp
    );
    assert_contains('OAuth ', $header);
    foreach (['oauth_consumer_key', 'oauth_nonce', 'oauth_signature_method', 'oauth_timestamp', 'oauth_token', 'oauth_version', 'oauth_signature'] as $f) {
        assert_contains($f . '="', $header, "missing $f");
    }
    assert_contains('oauth_nonce="' . $refNonce . '"', $header);
    assert_contains('oauth_timestamp="' . $refTimestamp . '"', $header);
    assert_contains('oauth_signature_method="HMAC-SHA1"', $header);
    assert_contains('oauth_version="1.0"', $header);
    // The oauth_* fields are sorted, and the request params are not echoed back
    // into the header (they belong in the body or the query string).
    assert_not_contains('status="', $header, 'request params must not appear in the Authorization header');
    assert_true(
        strpos($header, 'oauth_consumer_key') < strpos($header, 'oauth_nonce'),
        'oauth fields are not sorted'
    );
});

$runner->run('percent-encodes a query string out of the signature base URL', function () use ($refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret) {
    // A URL carrying its own query must sign against the bare path, otherwise
    // the chunked media upload (/append?command=APPEND&segment_index=N) breaks.
    $withQuery = oauth1_header(
        'POST', 'https://upload.twitter.com/1.1/media/upload.json?command=APPEND', [],
        $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret, 'fixednonce', 1700000000
    );
    $noQuery = oauth1_header(
        'POST', 'https://upload.twitter.com/1.1/media/upload.json', [],
        $refConsumerKey, $refConsumerSecret, $refToken, $refTokenSecret, 'fixednonce', 1700000000
    );
    assert_same(
        $noQuery,
        $withQuery,
        'a query string in the URL changed the signature base string'
    );
});

$runner->run('includes request params in the signature, not just oauth_*', function () {
    $a = oauth1_header('POST', 'https://api.x.com/2/tweets', ['text' => 'hi'],
        'ck', 'cs', 'tk', 'ts', 'n', 1);
    $b = oauth1_header('POST', 'https://api.x.com/2/tweets', ['text' => 'bye'],
        'ck', 'cs', 'tk', 'ts', 'n', 1);
    assert_true($a !== $b, 'changing a request param must change the signature');
});

$runner->run('generates a fresh nonce on every call by default', function () {
    $seen = [];
    for ($i = 0; $i < 5; $i++) {
        $h = oauth1_header('POST', 'https://api.x.com/2/tweets', [], 'ck', 'cs', 'tk', 'ts');
        preg_match('/oauth_nonce="([^"]+)"/', $h, $m);
        $seen[] = $m[1] ?? '';
    }
    assert_same(5, count(array_unique($seen)), 'reused an oauth_nonce');
});
