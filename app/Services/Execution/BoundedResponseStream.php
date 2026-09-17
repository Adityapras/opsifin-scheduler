<?php

namespace App\Services\Execution;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** Retain a bounded prefix while consuming the response without buffering its tail. */
final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private readonly int $limit)
    {
        $this->stream = Utils::streamFor('');
    }

    public function write(string $string): int
    {
        $remaining = max(0, $this->limit - $this->stream->getSize());
        if ($remaining > 0) {
            $this->stream->write(substr($string, 0, $remaining));
        }

        return strlen($string);
    }
}
