<?php

namespace Tests\Unit;

use App\Services\LegacyImport\CurlParser;
use PHPUnit\Framework\TestCase;

class CurlParserTest extends TestCase
{
    private CurlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CurlParser;
    }

    public function test_parses_single_line_client_script(): void
    {
        $script = <<<'SH'
        #!/bin/bash
        curl -i -H "Content-Type: application/json" -H 'Accept:application/json' -H 'Authorization:Basic cmVzdF9icmF2bzpzZWNyZXQ=' -X POST -d '{"person":{"name":"bob"}}' https://bravo.opsifin.com/apiv_g/api_repost
        SH;

        $curl = $this->parser->parseFile($script);

        $this->assertSame('POST', $curl->method);
        $this->assertSame('https://bravo.opsifin.com', $curl->baseUrl());
        $this->assertSame('/apiv_g/api_repost', $curl->path);
        $this->assertSame('{"person":{"name":"bob"}}', $curl->body);
        $this->assertSame('Basic', $curl->authScheme);
        $this->assertSame('rest_bravo', $curl->authUsername);
        $this->assertSame('secret', $curl->authPassword);
    }

    public function test_parses_multiline_command_with_continuations(): void
    {
        $script = <<<'SH'
        #!/bin/bash
        curl -i --http1.1 --connect-timeout 10 --max-time 60 \
          -H "Content-Type: application/json" \
          -H "SecretKey: abc123" \
          -X POST \
          -d '{}' \
          "https://gns.opsifin.com/api/remittanceApi"
        SH;

        $curl = $this->parser->parseFile($script);

        $this->assertSame('/api/remittanceApi', $curl->path);
        $this->assertSame('abc123', $curl->secretKey);
        $this->assertSame(60, $curl->maxTime);
        $this->assertSame(10, $curl->connectTimeout);
        $this->assertSame(['SecretKey' => 'abc123'], $curl->extraHeaders());
    }

    public function test_flag_with_value_is_not_mistaken_for_the_url(): void
    {
        // Regresi: `-A "Mozilla/5.0 ..."` sempat terbaca sebagai URL.
        $script = <<<'SH'
        curl -i -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64)" \
          -H "Connection: keep-alive" \
          -X POST -d '{}' "https://jontru.opsifin.com/api/v1/kcic/email"
        SH;

        $curl = $this->parser->parseFile($script);

        $this->assertSame('jontru.opsifin.com', $curl->host);
        $this->assertSame('/api/v1/kcic/email', $curl->path);
    }

    public function test_substitutes_environment_variables(): void
    {
        $script = <<<'SH'
        source "/home/ubuntu/cron/opsifin_env.sh"
        curl -i -H "Authorization:${OPSIFIN_QA2_API_TOKEN}" -X POST -d '{}' ${OPSIFIN_QA2_BASE_URL}/opsifin_api_print/updatePrintBilling
        SH;

        $curl = $this->parser->parseFile($script, [
            'OPSIFIN_QA2_BASE_URL' => 'https://qa2.fin-svc-barto.net',
            'OPSIFIN_QA2_API_TOKEN' => 'Basic '.base64_encode('rest_qa2:pass'),
        ]);

        $this->assertSame('https://qa2.fin-svc-barto.net', $curl->baseUrl());
        $this->assertSame('rest_qa2', $curl->authUsername);
    }

    public function test_detects_url_written_on_a_separate_line_without_continuation(): void
    {
        $script = <<<'SH'
        curl -i -H 'Accept:application/json' -X POST -d '{}'
        https://pesonnago.fin-svc-barto.net/api/PostInvoice/tokenUpdate
        SH;

        $curl = $this->parser->parseFile($script);

        $this->assertNull($curl->rawUrl);
        $this->assertSame('https://pesonnago.fin-svc-barto.net/api/PostInvoice/tokenUpdate', $curl->danglingUrl);
    }

    public function test_normalizes_duplicated_slashes_in_path(): void
    {
        $curl = $this->parser->parseFile("curl -X POST -d '{}' https://misteraladin.opsifin.com//apiv1/api_all");

        $this->assertSame('/apiv1/api_all', $curl->path);
    }

    public function test_reports_missing_timeouts_as_problems(): void
    {
        $curl = $this->parser->parseFile("curl -X POST -d '{}' https://gn.opsifin.com/x");

        $this->assertContains('Tidak ada --max-time.', $curl->problems);
        $this->assertContains('Tidak ada --connect-timeout.', $curl->problems);
    }
}
