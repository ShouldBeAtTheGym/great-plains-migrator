<?php

namespace Tests\Unit;

use App\Services\InteractionLog;
use DateTimeImmutable;
use Tests\TestCase;

class InteractionLogTest extends TestCase
{
    public function test_glued_dated_lines_become_separate_notes(): void
    {
        $log = app(InteractionLog::class);
        $notes = <<<'TXT'
10.19 they ordered!10.18 waiting for csr to enter the order. Make sure on the address.10.14 they ordered.
****2026****
12.8 sent email asking if there is anything they need.
****2025****
12.2 sent email just checking in here today.
TXT;

        $rows = $log->rows([
            'id' => 'interaction_9_100',
            'body' => '<p>Assigned to ANDY<br></p>',
            'date' => '2026-10-29 12:00:00',
            'note_log' => $notes,
            'subject' => 'Account',
        ]);

        $this->assertCount(5, $rows);
        $this->assertSame('interaction_9_100_5', $rows[0]['id']);
        $this->assertSame('2026-10-19 08:00:00', $rows[0]['date']);
        $this->assertStringContainsString('10.19 they ordered!', $rows[0]['body']);
        $this->assertStringContainsString('Assigned to ANDY', $rows[0]['body']);
        $this->assertStringNotContainsString('10.18', $rows[0]['body']);
        $this->assertSame('2026-10-18 08:00:00', $rows[1]['date']);
        $this->assertStringContainsString('Make sure on the address.', $rows[1]['body']);
        $this->assertSame('2026-10-14 08:00:00', $rows[2]['date']);
        $this->assertSame('2025-12-08 08:00:00', $rows[3]['date']);
        $this->assertSame('interaction_9_100_1', $rows[4]['id']);
        $this->assertSame('2024-12-02 08:00:00', $rows[4]['date']);
    }

    public function test_indented_follow_ups_stay_on_the_dated_note(): void
    {
        $pieces = app(InteractionLog::class)->pieces(<<<'TXT'
10.28 - updated info.
   (4)ORDERED! Jeff said this is good to go
   (3)Sent TP
1/7 - Jeff reached out

****2026****

11/19 - Supplies working well?
TXT, new DateTimeImmutable('2026-10-28 16:56:33'));

        $this->assertCount(3, $pieces);
        $this->assertSame('2026-10-28 08:00:00', $pieces[0]['date']);
        $this->assertStringContainsString('(4)ORDERED!', $pieces[0]['text']);
        $this->assertStringContainsString('(3)Sent TP', $pieces[0]['text']);
        $this->assertSame('2026-01-07 08:00:00', $pieces[1]['date']);
        $this->assertSame('2025-11-19 08:00:00', $pieces[2]['date']);
    }

    public function test_month_names_and_a_store_header_split_by_year(): void
    {
        $rows = app(InteractionLog::class)->rows([
            'id' => 'interaction_72_000002',
            'body' => '<p></p>',
            'date' => new DateTimeImmutable('2026-10-26 14:03:38'),
            'note_log' => "Store: (250) 392-5923\r\nOct. 26 - Sent note\r\nJan. 26 - Sent booking\r\nDec. 2 - older note\r",
        ]);

        $this->assertSame('interaction_72_000002_0', $rows[0]['id']);
        $this->assertStringContainsString('Store: (250) 392-5923', $rows[0]['body']);
        $this->assertSame('2026-10-26 08:00:00', $rows[1]['date']);
        $this->assertSame('2026-01-26 08:00:00', $rows[2]['date']);
        $this->assertSame('2025-12-02 08:00:00', $rows[3]['date']);
    }

    public function test_a_single_update_keeps_the_original_id(): void
    {
        $rows = app(InteractionLog::class)->rows([
            'id' => 'interaction_80_10',
            'body' => '<p>Follow-up 2026-11-16 09:00<br></p>',
            'date' => '2026-10-27 09:00:00',
            'note_log' => '10-27 sent quote,',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('interaction_80_10', $rows[0]['id']);
        $this->assertSame('2026-10-27 08:00:00', $rows[0]['date']);
        $this->assertStringContainsString('10-27 sent quote,', $rows[0]['body']);
    }
}
