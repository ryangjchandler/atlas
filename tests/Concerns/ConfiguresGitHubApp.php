<?php

namespace Tests\Concerns;

trait ConfiguresGitHubApp
{
    protected static ?string $githubPrivateKey = null;

    /**
     * Configure the GitHub App with a real RSA key, so tokens are actually signed, and return its public key.
     */
    protected function configureGitHubApp(): string
    {
        if (static::$githubPrivateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            static::$githubPrivateKey = $pem;
        }

        // Store it the way single-line environments do, with literal "\n" separators...
        config([
            'services.github.driver' => 'app',
            'services.github.app_id' => '12345',
            'services.github.app_slug' => 'atlas-test',
            'services.github.private_key' => str_replace("\n", '\n', static::$githubPrivateKey),
        ]);

        return openssl_pkey_get_details(openssl_pkey_get_private(static::$githubPrivateKey))['key'];
    }
}
