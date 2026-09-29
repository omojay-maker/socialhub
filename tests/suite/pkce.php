<?php
/**
 * PKCE (RFC 7636) used by the X/Twitter OAuth 2.0 authorization code flow.
 */

$runner->group('PKCE / OAuth 2.0 (X)');

$runner->run('S256 challenge matches the RFC 7636 appendix B vector', function () {
    $verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    assert_same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge);
});

$runner->run('b64u strips padding and uses the URL-safe alphabet', function () {
    $encoded = b64u(random_bytes(32));
    assert_not_contains('=', $encoded, 'padding must be stripped');
    assert_not_contains('+', $encoded);
    assert_not_contains('/', $encoded);
    assert_true((bool) preg_match('/^[A-Za-z0-9\-_]+$/', $encoded), 'unexpected characters: ' . $encoded);
});

$runner->run('state context carries a verifier long enough for the callback', function () {
    $x = ProviderFactory::real('twitter');
    $ctx = $x->prepareStateContext();
    assert_true(isset($ctx['code_verifier']), 'no code_verifier in state context');
    // RFC 7636 section 4.1 requires 43-128 characters.
    assert_true(strlen($ctx['code_verifier']) >= 43, 'verifier too short: ' . strlen($ctx['code_verifier']));
    assert_true(strlen($ctx['code_verifier']) <= 128, 'verifier too long: ' . strlen($ctx['code_verifier']));
});

$runner->run('authorization request advertises S256 and a challenge', function () {
    $x = ProviderFactory::real('twitter');
    $ctx = $x->prepareStateContext();
    $url = $x->authUrl('STATE_TEST', $ctx);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    assert_same('code', $q['response_type'] ?? null);
    assert_same('S256', $q['code_challenge_method'] ?? null);
    assert_same('STATE_TEST', $q['state'] ?? null);
    assert_true(!empty($q['code_challenge']), 'missing code_challenge');

    $expected = rtrim(strtr(base64_encode(hash('sha256', $ctx['code_verifier'], true)), '+/', '-_'), '=');
    assert_same($expected, $q['code_challenge'], 'challenge does not match the stored verifier');
});

$runner->run('requests offline.access so a refresh token is issued', function () {
    $x = ProviderFactory::real('twitter');
    parse_str((string) parse_url($x->authUrl('S', $x->prepareStateContext()), PHP_URL_QUERY), $q);
    $scopes = preg_split('/\s+/', trim((string) ($q['scope'] ?? ''))) ?: [];
    assert_true(in_array('offline.access', $scopes, true), 'offline.access missing, publishing would stop after 2h');
});

$runner->run('TWITTER_EXTRA_SCOPES is split on spaces and commas', function () {
    $x = ProviderFactory::real('twitter');
    $reflection = new ReflectionMethod($x, 'scopes');
    $reflection->setAccessible(true);

    putenv('TWITTER_EXTRA_SCOPES=like.write follows.read,lists.write');
    $scopes = $reflection->invoke($x);

    // A naive (array) cast would produce one entry containing the separators.
    assert_false(in_array('like.write follows.read,lists.write', $scopes, true),
        'extra scopes were not tokenised');
    foreach (['like.write', 'follows.read', 'lists.write'] as $s) {
        assert_true(in_array($s, $scopes, true), "missing extra scope $s");
    }

    putenv('TWITTER_EXTRA_SCOPES');
});

$runner->run('client credentials are sent as a Basic authorization header', function () {
    $x = ProviderFactory::real('twitter');
    $m = new ReflectionMethod($x, 'clientAuthHeader');
    $m->setAccessible(true);

    putenv('TWITTER_CLIENT_ID=id123');
    putenv('TWITTER_CLIENT_SECRET=sec456');
    $header = $m->invoke($x);

    assert_contains('Basic ', $header, 'X rejects token calls without the Basic scheme prefix');
    $decoded = base64_decode(substr($header, 6), true);
    assert_same('id123:sec456', $decoded);

    putenv('TWITTER_CLIENT_ID');
    putenv('TWITTER_CLIENT_SECRET');
});
