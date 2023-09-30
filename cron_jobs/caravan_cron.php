<?php
session_start();

require_once __DIR__ . '/../classes/System.php';
require_once __DIR__ . '/../classes/Village.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/War/WarManager.php';
require_once __DIR__ . '/../classes/travel/Patrol.php';

$system = new System();
$system->db->connect();

if (!$system->db->con) {
    throw new RuntimeException("Error connecting to DB!");
}

$player = false;
if (isset($_SESSION['user_id'])) {
    $player = User::loadFromId($system, $_SESSION['user_id']);
    $player->loadData();
    $production_key = "RuN ON ProDUCtIon";

    if ($player->staff_manager->isHeadAdmin()) {
        if (isset($_GET['run_script']) && $_GET['run_script'] == 'true') {
            if ($system->environment === System::ENVIRONMENT_PROD) {
                $debug = false;
                if (isset($_GET['debug'])) {
                    $debug = $system->db->clean($_GET['debug']);
                }
                if (!isset($_POST['confirm']) || $_POST['confirm'] !== $production_key) {
                    echo "WARNING PRODUCTION ENVIRONMENT! Confirm you would like to run this adhoc on <b>PRODUCTION ENVIRONMENT</b>!!!<br />
                To confirm, type the following (case sensitive): $production_key<br />
                <form action='{$system->router->base_url}/cron_jobs/caravan_cron.php?run_script=true' method='post'>
                    <input type='text' name='confirm' /><input type='submit' value='Run Script' />
                </form>";
                } else {
                    hourlyCaravan($system, $debug);
                    $player->staff_manager->staffLog(
                        StaffManager::STAFF_LOG_ADMIN,
                        "{$player->user_name}({$player->user_id}) manually ran caravan cron."
                    );
                }
            } else {
                $debug = false;
                if (isset($_GET['debug'])) {
                    $debug = $system->db->clean($_GET['debug']);
                }
                hourlyCaravan($system, $debug);
                $player->staff_manager->staffLog(
                    StaffManager::STAFF_LOG_ADMIN,
                    "{$player->user_name}({$player->user_id}) manually ran caravan cron."
                );
            }
        } else {
            echo "You can run the caravan cron script Adhoc. This is not reversible.<br><a href='{$system->router->base_url}/cron_jobs/caravan_cron.php?run_script=true'>Run</a><br><a href='{$system->router->base_url}/cron_jobs/caravan_cron.php?run_script=true&debug=true'>Debug</a>";
        }
    }
} else {
    // Check for verify to run cron
    $run_ok = false;
    if (php_sapi_name() == 'cli') {
        $run_ok = true;
    } else if ($_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR']) {
        $run_ok = true;
    }

    if ($run_ok) {
        hourlyCaravan($system);
        $system->log('cron', 'Hourly Caravan', "Caravans and resources have been processed.");
    } else {
        $system->log('cron', 'Invalid access', "Attempted access by " . $_SERVER['REMOTE_ADDR']);
    }
}

function hourlyCaravan(System $system, $debug = true): void
{
    /* step 1: update village resources */

    // get villages
    $village_result = $system->db->query("SELECT * FROM `villages`");
    $village_result = $system->db->fetch_all($village_result);
    $villages = [];
    foreach ($village_result as $village) {
        $villages[$village['village_id']] = new Village($system, village_row: $village);
    }

    // get resources from caravans
    $caravan_result = $system->db->query("SELECT * FROM `caravans`");
    $caravan_result = $system->db->fetch_all($caravan_result);
    foreach ($caravan_result as $caravan) {
        $caravan_resources = json_decode($caravan['resources'], true);
        // add resource to village
        foreach ($caravan_resources as $resource_id => $quantity) {
            $villages[$caravan['village_id']]->addResource($resource_id, $quantity);
        }
    }
    // update resources
    foreach ($villages as $village) {
        $village->updateResources();
    }

    /* step 2: delete old caravans */

    $system->db->query("DELETE FROM `caravans`");

    /* step 3: generate new caravans */

    $queries = [];
    // get regions
    $region_result = $system->db->query("SELECT * FROM `regions` WHERE `region_id` > 5");
    $region_result = $system->db->fetch_all($region_result);
    foreach ($region_result as $region) {
        // get region locations for region
        $region_location_result = $system->db->query("SELECT * FROM `region_locations` WHERE `region_id` = {$region['region_id']}");
        $region_location_result = $system->db->fetch_all($region_location_result);
        $caravan_resources = [];
        // add resources to region caravan
        foreach ($region_location_result as $region_location) {
            if (!empty($caravan_resources[$region_location['resource_id']])) {
                $caravan_resources[$region_location['resource_id']] += $region_location['resource_count'];
            } else {
                $caravan_resources[$region_location['resource_id']] = ($region_location['resource_count']);
            }
        }
        // create new caravan for region
        // caravans will spawn from now until the next caravan tick, also giving enough buffer so that they reach their destination before the next tick
        $start_time = rand(time(), time() + (WarManager::CARAVAN_TIMER_HOURS * 60 * 60) - (WarManager::BASE_CARAVAN_TIME_MS / 1000));
        $travel_time = WarManager::BASE_CARAVAN_TIME_MS;
        $region_id = $region['region_id'];
        $village_id = $region['village'];
        $caravan_type = Patrol::CARAVAN_TYPE_RESOURCE;
        $resources = json_encode($caravan_resources);
        $name = $villages[$region['village']]->name . " Caravan";
        $queries[] = "INSERT INTO `caravans` (`start_time`, `travel_time`, `region_id`, `village_id`, `caravan_type`, `resources`, `name`)
            VALUES ('{$start_time}', '{$travel_time}', '{$region_id}', '{$village_id}', '{$caravan_type}', '{$resources}', '{$name}')";
    }

    if ($debug) {
        echo "Debug running...<br>";
        foreach ($queries as $query) {
            echo $query . "<br />";
        }
        echo "Debug complete";
    } else {
        echo "Script running...<br>";
        foreach ($queries as $query) {
            $system->db->query("LOCK TABLES `caravans` WRITE;");
            $system->db->query($query);
            $system->db->query("UNLOCK TABLES;");
        }
        echo "Script complete";
    }
}