<?php
require_once 'TripSyncController.php';
$sync = new TripSyncController();
$sync->syncPriceAndInventory();
