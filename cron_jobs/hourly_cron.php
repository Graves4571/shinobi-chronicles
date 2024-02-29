<?php
session_start();

/**
 * Hourly Cron Job
 **
 *  This should be processed every hour.
 *
 *  Hourly Cron does the following:
 *      Calculates research and construction progress
 *      Applies upkeep from upgrades
 *      Applies resource decay
 *      Triggers war victory conditions
 *      Applies base production for each village
 *      Increases resource count of each region by production rate
 *      Increases/Decreases region_location stability value toward baseline
 *      Increases/Decreases region_location defense value toward baseline
 *      Applies region_location regen, bonus for castles based on local village health
 *      Creates resource logs
 */

require_once __DIR__ . '/../classes/System.php';
require_once __DIR__ . '/../classes/Village.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/inbox/Inbox.php';
require_once __DIR__ . '/../classes/war/WarManager.php';
require_once __DIR__ . '/../classes/travel/MapNPC.php';
require_once __DIR__ . '/../classes/village/VillageUpgradeManager.php';

$system = System::initialize(load_layout: false);
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
                <form action='{$system->router->base_url}/cron_jobs/hourly_cron.php?run_script=true' method='post'>
                    <input type='text' name='confirm' /><input type='submit' value='Run Script' />
                </form>";
                } else {
                    hourlyCron($system, $debug);
                    $player->staff_manager->staffLog(
                        StaffManager::STAFF_LOG_ADMIN,
                        "{$player->user_name}({$player->user_id}) manually ran hourly cron."
                    );
                }
            } else {
                $debug = false;
                if (isset($_GET['debug'])) {
                    $debug = $system->db->clean($_GET['debug']);
                }
                hourlyCron($system, $debug);
                $player->staff_manager->staffLog(
                    StaffManager::STAFF_LOG_ADMIN,
                    "{$player->user_name}({$player->user_id}) manually ran hourly cron."
                );
            }
        } else {
            echo "You can run the hourly cron script Adhoc. This is not reversible.<br><a href='{$system->router->base_url}/cron_jobs/hourly_cron.php?run_script=true'>Run</a><br><a href='{$system->router->base_url}/cron_jobs/hourly_cron.php?run_script=true&debug=true'>Debug</a>";
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
        hourlyCron($system, debug: false);
        $system->log('cron', 'Hourly Cron', "Regions have been processed.");
    } else {
        $system->log('cron', 'Invalid access', "Attempted access by " . $_SERVER['REMOTE_ADDR']);
    }
}

