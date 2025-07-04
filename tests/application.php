<?php

// ./tests/application.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KaririCode\Cache\CacheBuilder;
use KaririCode\Cache\Exception\CacheException;

// Load configuration
$config = require __DIR__ . '/../config/plugins.php';

// Create the cache builder
$cacheBuilder = new CacheBuilder();

// try {
// Build the cache
$cache = $cacheBuilder->build($config);

echo "1. Basic cache operations:\n";

// Store an item
$cache->save($cache->getItem('user_1')->set([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]));
echo "Item 'user_1' saved in cache.\n";

// Retrieve an item
$userItem = $cache->getItem('user_1');
if ($userItem->isHit()) {
    $userData = $userItem->get();
    echo "User retrieved: {$userData['name']}, Email: {$userData['email']}\n";
} else {
    echo "User not found in cache.\n";
}

// Check if an item exists
echo $cache->hasItem('user_1') ? "The item 'user_1' exists in cache.\n" : "The item 'user_1' does not exist in cache.\n";

echo "\n2. Multiple item operations:\n";

// Store multiple items
$cache->saveDeferred($cache->getItem('user_2')->set(['name' => 'Jane Doe', 'email' => 'jane@example.com']));
$cache->saveDeferred($cache->getItem('user_3')->set(['name' => 'Bob Smith', 'email' => 'bob@example.com']));
$cache->commit();
echo "Items 'user_2' and 'user_3' saved in cache.\n";

// Retrieve multiple items
$items = $cache->getItems(['user_1', 'user_2', 'user_3']);
foreach ($items as $key => $item) {
    if ($item->isHit()) {
        $data = $item->get();
        echo "Item $key: {$data['name']}, {$data['email']}\n";
    }
}

echo "\n4. Using transactions:\n";

// Begin a transaction
$cache->beginTransaction();

try {
    $cache->save($cache->getItem('transaction_1')->set('Value 1'));
    $cache->save($cache->getItem('transaction_2')->set('Value 2'));

    // Simulate an error
    if (0 === rand(0, 1)) {
        throw new Exception('Simulated error');
    }

    $cache->commitTransaction();
    echo "Transaction completed successfully.\n";
} catch (Exception $e) {
    $cache->rollbackTransaction();
    echo 'Transaction rolled back due to error: ' . $e->getMessage() . "\n";
}

// Check items after transaction
foreach (['transaction_1', 'transaction_2'] as $key) {
    echo $cache->hasItem($key) ? "The item '$key' exists after the transaction.\n" : "The item '$key' does not exist after the transaction.\n";
}

echo "\n5. Item expiration:\n";

// Save an item with expiration
$expiringItem = $cache->getItem('expiring_item');
$expiringItem->set('This item will expire')->expiresAfter(5); // expires in 5 seconds
$cache->save($expiringItem);
echo "Item 'expiring_item' saved with a 5-second expiration.\n";

echo "Waiting for expiration...\n";
sleep(6);

echo $cache->hasItem('expiring_item') ? "The item 'expiring_item' still exists (it shouldn't).\n" : "The item 'expiring_item' expired correctly.\n";

echo "\n6. Cache clearing:\n";

// Clear the entire cache
$cache->clear();
echo "Cache completely cleared.\n";

// Check if items still exist
foreach (['user_1', 'user_2', 'user_3', 'transaction_1', 'transaction_2'] as $key) {
    echo $cache->hasItem($key) ? "The item '$key' still exists after clearing (it shouldn't).\n" : "The item '$key' was removed correctly.\n";
}
// } catch (CacheException $e) {
//     echo 'Cache error: ' . $e->getMessage() . "\n";
// } catch (Exception $e) {
//     echo 'An error occurred: ' . $e->getMessage() . "\n";
// }
