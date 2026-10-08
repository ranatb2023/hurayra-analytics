<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs at one fixed instant. Relative dates ("renews in 3d",
     * "active as of now") are measured from the real clock, so leaving it
     * running would make a fixture pass today and fail next month.
     */
    public const FROZEN_NOW = '2026-10-08 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW, 'UTC'));
    }
}
