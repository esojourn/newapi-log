<?php

namespace Tests\Unit;

use App\Support\ChannelLogMessage;
use PHPUnit\Framework\TestCase;

class ChannelLogMessageTest extends TestCase
{
    public function test_request_ids_in_plain_text_and_json_do_not_change_the_group(): void
    {
        $first = 'HTTP 503: No credentials. (request id: abc-123) (request id: def-456), body: {"message":"No credentials. (request id: abc-123)"}';
        $second = 'HTTP 503: No credentials. (request id: xyz-789), body: {"message":"No credentials. (request id: xyz-789)"}';

        $this->assertSame(ChannelLogMessage::groupingKey($first), ChannelLogMessage::groupingKey($second));
        $this->assertSame(ChannelLogMessage::groupingKey('HTTP 503'), ChannelLogMessage::groupingKey('HTTP 503 (Request_ID: abc_123) (request-id: xyz:456)'));
    }

    public function test_status_codes_error_details_and_null_are_not_ignored(): void
    {
        $messages = [
            null, '', 'HTTP 503', 'HTTP 504', 'HTTP 503: No credentials.', 'HTTP 503: Rate limit.',
            'quota 100', 'quota 200', 'failed at 12:20:01', 'failed at 12:30:01',
            'error (account id: abc)', 'error (account id: xyz)',
        ];
        $keys = array_map([ChannelLogMessage::class, 'groupingKey'], $messages);

        $this->assertCount(count($messages), array_unique($keys, SORT_REGULAR));
    }
}
