<?php

declare(strict_types=1);

namespace Featureflip\Tests\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A stand-in evaluation API that records what the SDK sent. */
final class RecordingApi implements ClientInterface
{
    public HttpFactory $factory;
    public int $fetches = 0;
    public ?string $lastUrl = null;
    public ?string $lastAuthorization = null;
    public ?string $lastUserAgent = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            $this->fetches++;
            $this->lastUrl = (string) $request->getUri();
            $this->lastAuthorization = $request->getHeaderLine('Authorization');
            $this->lastUserAgent = $request->getHeaderLine('User-Agent');
        }

        return $this->factory->createResponse(200)
            ->withBody($this->factory->createStream('{"flags":[],"segments":[]}'));
    }
}
