<?php

use BGAWorkbench\Test\Notification;

class feException extends Exception
{

}

class BgaSystemException extends feException
{

}

class BgaUserException extends feException
{

}

class APP_GameClass extends APP_DbObject
{

}

class Gamestate
{
    /**
     * @var Table
     */
    private $table;

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function setAllPlayersMultiactive()
    {
    }

    public function setPlayerNonMultiactive($player_id, $next_state)
    {
        return false;
    }

    public function nextState($action = '')
    {
        $this->table::DbQuery("UPDATE bga_workbench SET `value` = '{$action}' WHERE `key` = 'state_machine' AND `subkey` = 'next_transition'");
    }

    public function state(): Array
    {
        return $this->table->getCurrentState();
    }

    public function changeActivePlayer($player_id)
    {
        $this->table->stubActivePlayerId($player_id);
    }
}

abstract class Table extends APP_GameClass
{
    /**
     * @var Gamestate
     */
    public $gamestate;

    public function __construct()
    {
        $this->gamestate = new Gamestate($this);
    }

    abstract protected function setupNewGame($players, $options = array());

    public function _(string $text): string
    {
        return $text;
    }

    public function trace(string $text): void
    {
    }

    public function getBgaEnvironment(): string {
        return 'studio';
    }

    public function reattributeColorsBasedOnPreferences($players, $colors)
    {
    }

    public function reloadPlayersBasicInfos()
    {
    }

    protected function activeNextPlayer()
    {
        $currentPlayerId = self::getActivePlayerId();
        $players = self::getObjectListFromDB("SELECT player_id, player_no FROM player WHERE player_eliminated = 0 ORDER BY player_no");
        $players = array_merge($players, $players);
        $foundCurrentPlayer = false;
        foreach ($players as $player) {
            if ($foundCurrentPlayer) {
                self::stubActivePlayerId($player['player_id']);
                return;
            }
            if ($player['player_id'] == $currentPlayerId) {
                $foundCurrentPlayer = true;
            }
        }
    }

    public function checkAction($actionName, $bThrowException = true)
    {
        $state = $this->gamestate->state();
        $possibleActions = $state['possibleactions'] ?? [];
        return in_array($actionName, $possibleActions);
    }

    public $nonPersistentGameStateLabelsToIds = [];

    public function initGameStateLabels($labels)
    {
        foreach ($labels as $label => $id) {
            if (!is_string($label)) {
                throw new InvalidArgumentException('All labels must be a string');
            }
            // Try to store in the DB first, but if this is being called from the constructor, then we can't persist it yet.
            try {
                self::DbQuery("INSERT INTO bga_workbench (`key`, `subkey`, `value`) VALUES ('game_state_labels', '{$label}', {$id})");
            } catch (Exception $e) {
                $this->nonPersistentGameStateLabelsToIds[$label] = $id;
            }
        }
    }

    /** Persist the game state labels to the database (for testing purposes only) */
    public function persistGameStateLabels()
    {
        foreach ($this->nonPersistentGameStateLabelsToIds as $label => $id) {
            self::DbQuery("INSERT INTO bga_workbench (`key`, `subkey`, `value`) VALUES ('game_state_labels', '{$label}', '{$id}')");
        }
        $this->nonPersistentGameStateLabelsToIds = [];
    }

