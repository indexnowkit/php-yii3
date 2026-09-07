<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Testing\Conformance\KeyFileAssertions;
use IndexNowKit\Yii3\Tests\Support\Web;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The key file route of config/routes.php through the real router: matched by pattern, served by the PSR-15
 * handler, per host.
 */
final class KeyFileTest extends Yii3TestCase
{
    #[TestDox('H01 GET /{key}.txt -> 200 text/plain with the key, short cache, Vary: Host with a hosts map')]
    public function testKeyFile(): void
    {
        $response = Web::get($this->container, self::BASE_URL . '/' . self::KEY . '.txt');

        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), Web::headers($response), (string) $response->getBody(), self::KEY, expectVaryHost: true);
    }

    #[TestDox('H01b the key file of another configured host is served only on that host')]
    public function testKeyFileIsPerHost(): void
    {
        KeyFileAssertions::assertNotServed(Web::get($this->container, self::BASE_URL . '/' . self::SECOND_KEY . '.txt')->getStatusCode());

        $response = Web::get($this->container, 'https://example.de/' . self::SECOND_KEY . '.txt');
        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), Web::headers($response), (string) $response->getBody(), self::SECOND_KEY, expectVaryHost: true);
    }

    #[TestDox('H02 GET /other.txt -> 404; a key shorter than 8 characters does not even match the route')]
    public function testUnknownKey(): void
    {
        KeyFileAssertions::assertNotServed(Web::get($this->container, self::BASE_URL . '/abcdefghijklmnop.txt')->getStatusCode());
        KeyFileAssertions::assertNotServed(Web::get($this->container, self::BASE_URL . '/short.txt')->getStatusCode());
    }
}
