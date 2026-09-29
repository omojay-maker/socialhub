<?php
/**
 * The platform registry is the single source of truth shared by the API, the
 * OAuth endpoint and the Accounts screen, so drift between it and the database
 * or the provider classes shows up here.
 */

$runner->group('Platform registry');

$runner->run('all four platforms are in the allow-list', function () {
    assert_same(
        ['instagram', 'linkedin', 'tiktok', 'twitter'],
        ProviderFactory::SUPPORTED
    );
});

$runner->run('every allow-listed slug resolves to a real provider', function () {
    foreach (ProviderFactory::SUPPORTED as $slug) {
        $p = ProviderFactory::real($slug);
        assert_true($p instanceof SocialMediaProvider, "no provider for $slug");
        assert_same($slug, $p->getPlatformSlug(), "slug mismatch for $slug");
    }
});

$runner->run('every real provider implements the full interface', function () {
    $iface = array_map(
        fn(ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(SocialMediaProvider::class))->getMethods()
    );
    foreach (ProviderFactory::SUPPORTED as $slug) {
        $p = ProviderFactory::real($slug);
        foreach ($iface as $method) {
            assert_true(method_exists($p, $method), "$slug is missing $method()");
        }
    }
});

$runner->run('make() falls back to the mock for an unknown slug', function () {
    $p = ProviderFactory::make('myspace', true);
    assert_true($p instanceof MockSocialProvider, 'unknown slugs must not fatal');
});

$runner->run('mock providers declare themselves unconfigured for real UI', function () {
    // The Accounts screen uses this to disable the Connect button in mock mode.
    foreach (ProviderFactory::statusForUi() as $row) {
        assert_false($row['can_connect'], "can_connect should be false in mock mode for {$row['slug']}");
        // A setup hint is still useful in mock mode: it tells the operator what
        // to configure before switching SOCIAL_MODE to live.
        assert_true(is_string($row['setup_hint']), "setup_hint should be a string for {$row['slug']}");
    }
});

$runner->run('statusForUi covers exactly the allow-listed slugs', function () {
    $slugs = array_column(ProviderFactory::statusForUi(), 'slug');
    sort($slugs);
    $expected = ProviderFactory::SUPPORTED;
    sort($expected);
    assert_same($expected, $slugs);
});

$runner->group('Database / platform rows');

$runner->run('social_platforms holds a row per supported slug', function () {
    $rows = db()->query('SELECT slug FROM social_platforms')->fetchAll(PDO::FETCH_COLUMN);
    foreach (ProviderFactory::SUPPORTED as $slug) {
        assert_true(in_array($slug, $rows, true), "social_platforms is missing '$slug' (run the migrations)");
    }
});

$runner->run('every platform row has a name and a hex colour', function () {
    foreach (db()->query('SELECT slug, name, color FROM social_platforms')->fetchAll() as $r) {
        assert_true($r['name'] !== '', "platform {$r['slug']} has no display name");
        assert_true(
            (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r['color']),
            "platform {$r['slug']} has a colour the UI cannot render: {$r['color']}"
        );
    }
});

$runner->run('migration 005 has been applied', function () {
    $stmt = db()->prepare("SELECT COUNT(*) FROM schema_migrations WHERE filename = ?");
    $stmt->execute(['005_platforms_tiktok_twitter.sql']);
    assert_same(1, (int) $stmt->fetchColumn(), 'run: php scripts/migrate.php');
});