    public function setGameStateInitialValue($label, $value)
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('The value must be numeric');
        }
        $id = self::getGameStateId($label);
        self::DbQuery("INSERT INTO global (global_id, global_value) VALUES ({$id}, {$value})");
    }

    public function setGameStateValue($label, $value)
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('The value must be numeric');
        }

        $id = self::getGameStateId($label);

        if (self::getUniqueValueFromDB("SELECT COUNT(*) FROM global WHERE global_id = {$id}") === 0) {
            throw new Exception("The game state value {$label} has not been initialized");
        }

        self::DbQuery("UPDATE global SET global_value = {$value} WHERE global_id = {$id}");
    }

    public function incGameStateValue($label, $delta)
    {
        if (!is_numeric($delta)) {
            throw new InvalidArgumentException('The delta must be numeric');
        }
        self::setGameStateValue($label, self::getGameStateValue($label) + $delta);
    }

    public function getGameStateValue($label)
    {
        $id = self::getGameStateId($label);

        if (self::getUniqueValueFromDB("SELECT COUNT(*) FROM global WHERE global_id = {$id}") == 0) {
            throw new Exception("The game state value {$label} (id={$id}) has not been initialized");
        }

        return self::getUniqueValueFromDB("SELECT global_value FROM global WHERE global_id = {$id}");
    }

    private function getGameStateId($label) {
        if (array_key_exists($label, $this->nonPersistentGameStateLabelsToIds)) {
            return $this->nonPersistentGameStateLabelsToIds[$label];
        }
        if (self::getUniqueValueFromDB("SELECT COUNT(*) FROM bga_workbench WHERE `key` = 'game_state_labels' AND `subkey` = '{$label}'") == 0) {
            throw new InvalidArgumentException(sprintf('The label %s was not defined by initGameStateLabels', $label));
        }
        return self::getUniqueValueFromDB("SELECT `value` FROM bga_workbench WHERE `key` = 'game_state_labels' AND `subkey` = '{$label}'");
    }

    private function getStatId($targetName)
    {
        include('stats.inc.php');
        foreach ($stats_type as $type => $stats) {
            foreach ($stats as $name => $stat) {
                if ($name === $targetName) {
                    return $stat['id'];
                }
            }
        }
        throw new Exception('State not found: ' . $targetName);
    }

    public function initStat($table_or_player, $name, $value, $player_id = null)
    {
        if ($value === true) {
            $value = 1;
        } elseif ($value === false) {
            $value = 0;
        }

        $stat_id = $this->getStatId($name);
        $sql = 'INSERT INTO stats (stats_id, stats_type, stats_player_id, stats_value) VALUES ';

        switch ($table_or_player) {
            case 'table':
                $sql .= sprintf('(%d, "table", NULL, %s)', $stat_id, $value);
                break;
            case 'player':
                $players = self::loadPlayersBasicInfos();
                if ($player_id === null) {
                    $values = [];
                    foreach (array_keys($players) as $id) {
                        $values[] = "('" . $stat_id . "','player','$id','" . $value . "')";
                    }
                    $sql .= implode(', ', $values);
                } else {
                    $values[] = "('" . $stat_id . "','player','$player_id','" . $value . "')";
                }
                break;
            default:
                throw new InvalidArgumentException(sprintf('Wrong table_or_player type: %s', $table_or_player));
        }

        self::DbQuery($sql);
    }

    public function incStat($delta, $name, $player_id = null)
    {
        $statId = $this->getStatId($name);
        if ($player_id === null) {
            self::DbQuery("UPDATE stats SET stats_value = stats_value + {$delta} WHERE stats_type = 'table' AND stats_id = {$statId}");
        } else {
            self::DbQuery("UPDATE stats SET stats_value = stats_value + {$delta} WHERE stats_type = 'player' AND stats_id = {$statId} AND stats_player_id = {$player_id}");
        }
    }

    public function setStat($value, $name, $player_id = null)
    {
        $statId = $this->getStatId($name);
        if ($player_id === null) {
            self::DbQuery("UPDATE stats SET stats_value = {$value} WHERE stats_type = 'table' AND stats_id = {$statId}");
        } else {
            self::DbQuery("UPDATE stats SET stats_value = {$value} WHERE stats_type = 'player' AND stats_id = {$statId} AND stats_player_id = {$player_id}");
        }
    }

    public function getStat($name, $player_id = null)
    {
        $statId = $this->getStatId($name);
        if ($player_id === null) {
            return self::getUniqueValueFromDB("SELECT stats_value FROM stats WHERE stats_type = 'table' AND stats_id = ${statId}");
        }
        return self::getUniqueValueFromDB("SELECT stats_value FROM stats WHERE stats_type = 'player' AND stats_id = ${statId} AND stats_player_id = {$player_id}");
    }

    /**
     * @param int $player_id
     * @param int $specific_time
     */
    public function giveExtraTime($player_id, $specific_time = null) {}

    /**
     * @return string
     */
    public function getActivePlayerName()
    {
        $players = self::loadPlayersBasicInfos();
        return $players[$this->getActivePlayerId()]['player_name'];
    }

    ////////////////////////////////////////////////////////////////////////
    // Testing methods
    /**
     * @var array[]
     */
    private $notifications = [];

    /**
     * @return array[]
     */
    public function getNotifications()
    {
        return $this->notifications;
    }

    public function resetNotifications()
    {
        $this->notifications = [];
    }

    /**
     * @param string $notification_type
     * @param string $notification_log
     * @param array $notification_args
     */
    public function notifyAllPlayers($notification_type, $notification_log, $notification_args)
    {
        $this->notifyPlayer('all', $notification_type, $notification_log, $notification_args);
    }

    /**
     * @param int $player_id
     * @param string $notification_type
     * @param string $notification_log
     * @param array $notification_args
     */
    public function notifyPlayer($player_id, $notification_type, $notification_log, $notification_args)
    {
        if ($notification_log === null) {
            throw new \InvalidArgumentException('Use empty string for notification_log instead of null');
        }
        $this->notifications[] = [
            'playerId' => $player_id,
            'type' => $notification_type,
            'log' => $notification_log,
            'args' => $notification_args
        ];
    }

    /**
     * @var int
     */
    private $currentPlayerId;

    /**
     * @return int
     */
    protected function getCurrentPlayerId()
    {
        if ($this->currentPlayerId === null) {
            throw new \RuntimeException('Not a player bounded instance');
        }
        return $this->currentPlayerId;
    }

    /**
     * @todo get from getCurrentPlayerId table load
     * @return string
     */
    protected function getCurrentPlayerName()
    {
        return null;
    }

    /**
     * @todo get from getCurrentPlayerId table load
     * @return string
     */
    protected function getCurrentPlayerColor()
    {
        return null;
    }

    /**
     * @param int $currentPlayerId
     * @return self
     */
    public function stubCurrentPlayerId($currentPlayerId)
    {
        $this->currentPlayerId = $currentPlayerId;
        return $this;
    }

    /**
     * @return int
     */
    public function getActivePlayerId()
    {
        return self::getUniqueValueFromDB("SELECT `value` FROM bga_workbench WHERE `key` = 'state_machine' AND `subkey` = 'active_player_id'");
    }

    /**
     * @param int $activePlayerId
     * @return self
     */
    public function stubActivePlayerId($activePlayerId)
    {
        self::DbQuery("UPDATE bga_workbench SET `value` = '{$activePlayerId}' WHERE `key` = 'state_machine' AND `subkey` = 'active_player_id'");
        return $this;
    }

    /**
     * @var array|null
     */
    private static $stubbedGameInfos = null;

    /**
     * @param array $gameInfos
     */
    public static function stubGameInfos(array $gameInfos)
    {
        self::$stubbedGameInfos = $gameInfos;
    }

    /**
     * @param string $name
     * @return array
     */
    public static function getGameInfosForGame($name)
    {
        return self::$stubbedGameInfos;
    }

    /**
     * @var array|null
     */
    private static $statesById = null;

    /**
     * @var array|null
     */
    private static $statesLabelToId = null;

    /**
     * @param array $states
     */
    public static function stubStates(array $states)
    {
        self::$statesById = $states;
        self::$statesLabelToId = array_combine(
            array_map(
                function ($stateId, $state) {
                    return $state['name'];
                },
                array_keys($states),
                $states
            ),
            array_keys($states)
        );
    }

    /**
     * @param string $label
     * @return array
     */
    public static function getStateForLabel(string $label)
    {
        if (!isset(self::$statesLabelToId[$label])) {
            throw new Exception("State not found: ". $label. ". Valid states: ". implode(', ', array_keys(self::$statesLabelToId)). ".");
        }

        return self::$statesById[self::$statesLabelToId[$label]];
    }

    /**
     * @param int $id
     * @return array
     */
    public static function getStateForId(string $id)
    {
        if (!isset(self::$statesById[$id])) {
            throw new Exception("State not found: ". $id. ". Valid state IDs: ". implode(', ', array_values(self::$statesLabelToId)). ".");
        }
        return self::$statesById[$id];
    }

    /** Return the name of the upcoming transition (for testing-purposes only). */
    public function getTransitionName(): string
    {
        return self::getUniqueValueFromDB("SELECT `value` FROM bga_workbench WHERE `key` = 'state_machine' AND `subkey` = 'next_transition'");
    }

    /** Set the ID of the current state and reset the next transition (for testing-purposes only). */
    public function setStateId($id)
    {
        self::DbQuery("UPDATE bga_workbench SET `value` = '{$id}' WHERE `key` = 'state_machine' AND `subkey` = 'current_state_id'");
        self::DbQuery("UPDATE bga_workbench SET `value` = '' WHERE `key` = 'state_machine' AND `subkey` = 'next_transition'");
    }

    /** Get the current state (for testing-purposes only). */
    public function getCurrentState()
    {
        $stateId = (int) self::getUniqueValueFromDB("SELECT `value` FROM bga_workbench WHERE `key` = 'state_machine' AND `subkey` = 'current_state_id'");
        return self::getStateForId($stateId);
    }

    /**
     * @return array
     */
    public function loadPlayersBasicInfos()
    {
        $players = self::getObjectListFromDB('SELECT * FROM player');
        $playerIds = array_map(
            function (array $player) {
                return (int) $player['player_id'];
            },
            $players
        );
        return array_combine($playerIds, $players);
    }
}
