<?php

declare(strict_types=1);

namespace KaririCode\Tests\Cache\Unit\ValueObject;

use KaririCode\Cache\ValueObject\CircuitBreakerState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CircuitBreakerState Value Object.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CircuitBreakerState::class)]
final class CircuitBreakerStateTest extends TestCase
{
    private const FAILURE_THRESHOLD = 5;
    private const OPEN_TIMEOUT_SECONDS = 60;

    public function testInitialStateIsClosed(): void
    {
        $state = new CircuitBreakerState();

        $this->assertTrue($state->isClosed());
        $this->assertFalse($state->isOpen());
        $this->assertFalse($state->isHalfOpen());
        $this->assertSame(0, $state->getFailureCount());
        $this->assertNull($state->lastFailureTimestamp);
    }

    public function testRecordSuccessOnClosedStateChangesNothing(): void
    {
        $initialState = new CircuitBreakerState();
        $newState = $initialState->recordSuccess();

        $this->assertSame($initialState, $newState);
    }

    public function testRecordFailureIncrementsCountAndSetsTimestamp(): void
    {
        $state = new CircuitBreakerState();
        $newState = $state->recordFailure();

        $this->assertSame(1, $newState->getFailureCount());
        $this->assertNotNull($newState->lastFailureTimestamp);
        $this->assertTrue($newState->isClosed());
    }

    public function testShouldOpenReturnsTrueWhenThresholdIsReached(): void
    {
        $state = new CircuitBreakerState();
        for ($i = 0; $i < self::FAILURE_THRESHOLD; ++$i) {
            $state = $state->recordFailure();
        }

        $this->assertTrue($state->shouldOpen());
        $this->assertSame(self::FAILURE_THRESHOLD, $state->getFailureCount());
    }

    public function testOpenTransitionsStateToOpen(): void
    {
        $state = new CircuitBreakerState(failures: self::FAILURE_THRESHOLD);
        $openedState = $state->open();

        $this->assertTrue($openedState->isOpen());
        $this->assertFalse($openedState->isClosed());
    }

    public function testCanAttemptRetryReturnsFalseWhenOpenAndWithinTimeout(): void
    {
        $state = new CircuitBreakerState(failures: self::FAILURE_THRESHOLD, lastFailureTimestamp: time() - 10);
        $openedState = $state->open();

        $this->assertFalse($openedState->canAttemptRetry());
    }

    public function testCanAttemptRetryReturnsTrueWhenOpenAndTimeoutHasPassed(): void
    {
        $pastTimestamp = time() - self::OPEN_TIMEOUT_SECONDS - 1;
        $state = new CircuitBreakerState(failures: self::FAILURE_THRESHOLD, lastFailureTimestamp: $pastTimestamp);
        $openedState = $state->open();

        $this->assertTrue($openedState->canAttemptRetry());
    }

    public function testCanAttemptRetryReturnsTrueWhenNoPreviousFailure(): void
    {
        $state = new CircuitBreakerState();
        $this->assertTrue($state->canAttemptRetry());
    }

    public function testShouldAttemptResetLogic(): void
    {
        // Case 1: No last failure timestamp, should be false.
        $state1 = new CircuitBreakerState();
        $this->assertFalse($state1->shouldAttemptReset(30));

        // Case 2: Timeout has not passed, should be false.
        $state2 = new CircuitBreakerState(lastFailureTimestamp: time() - 10);
        $this->assertFalse($state2->shouldAttemptReset(30));

        // Case 3: Timeout has passed, should be true.
        $state3 = new CircuitBreakerState(lastFailureTimestamp: time() - 40);
        $this->assertTrue($state3->shouldAttemptReset(30));
    }

    public function testHalfOpenTransitionsStateAndResetsFailures(): void
    {
        $state = new CircuitBreakerState(failures: 5, state: CircuitBreakerState::STATE_OPEN);
        $halfOpenState = $state->halfOpen();

        $this->assertTrue($halfOpenState->isHalfOpen());
        $this->assertSame(0, $halfOpenState->getFailureCount());
        $this->assertNull($halfOpenState->lastFailureTimestamp);
    }

    public function testRecordSuccessOnHalfOpenStateClosesCircuit(): void
    {
        $state = new CircuitBreakerState(state: CircuitBreakerState::STATE_HALF_OPEN);
        $closedState = $state->recordSuccess();

        $this->assertTrue($closedState->isClosed());
        $this->assertSame(0, $closedState->getFailureCount());
    }

    public function testRecordFailureOnHalfOpenStateDoesNotImmediatelyReopen(): void
    {
        $state = new CircuitBreakerState(state: CircuitBreakerState::STATE_HALF_OPEN);
        $failedState = $state->recordFailure();

        $this->assertTrue($failedState->isHalfOpen());
        $this->assertSame(1, $failedState->getFailureCount());
    }
}
