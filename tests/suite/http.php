<?php
/**
 * HTTP helper behaviour, including the multipart builder used by the X media
 * upload and the API error shapes the UI branches on.
 */

$runner->group('HTTP helpers');

$runner->run('http_query_params keeps keys containing dots intact', function () {
    // parse_str() rewrites 'a.b' to 'a_b', which would silently break an
    // OAuth 1.0a signature.
    $q = http_query_params('media.metadata=x&segment_index=2&plain=1');
    assert_same('x', $q['media.metadata'] ?? null, 'parse_str-style key mangling detected');
    assert_same('2', $q['segment_index'] ?? null);
    assert_same('1', $q['plain'] ?? null);
});

$runner->run('http_query_params decodes percent-encoded values', function () {
    $q = http_query_params('text=hello%20world%2B%21&status=He%2Bllo');
    assert_same('hello world+!', $q['text'] ?? null);
    assert_same('He+llo', $q['status'] ?? null);
});

$runner->run('http_query_params handles an empty string and a leading ?', function () {
    assert_same([], http_query_params(''));
    assert_same([], http_query_params('?'));
    assert_same(['a' => 'b'], http_query_params('?a=b'));
});

$runner->run('http_query_params tolerates a valueless key', function () {
    $q = http_query_params('flag&a=b');
    assert_same('', $q['flag'] ?? null);
    assert_same('b', $q['a'] ?? null);
});

$runner->run('http_query_params gives the same result as http_build_query round-trip', function () {
    $pairs = ['a' => '1', 'b' => 'x y', 'c' => 'z&w'];
    assert_same($pairs, http_query_params(http_build_query($pairs)));
});

$runner->group('HTTP / error handling');

$runner->run('describe_api_error prefers the human-readable message', function () {
    $out = describe_api_error(['error' => ['message' => 'Invalid request', 'code' => 100]]);
    assert_contains('Invalid request', $out);
});

$runner->run('describe_api_error falls back through the known shapes', function () {
    assert_contains('rate limited', strtolower(describe_api_error(['error' => ['error_user_msg' => 'Rate limited']])));
    assert_contains('403', describe_api_error(['error' => ['code' => 403]]));
    assert_contains('plain text', describe_api_error('plain text failure'));
    assert_contains('Unknown', describe_api_error(null));
});

$runner->run('describe_api_error never leaks a stack trace', function () {
    $out = describe_api_error(['error' => ['message' => 'boom']]);
    assert_not_contains('vendor/', $out);
    assert_not_contains('/var/www', $out);
});
