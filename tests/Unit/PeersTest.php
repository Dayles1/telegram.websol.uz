<?php

namespace Tests\Unit;

use App\Sending\Peers;
use App\Telegram\Phone;
use PHPUnit\Framework\TestCase;

class PeersTest extends TestCase
{
    public function test_every_spelling_of_a_username_becomes_one(): void
    {
        foreach (['@durov', 'durov', 't.me/durov', 'https://t.me/durov/', 'http://telegram.me/durov', ' @durov '] as $spelling) {
            $this->assertSame('@durov', Peers::normalize($spelling), $spelling);
        }
    }

    public function test_links_and_ids_are_kept(): void
    {
        $this->assertSame('https://t.me/+AbCdEf123', Peers::normalize('https://t.me/+AbCdEf123'));
        $this->assertSame('https://t.me/joinchat/AbCdEf', Peers::normalize('https://t.me/joinchat/AbCdEf'));
        $this->assertSame('-1001234567890', Peers::normalize('-1001234567890'));
    }

    public function test_unique_ignores_username_case_and_blank_lines(): void
    {
        $this->assertSame(['@Alice', '@bob'], Peers::parse("@Alice\nalice\n\n t.me/ALICE \n@bob,  ;"));
    }

    public function test_phone_format(): void
    {
        $this->assertSame('+998901112233', Phone::normalize('+998 (90) 111-22-33'));
        $this->assertSame('998901112233', Phone::digits('+998 90 111 22 33'));
        $this->assertSame('+99890*****33', Phone::mask('+998901112233'));
    }
}
