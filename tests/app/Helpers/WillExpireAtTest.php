<?php

namespace Tests\Feature;

use Tests\TestCase;
use Carbon\Carbon;

class WillExpireAtTest extends TestCase
{
    /**
     * Test case when the difference is less than or equal to 90 minutes.
     *
     * @return void
     */
    public function testWillExpireAtWhenDifferenceIsLessThanOrEqualTo90Minutes()
    {
        $dueTime = Carbon::now()->addMinutes(60)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($dueTime)->format('Y-m-d H:i:s');
        $result = \App\Helpers\TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test case when the difference is between 90 minutes and 24 hours.
     *
     * @return void
     */
    public function testWillExpireAtWhenDifferenceIsBetween90MinutesAnd24Hours()
    {
        $dueTime = Carbon::now()->addMinutes(120)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::now()->addMinutes(90)->format('Y-m-d H:i:s');
        $result = \App\Helpers\TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test case when the difference is between 24 hours and 72 hours.
     *
     * @return void
     */
    public function testWillExpireAtWhenDifferenceIsBetween24HoursAnd72Hours()
    {
        $dueTime = Carbon::now()->addHours(48)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::now()->addHours(16)->format('Y-m-d H:i:s');
        $result = \App\Helpers\TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test case when the difference is more than 72 hours.
     *
     * @return void
     */
    public function testWillExpireAtWhenDifferenceIsMoreThan72Hours()
    {
        $dueTime = Carbon::now()->addHours(100)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($dueTime)->subHours(48)->format('Y-m-d H:i:s');
        $result = \App\Helpers\TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expected, $result);
    }
}