function hourlyCron(System $system, $debug = true): void {
    // get regions
    $region_result = $system->db->query("SELECT * FROM `regions`");
    $region_result = $system->db->fetch_all($region_result);
    $queries = [];
    // get villages
    $village_resource_production = [];
    $village_result = $system->db->query("SELECT * FROM `villages`");
    $village_result = $system->db->fetch_all($village_result);
    $villages = [];
    foreach ($village_result as $village) {
        $villages[$village['village_id']] = new Village($system, village_row: $village);
    }
    // handle research and construction
    foreach ($villages as $village) {
        foreach ($village->upgrades as $upgrade) {
            if ($upgrade->status == VillageUpgradeConfig::UPGRADE_STATUS_RESEARCHING) {
                $upgrade->research_progress += (time() - $upgrade->research_progress_last_updated) * $village->research_speed;
                $upgrade->research_progress_last_updated = time();
                if ($upgrade->research_progress > $upgrade->research_progress_required) {
                    // if upgrade has no upkeep, set to unlocked (permanent)
                    if (VillageUpgradeConfig::UPGRADE_UPKEEP[$upgrade->key][WarManager::RESOURCE_MATERIALS] == 0 && VillageUpgradeConfig::UPGRADE_UPKEEP[$upgrade->key][WarManager::RESOURCE_FOOD] == 0 && VillageUpgradeConfig::UPGRADE_UPKEEP[$upgrade->key][WarManager::RESOURCE_WEALTH] == 0) {
                        $upgrade->status = VillageUpgradeConfig::UPGRADE_STATUS_UNLOCKED;
                    } else {
                        $upgrade->status = VillageUpgradeConfig::UPGRADE_STATUS_INACTIVE;
                    }
                    $queries[] = "UPDATE `village_upgrades` SET `status` = '{$upgrade->status}', `research_progress` = NULL, `research_progress_required` = NULL, `research_progress_last_updated` = {$upgrade->research_progress_last_updated}, `research_boosted` = 0 WHERE `village_id` = {$village->village_id} AND `id` = {$upgrade->id}";
                    $village->research_score = VillageUpgradeManager::calcResearchScore($system, $village);
                    $queries[] = "UPDATE `villages` SET `research_score` = {$village->research_score} WHERE `village_id` = {$village->village_id}";
                } else {
                    $queries[] = "UPDATE `village_upgrades` SET `research_progress` = {$upgrade->research_progress}, `research_progress_last_updated` = {$upgrade->research_progress_last_updated} WHERE `village_id` = {$village->village_id} AND `id` = {$upgrade->id}";
                }
            }
            if ($upgrade->status == VillageUpgradeConfig::UPGRADE_STATUS_ACTIVATING) {
                $upgrade->research_progress += (time() - $upgrade->research_progress_last_updated) * $village->research_speed;
                $upgrade->research_progress_last_updated = time();
                if ($upgrade->research_progress > $upgrade->research_progress_required) {
                    $upgrade->status = VillageUpgradeConfig::UPGRADE_STATUS_ACTIVE;
                    $queries[] = "UPDATE `village_upgrades` SET `status` = '{$upgrade->status}', `research_progress` = NULL, `research_progress_required` = NULL, `research_progress_last_updated` = {$upgrade->research_progress_last_updated} WHERE `village_id` = {$village->village_id} AND `id` = {$upgrade->id}";
                } else {
                    $queries[] = "UPDATE `village_upgrades` SET `research_progress` = {$upgrade->research_progress}, `research_progress_last_updated` = {$upgrade->research_progress_last_updated} WHERE `village_id` = {$village->village_id} AND `id` = {$upgrade->id}";
                }

            }
        }
        foreach ($village->buildings as $building) {
            if ($building->status == VillageBuildingConfig::BUILDING_STATUS_UPGRADING) {
                $building->construction_progress += (time() - $building->construction_progress_last_updated) * $village->construction_speed;
                $building->construction_progress_last_updated = time();
                if ($building->construction_progress > $building->construction_progress_required) {
                    $building->status = VillageBuildingConfig::BUILDING_STATUS_DEFAULT;
                    $building->tier += 1;
                    $building->construction_progress = 0;
                    $building->health = VillageBuildingConfig::BUILDING_MAX_HEALTH[$building->key][$building->tier];
                    $queries[] = "UPDATE `village_buildings` SET `status` = '{$building->status}', `construction_progress` = NULL, `construction_progress_required` = NULL, `construction_progress_last_updated` = {$building->construction_progress_last_updated}, `tier` = {$building->tier}, `construction_boosted` = 0, `health` = {$building->health} WHERE `village_id` = {$village->village_id} AND `id` = {$building->id}";
                    $village->construction_score = VillageUpgradeManager::calcConstructionScore($system, $village);
                    $queries[] = "UPDATE `villages` SET `construction_score` = {$village->construction_score} WHERE `village_id` = {$village->village_id}";
                } else {
                    $queries[] = "UPDATE `village_buildings` SET `construction_progress` = {$building->construction_progress}, `construction_progress_last_updated` = {$building->construction_progress_last_updated} WHERE `village_id` = {$village->village_id} AND `id` = {$building->id}";
                }
            }
        }
    }
    // apply upkeep from upgrades
    foreach ($villages as $village) {
        $upkeep = VillageUpgradeManager::getTotalUpkeepForVillage($system, $village);
        $village->subtractResource(WarManager::RESOURCE_MATERIALS, $upkeep[WarManager::RESOURCE_MATERIALS]);
        $village->subtractResource(WarManager::RESOURCE_FOOD, $upkeep[WarManager::RESOURCE_FOOD]);
        $village->subtractResource(WarManager::RESOURCE_WEALTH, $upkeep[WarManager::RESOURCE_WEALTH]);
        $queries[] = "INSERT INTO `resource_logs`
            (`village_id`, `resource_id`, `type`, `quantity`, `time`)
            VALUES ({$village->village_id}, " . WarManager::RESOURCE_MATERIALS . ", " . VillageManager::RESOURCE_LOG_UPGRADE_UPKEEP . ", " . $upkeep[WarManager::RESOURCE_MATERIALS] . ", " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
            (`village_id`, `resource_id`, `type`, `quantity`, `time`)
            VALUES ({$village->village_id}, " . WarManager::RESOURCE_FOOD . ", " . VillageManager::RESOURCE_LOG_UPGRADE_UPKEEP . ", " . $upkeep[WarManager::RESOURCE_FOOD] . ", " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
            (`village_id`, `resource_id`, `type`, `quantity`, `time`)
            VALUES ({$village->village_id}, " . WarManager::RESOURCE_WEALTH . ", " . VillageManager::RESOURCE_LOG_UPGRADE_UPKEEP . ", " . $upkeep[WarManager::RESOURCE_WEALTH] . ", " . time() . ")";
    }
    // apply resource decay
    $decay_rate = (VillageManager::EXCESS_RESOURCE_DECAY_PER_DAY / 24) / 100;
    foreach ($villages as $village) {
        $total_resources = 0;
        // calculate total resources
        foreach ($village->resources as $resource_id => $resource_count) {
            $total_resources += $resource_count;
        }
        // calculate excess resources and apply decay propotionally to each resource
        $excess_resources = $total_resources - $village->resource_capacity;
        if ($excess_resources > 0) {
            foreach ($village->resources as $resource_id => $resource_count) {
                // calculate proportion of total resources
                $proportion = $resource_count / $total_resources;
                // apply decay based on proportion
                $decay = ceil($excess_resources * $decay_rate * $proportion);
                // subtract resource decay
                $village->subtractResource($resource_id, $decay);
                // log decay
                $queries[] = "INSERT INTO `resource_logs`
                    (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                    VALUES ({$village->village_id}, {$resource_id}, " . VillageManager::RESOURCE_LOG_DECAY . ", " . $decay . ", " . time() . ")";
            }
        }
    }
    // trigger victory conditions
    $war_type = VillageRelation::RELATION_WAR;
    $query = "SELECT * FROM `village_relations` WHERE `relation_type` = {$war_type} AND `relation_end` IS NULL";
    $wars = $system->db->query($query);
    $wars = $system->db->fetch_all($wars);
    foreach ($wars as $war_data) {
        $war = new VillageRelation($war_data);
        $queries = array_merge($queries, WarManager::getHandleVictoryQueries($system, $war));
    }
    // apply base production
    foreach ($village_result as $village) {
        $villages[$village['village_id']] = new Village($system, village_row: $village);
        $materials_production = $villages[$village['village_id']]->policy->materials_production + $villages[$village['village_id']]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_MATERIALS_PRODUCTION];
        $food_production = $villages[$village['village_id']]->policy->food_production + $villages[$village['village_id']]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_FOOD_PRODUCTION];
        $wealth_production = $villages[$village['village_id']]->policy->wealth_production + $villages[$village['village_id']]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_WEALTH_PRODUCTION];
        $villages[$village['village_id']]->addResource(1, $materials_production);
        $villages[$village['village_id']]->addResource(2, $food_production);
        $villages[$village['village_id']]->addResource(3, $wealth_production);
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 1, " . VillageManager::RESOURCE_LOG_PRODUCTION . ", {$materials_production}, " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 1, " . VillageManager::RESOURCE_LOG_COLLECTION . ", {$materials_production}, " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 2, " . VillageManager::RESOURCE_LOG_PRODUCTION . ", {$food_production}, " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 2, " . VillageManager::RESOURCE_LOG_COLLECTION . ", {$food_production}, " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 3, " . VillageManager::RESOURCE_LOG_PRODUCTION . ", {$wealth_production}, " . time() . ")";
        $queries[] = "INSERT INTO `resource_logs`
                        (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                        VALUES ({$village['village_id']}, 3, " . VillageManager::RESOURCE_LOG_COLLECTION . ", {$wealth_production}, " . time() . ")";
    }
    foreach ($region_result as $region) {
        // get region locations for region
        $region_location_result = $system->db->query("SELECT * FROM `region_locations` WHERE `region_id` = {$region['region_id']}");
        $region_location_result = $system->db->fetch_all($region_location_result);

        /* update stability */
        $castle_stability = 0;
        $castle_occupying_village_id = 0;
        foreach ($region_location_result as &$region_location) {
            if ($region_location['type'] === 'castle') {
                $castle_occupying_village_id = $region_location['occupying_village_id'];
                $stability_baseline = WarManager::BASE_CASTLE_STABILITY + $villages[$region_location['occupying_village_id']]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_BASE_STABILITY] + $villages[$region_location['occupying_village_id']]->policy->base_stability;
                $stability_gap = abs($stability_baseline - $region_location['stability']);
                $stability_shift = min(WarManager::BASE_STABILITY_SHIFT_PER_HOUR + floor($stability_gap / WarManager::STABILITY_DEFENSE_SHIFT_INCREMENT), WarManager::MAX_STABILITY_DEFENSE_SHIFT);
                if ($region_location['stability'] < $stability_baseline) {
                    $region_location['stability'] += $stability_shift;
                    $region_location['stability'] = min($region_location['stability'], $stability_baseline);
                } else if ($region_location['stability'] > $stability_baseline) {
                    $region_location['stability'] -= $stability_shift;
                    $region_location['stability'] = max($region_location['stability'], $stability_baseline);
                }
                $castle_stability = $region_location['stability'];
            }
            unset($region_location);
        }
        foreach ($region_location_result as &$region_location) {
            if ($region_location['type'] === 'village') {
                $stability_baseline = WarManager::BASE_TOWN_STABILITY + $villages[$region_location['occupying_village_id']]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_BASE_STABILITY] + $villages[$region_location['occupying_village_id']]->policy->base_stability;
                // if home region
                if ($region_location['region_id'] < 5) {
                    $stability_baseline += WarManager::HOME_REGION_STABILITY_BONUS;
                }
                // if castle owned
                if ($castle_occupying_village_id == $region_location['occupying_village_id']) {
                    $stability_baseline += $castle_stability;
                }
                // if non-native region
                if ($region_location['occupying_village_id'] != WarManager::REGION_ORIGINAL_VILLAGE[$region['region_id']]) {
                    $stability_baseline -= WarManager::OCCUPIED_TOWN_STABILITY_PENALTY;
                    $stability_baseline -= $villages[WarManager::REGION_ORIGINAL_VILLAGE[$region['region_id']]]->active_upgrade_effects[VillageUpgradeConfig::UPGRADE_EFFECT_OCCUPIED_BASE_STABILITY_REDUCTION];
                }
                $stability_gap = abs($stability_baseline - $region_location['stability']);
                $stability_shift = min(WarManager::BASE_STABILITY_SHIFT_PER_HOUR + floor($stability_gap / WarManager::STABILITY_DEFENSE_SHIFT_INCREMENT), WarManager::MAX_STABILITY_DEFENSE_SHIFT);
                if ($region_location['stability'] < $stability_baseline) {
                    $region_location['stability'] += $stability_shift;
                    $region_location['stability'] = min($region_location['stability'], $stability_baseline);
                } else if ($region_location['stability'] > $stability_baseline) {
                    $region_location['stability'] -= $stability_shift;
                    $region_location['stability'] = max($region_location['stability'], $stability_baseline);
                }
            }
            unset($region_location);
        }

        /* update resource count */
        foreach ($region_location_result as &$region_location) {
            switch ($region_location['type']) {
                case 'castle':
                    $production = WarManager::BASE_CASTLE_RESOURCE_PRODUCTION * max((1 + ($region_location['stability'] / 100)), 0);
                    break;
                case 'village';
                    $production = WarManager::BASE_TOWN_RESOURCE_PRODUCTION * max((1 + ($region_location['stability'] / 100)), 0);
                    break;
                default;
                    break;
            }
            // if one of the home regions or occupying village does not control region, collect resources bypassing caravans
            if ($region['region_id'] <= 5 || $region['village'] != $region_location['occupying_village_id']) {
                $villages[$region['village']]->addResource($region_location['resource_id'], $region_location['resource_count']);
                $queries[] = "INSERT INTO `resource_logs`
                    (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                    VALUES ({$region_location['occupying_village_id']}, {$region_location['resource_id']}, " . VillageManager::RESOURCE_LOG_COLLECTION . ", {$region_location['resource_count']}, " . time() . ")";
                $region_location['resource_count'] = 0;
            }
            $region_location['resource_count'] += $production;
            !empty($village_resource_production[$region['village']][$region_location['resource_id']]) ? $village_resource_production[$region['village']][$region_location['resource_id']] += $production : $village_resource_production[$region['village']][$region_location['resource_id']] = $production;
            unset($region_location);
        }

        /* update defense */
        foreach ($region_location_result as &$region_location) {
            $defense_gap = abs($region_location['stability'] - $region_location['defense']);
            $defense_shift = min(WarManager::BASE_DEFENSE_SHIFT_PER_HOUR + floor($defense_gap / WarManager::STABILITY_DEFENSE_SHIFT_INCREMENT), WarManager::MAX_STABILITY_DEFENSE_SHIFT);
            switch ($region_location['type']) {
                case 'castle':
                    if ($region_location['defense'] > $region_location['stability']) {
                        $region_location['defense'] -= $defense_shift;
                        if ($region_location['defense'] < $region_location['stability']) {
                            $region_location['defense'] = $region_location['stability'];
                        }
                        $region_location['defense'] = max($region_location['defense'], 0);
                    }
                    else if ($region_location['defense'] < $region_location['stability']) {
                        $region_location['defense'] += $defense_shift;
                        if ($region_location['defense'] > $region_location['stability']) {
                            $region_location['defense'] = $region_location['stability'];
                        }
                        $region_location['defense'] = min($region_location['defense'], 100);
                    }
                    break;
                case 'village';
                    if ($region_location['defense'] > $region_location['stability']) {
                        $region_location['defense'] -= $defense_shift;
                        if ($region_location['defense'] < $region_location['stability']) {
                            $region_location['defense'] = $region_location['stability'];
                        }
                        $region_location['defense'] = max($region_location['defense'], 0);
                    } else if ($region_location['defense'] < $region_location['stability']) {
                        $region_location['defense'] += $defense_shift;
                        if ($region_location['defense'] > $region_location['stability']) {
                            $region_location['defense'] = $region_location['stability'];
                        }
                        $region_location['defense'] = min($region_location['defense'], 100);
                    }
                    break;
                default;
                    break;
            }
            unset($region_location);
        }

        /* update region_locations */
        foreach ($region_location_result as $region_location) {
            $queries[] = "UPDATE `region_locations`
                SET `resource_count` = {$region_location['resource_count']},
                `health` = {$region_location['health']},
                `defense` = {$region_location['defense']},
                `stability` = {$region_location['stability']}
                WHERE `region_location_id` = {$region_location['region_location_id']}";
        }
    }

    /* update villages */
    foreach ($villages as $village) {
        /* log resource production */
        if (!empty($village_resource_production[$village->village_id])) {
            /* log production */
            foreach ($village_resource_production[$village->village_id] as $key => $value) {
                $queries[] = "INSERT INTO `resource_logs`
                    (`village_id`, `resource_id`, `type`, `quantity`, `time`)
                    VALUES ({$village->village_id}, {$key}, " . VillageManager::RESOURCE_LOG_PRODUCTION . ", " . $value . ", " . time() . ")";
            }
        }
        /* generate village update queries */
        $queries[] = $village->updateResources(false);
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
            $system->db->query("LOCK TABLES `region_locations` WRITE, `villages` WRITE, `resource_logs` WRITE, `village_buildings` WRITE, `village_upgrades` WRITE, `village_relations` WRITE;");
            $system->db->query($query);
            $system->db->query("UNLOCK TABLES;");
        }
        echo "Script complete";
    }
}