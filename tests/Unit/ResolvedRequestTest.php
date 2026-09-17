<?php

namespace Tests\Unit;

use App\Services\Execution\Dto\ResolvedRequest;
use PHPUnit\Framework\TestCase;

class ResolvedRequestTest extends TestCase
{
    public function test_encoded_credentials_and_authorization_tokens_are_redacted(): void
    {
        $secret = 'clé/with space"';
        $request = new ResolvedRequest('POST', 'https://example.test', null,
            ['Authorization' => 'Bearer header-secret'], 10, 1, [$secret]);

        foreach ([0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES] as $flags) {
            $this->assertSame('{"secret":"••••••••"}', $request->redact(json_encode(['secret' => $secret], $flags)));
        }
        $this->assertSame('secret=••••••••', $request->redact('secret='.rawurlencode($secret)));
        $this->assertSame('secret=••••••••', $request->redact('secret='.urlencode($secret)));
        $this->assertSame('•••••••• / ••••••••', $request->redact('Bearer header-secret / header-secret'));
    }

    public function test_bounded_body_ending_inside_a_credential_masks_the_partial_secret(): void
    {
        $request = new ResolvedRequest('POST', 'https://example.test', null, [], 10, 1, ['super-secret']);

        $this->assertSame('prefix ••••••••', $request->redact('prefix super-sec'));
    }
}
