<?php

namespace Khinenw\ZombieGame;


use Khinenw\ZombieGame\event\game\GameFinishEvent;
use Khinenw\ZombieGame\event\game\GameRoundFinishEvent;
use Khinenw\ZombieGame\event\game\GameRoundStartEvent;
use Khinenw\ZombieGame\event\game\GameStartEvent;
use Khinenw\ZombieGame\event\game\ZombieInfectEvent;

class GameManager {
	private $innerTick = 0;
	private $roundTick = 0;
	private $playerData = array();
	private $gameId = "";
	private $gameStatus = GameManager::STATUS_PREPARATION;
	private $roundCount = 1;
	private $plugin;

	const HUMAN = 0;
	const ZOMBIE = 1;

	//Setting
	public static $INITIAL_ZOMBIE_COUNT = 2;
	public static $INFECTION_MEDICINE_TERM = 2400;
	public static $PREPARATION_TERM = 1200;
	public static $INGAME_REST_TERM = 600;
	public static $ROUND_TERM = 1800;

	const STATUS_PREPARATION = 0;
	const STATUS_INGAME = 1;
	const STATUS_INGAME_REST = 2;

	const RETURNTYPE_MEDICINE_NOT_ZOMBIE_SUCCEED = 0;
	const RETURNTYPE_MEDICINE_OVER_TIME_SUCCEED = 1;
	const RETURNTYPE_MEDICINE_SUCCEED = 2;


	const RETURNTYPE_TOUCH_IN_PREPARATION_OR_REST_FAILED = 0;
	const RETURNTYPE_TOUCH_SUCCEED = 1;
	const RETURNTYPE_TOUCH_ALREADY_TOUCED_FAILED = 2;

	private function newGame(){
		$this->plugin->getServer()->getPluginManager()->callEvent(new GameStartEvent($this->plugin, $this->gameId));

		$initialZombieNames = array_rand($this->playerData, GameManager::$INITIAL_ZOMBIE_COUNT);

		foreach($initialZombieNames as $zombie){
			$this->infectZombie($zombie, true, false);
		}
		$this->innerTick = 0;
		$this->roundTick = 0;
		$this->gameStatus = GameManager::STATUS_PREPARATION;
		$this->roundCount = 1;
	}

	public function startGame(array $playerList, $gameId, GameGenius &$plugin){
		$this->gameId = $gameId;

		foreach($playerList as $player){
			$this->playerData[$player->getName()] = array(
				"type" => GameManager::HUMAN,
				"player" => $player,
				"score" => 0,
				"touched" => array()
			);
		}

		$this->plugin = $plugin;
		$this->newGame();
	}

	public function touch($touchingPlayerName, $touchedPlayerName){
		if($this->gameStatus !== GameManager::STATUS_INGAME){
			return GameManager::RETURNTYPE_TOUCH_IN_PREPARATION_OR_REST_FAILED;
		}

		foreach($this->playerData[$touchedPlayerName]["touched"] as $pastToucher){
			if($pastToucher === $touchingPlayerName){
				return GameManager::RETURNTYPE_TOUCH_ALREADY_TOUCED_FAILED;
			}
		}

		switch($this->playerData[$touchingPlayerName]["type"]){
			case GameManager::HUMAN:
				if($this->isHuman($touchedPlayerName)){
					$this->playerData[$touchedPlayerName["score"]]++;
					$this->playerData[$touchingPlayerName["score"]]++;
				}else{
					$this->infectZombie($touchingPlayerName, true, false);
				}
				break;
			case GameManager::ZOMBIE:
				if($this->isHuman($touchedPlayerName)){
					$this->infectZombie($touchingPlayerName, true, false);
				}
		}
		array_push($this->playerData[$touchedPlayerName]["touched"], $touchingPlayerName);
		array_push($this->playerData[$touchingPlayerName]["touched"], $toucherPlayerName);

		return GameManager::RETURNTYPE_TOUCH_SUCCEED;
	}

	public function disconnectedFromServer($disconnectedPlayerName){
		$is_initial_zombie = false;

		if(isset($this->playerData[$disconnectedPlayerName]["initial_zombie"])){
			$is_initial_zombie = $this->playerData[$disconnectedPlayerName]["initial_zombie"];
		}

		unset($this->playerData[$disconnectedPlayerName]);

		if($is_initial_zombie){
			$zombie = array_rand($this->playerData);
			$this->infectZombie($zombie, true, false);
		}
	}

