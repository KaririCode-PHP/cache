<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\ExceptionInterface;

/**
 * Marker of every exception this package throws; catch it to handle any
 * cache failure. Each concrete exception also implements exactly one
 * kariricode/contract family, which the edge maps to a response.
 *
 *   8600-8609  BackendException            Runtime        backend I/O, payload
 *   8610-8619  ConnectionException         Runtime        unreachable backend
 *   8620-8629  StoreNotFoundException      NotFound       registry lookup
 *   8630-8639  InvalidKeyException         Validation     key rules
 *   8640-8649  ConfigurationException      Configuration  options, extensions
 *   8650-8659  Bridge\Exception\*          Validation / Runtime (PSR boundary)
 *   8660-8699  reserved
 *
 * Messages never echo keys, rejected values or store names (E-047): keys
 * routinely carry personal data, and messages reach logs.
 *
 * Ecosystem allocation: AsyncPrimitives 1000-1499 · EventLoop 2000-2499 ·
 * Async 3000-3049 · DependencyInjection 3100-3169 · Queue 4000-4099 ·
 * Template 7000-7099 · CircuitBreaker 7100-7199 · Authorization 7300-7499 ·
 * EventDispatcher 8100-8199 · Observability 8200-8249 · RateLimiter
 * 8300-8399 · Authenticator 8400-8599 · Cache 8600-8699 · I18n 9100-9199.
 *
 * ARFA 1.3 P5 (Continuous Observability): stable numeric codes.
 * Spec V4: exception marker (§8.4); families from kariricode/contract 2.11.
 *
 * Preconditions: none.
 * Thread-safety: stateless marker.
 *
 * Side effects: none.
 *
 * Example:
 *     try { $cache->get($key); } catch (CacheException $e) { $logger->error($e->getMessage(), ['code' => $e->getCode()]); }
 *
 * References: ADR-013; SPEC-001 §11.
 *
 * @see https://www.php.net/manual/en/class.throwable.php
 */
interface CacheException extends ExceptionInterface {}
