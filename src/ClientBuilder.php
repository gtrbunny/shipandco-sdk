<?php
/**
 * This code is licensed under the MIT License.
 *
 * Copyright (c) 2020 Alexey Kopytko <alexey@kopytko.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

declare(strict_types=1);

namespace ShipAndCoSDK;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\Naming\SerializedNameAnnotationStrategy;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializerInterface;
use JSONSerializer\Serializer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use VersionInfo\ComposerBranchAliasVersionReader;
use VersionInfo\GitVersionReader;
use VersionInfo\PlaceholderVersionReader;

use function GuzzleHttp\default_user_agent;
use function array_merge;
use function sprintf;
use function assert;
use function is_string;

/**
 * @codeCoverageIgnore
 */
final class ClientBuilder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_TIMEOUT = 60;

    private const STANDARD_BASE_URL = 'https://api.shipandco.com';

    private const PACKAGE_NAME = 'ShipAndCo-SDK';
    private const VERSION_INFO = '$Format:%h%d by %an +%ae$';

    /** @var ClientInterface|null */
    private $http;

    /** @var string */
    private $token = '';

    /** @var string */
    private $baseUrl = self::STANDARD_BASE_URL;

    /** @var int */
    private $timeout = self::DEFAULT_TIMEOUT;

    /** @var string|null */
    private $cacheDirectory = null;

    /** @var bool */
    private $cacheDebug = false;

    /** @var SerializerInterface|Serializer|null */
    private $serializer;

    /** @var string|null */
    private $userAgentPostfix;

    /** @var array */
    private $extraOptions = [];

    public static function clientWithToken(string $token = '', int $timeout = self::DEFAULT_TIMEOUT): Client
    {
        $builder = new self();
        $builder->setToken($token);
        $builder->setTimeout($timeout);

        return $builder->build();
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @see https://jmsyst.com/libs/serializer/master/configuration#configuring-a-cache-directory
     */
    public function setCacheDir(?string $cacheDirectory = null, bool $debug = false): self
    {
        $this->cacheDirectory = $cacheDirectory;
        $this->cacheDebug = $debug;

        return $this;
    }

    private function buildSerializer(): Serializer
    {
        $builder = SerializerBuilder::create();

        $builder->setPropertyNamingStrategy(
            new SerializedNameAnnotationStrategy(
                new IdenticalPropertyNamingStrategy()
            )
        );

        /**
         * @see https://jmsyst.com/libs/serializer/master/configuration#configuring-a-cache-directory
         */
        if ($this->cacheDirectory !== null) {
            $builder->setCacheDir($this->cacheDirectory);
            $builder->setDebug($this->cacheDebug);
        }

        return new Serializer($builder);
    }

    public function build(): Client
    {
        if ($this->serializer === null) {
            $this->serializer = $this->buildSerializer();
        }

        $this->http = $this->http ?? new GuzzleClient(array_merge([
            'base_uri' => $this->baseUrl,
            'timeout'  => $this->timeout,
            'headers'  => [
                'X-Access-Token'  => $this->token,
                'User-Agent'      => $this->getDefaultUserAgent(),
            ],
        ], $this->extraOptions));

        $client = new Client($this->http, $this->serializer);

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        if ($this->logger !== null) {
            $client->setLogger($this->logger);
        }

        return $client;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function setGuzzleClientExtraOptions(array $extraOptions): self
    {
        $this->extraOptions = $extraOptions;

        return $this;
    }

    public function setUserAgent(string $product, string $versionDetails): self
    {
        $this->userAgentPostfix = sprintf('%s/%s', $product, $versionDetails);

        return $this;
    }

    public function setGuzzleClient(ClientInterface $http): self
    {
        $this->http = $http;

        return $this;
    }

    public function setSerializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     *
     * @phan-suppress PhanDeprecatedFunction
     *
     * @psalm-suppress RedundantConditionGivenDocblockType
     * @psalm-suppress DeprecatedFunction
     */
    private function getDefaultUserAgent(): string
    {
        if ($this->userAgentPostfix === null) {
            $this->setUserAgent(self::PACKAGE_NAME, self::getVersion() ?? 'dev-unknown');
        }

        assert(is_string($this->userAgentPostfix));

        return default_user_agent() . ' ' . $this->userAgentPostfix;
    }

    /**
     * @codeCoverageIgnore
     *
     * @psalm-suppress MixedArrayAccess
     */
    private static function getVersion(): ?string
    {
        foreach ([
            new PlaceholderVersionReader(self::VERSION_INFO),
            new GitVersionReader(__DIR__ . '/../.git'),
            new ComposerBranchAliasVersionReader(__DIR__ . '/../composer.json'),
        ] as $versionReader) {
            $version = $versionReader->getVersionString();

            if ($version !== null) {
                break;
            }
        }

        return $version;
    }
}
