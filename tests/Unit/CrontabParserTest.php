<?php

namespace Tests\Unit;

use App\Enums\LegacyPattern;
use App\Services\LegacyImport\CrontabParser;
use PHPUnit\Framework\TestCase;

class CrontabParserTest extends TestCase
{
    private CrontabParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CrontabParser;
    }

    public function test_parses_direct_script_entry(): void
    {
        $entries = $this->parser->parse(
            "# -- Golden Nusa\n".
            "*/6 * * * * /home/ubuntu/cron/gn/repost.sh 2>&1 >> /home/ubuntu/cronlog/repost_gn.log\n"
        );

        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertSame(LegacyPattern::DirectScript, $entry->pattern);
        $this->assertSame('gn', $entry->clientKey);
        $this->assertSame('repost', $entry->taskKey);
        $this->assertSame('*/6 * * * *', $entry->cronExpression);
        $this->assertSame('Golden Nusa', $entry->sectionLabel);
        $this->assertFalse($entry->isCommented);
        $this->assertFalse($entry->hasFlock);
    }

    public function test_parses_gateway_entry_with_flock(): void
    {
        $entries = $this->parser->parse(
            "0 2 * * * /usr/bin/flock -n /tmp/mtt_auto_billing.lock /home/ubuntu/cron/gateway.sh mtt auto_billing\n"
        );

        $entry = $entries[0];

        $this->assertSame(LegacyPattern::Gateway, $entry->pattern);
        $this->assertSame('mtt', $entry->clientKey);
        $this->assertSame('auto_billing', $entry->taskKey);
        $this->assertTrue($entry->hasFlock);
        $this->assertSame('/tmp/mtt_auto_billing.lock', $entry->lockFile);
    }

    public function test_keeps_commented_entries_but_marks_them(): void
    {
        $entries = $this->parser->parse("#*/1 * * * * /home/ubuntu/cron/gn/kill_process_timeout.sh\n");

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]->isCommented);
        $this->assertSame('kill_process_timeout', $entries[0]->taskKey);
    }

    public function test_ignores_default_crontab_documentation(): void
    {
        $header = <<<'CRON'
        # Edit this file to introduce tasks to be run by cron.
        #
        # m h  dom mon dow   command
        CRON;

        $this->assertSame([], $this->parser->parse($header));
    }

    public function test_records_section_label_from_preceding_comment(): void
    {
        $entries = $this->parser->parse(
            "# -- Mister Aladin\n".
            "*/5 3-23,0-1 * * * /home/ubuntu/cron/aladin/repost.sh\n".
            "*/7 * * * * /home/ubuntu/cron/aladin/update_balance_trx.sh\n"
        );

        $this->assertSame('Mister Aladin', $entries[0]->sectionLabel);
        $this->assertSame('Mister Aladin', $entries[1]->sectionLabel);
        $this->assertSame('*/5 3-23,0-1 * * *', $entries[0]->cronExpression);
    }
}