	public function useMedicine($usingPlayerName){
		if($this->isHuman($usingPlayerName)){
			return GameManager::RETURNTYPE_MEDICINE_NOT_ZOMBIE_SUCCEED;
		}elseif($this->playerData[$usingPlayerName]["infection_time"] + GameManager::$INFECTION_MEDICINE_TERM < $this->innerTick){
			return GameManager::RETURNTYPE_MEDICINE_OVER_TIME_SUCCEED;
		}else{
			$this->playerData[$usingPlayerName]["type"] = GameManager::HUMAN;
			unset($this->playerData[$usingPlayerName]["infection_time"]);
			unset($this->playerData[$usingPlayerName]["initial_zombie"]);
			return GameManager::RETURNTYPE_MEDICINE_SUCCEED;
		}

	}

	public function tick(){
		$this->innerTick++;
		$this->roundTick++;
		switch($this->gameStatus){
			case GameManager::STATUS_PREPARATION:
				if($this->roundTick >= GameManager::$PREPARATION_TERM){
					$this->gameStatus = GameManager::STATUS_INGAME;
					$this->plugin->getServer()->getPluginManager()->callEvent(new GameRoundStartEvent($this->plugin, $this->gameId));
					$this->roundTick = 0;
				}
				break;
			case GameManager::STATUS_INGAME_REST:
				if($this->roundTick >= GameManager::$INGAME_REST_TERM){
					$this->gameStatus = GameManager::STATUS_INGAME;
					$this->plugin->getServer()->getPluginManager()->callEvent(new GameRoundStartEvent($this->plugin, $this->gameId));
					$this->roundTick = 0;
				}
				break;
			case GameManager::STATUS_INGAME:
				if($this->roundTick >= GameManager::$ROUND_TERM){
					$this->roundCount++;
					$zombieCount = 0;
					$humanCount = 0;

					foreach($this->playerData as $playerData){
						if($playerData["type"] === GameManager::HUMAN){
							$humanCount++;
						}else{
							$zombieCount++;
						}
					}

					$this->plugin->getServer()->getPluginManager()->callEvent(new GameRoundFinishEvent($this->plugin, $this->gameId, $zombieCount, $humanCount));

					if($zombieCount >= 10){
						$this->finishGame();
					}

					if($this->roundCount > 5){
						$this->finishGame();
					}
					foreach ($this->playerData as $name=>$data) {
						if(count($data["touched"]) <= 0){
							$this->infectZombie($name, false, true);
						}
					}

					$this->gameStatus = GameManager::STATUS_INGAME_REST;
					$this->roundTick = 0;
				}
				break;
		}
	}

	private function finishGame(){
		$isEverybodyZombie = false;
		foreach($this->playerData as $data){
			if($data["type"] === GameManager::ZOMBIE){
				$isEverybodyZombie = true;
			}
		}

		if($isEverybodyZombie){
			$winnerName = array();

			foreach($this->playerData as $playerName => $playerData){
				if(isset($playerData["initial_zombie"]) && $playerData["initial_zombie"] === true){
					array_push($winnerName, $playerName);
				}
			}

			$this->plugin->getServer()->getPluginManager()->callEvent(new GameFinishEvent($this->plugin, $this->gameId, $winnerName));
		}else{
			$winnerName = array();

			$highestScore = 0;
			foreach($this->playerData as $playerName => $playerData){
				if($playerData["type"] === GameManager::HUMAN && $highestScore < $playerData["score"]){
					$highestScore = $playerData["score"];
				}
			}

			foreach($this->playerData as $playerName => $playerData){
				if($playerData["type"] === GameManager::HUMAN && $highestScore === $playerData["score"]){
					array_push($winnerName, $playerName);
				}
			}

			$this->plugin->getServer()->getPluginManager()->callEvent(new GameFinishEvent($this->plugin, $this->gameId, $winnerName));
		}

	}

	private function infectZombie($playerName, $isInitialZombie, $isNoTouchInfection){
		$this->playerData[$playerName]["type"] = GameManager::ZOMBIE;
		$this->playerData[$playerName]["infection_time"] = $this->innerTick;
		$this->playerData[$playerName]["initial_zombie"] = $isInitialZombie;
		$this->plugin->getServer()->getPluginManager()->callEvent(new ZombieInfectEvent($this->plugin, $isInitialZombie, $isNoTouchInfection, $playerName));
	}

	public function isHuman($playerName){
		if($this->playerData[$playerName]["type"] === GameManager::ZOMBIE){
			return false;
		}
		return true;
	}
}